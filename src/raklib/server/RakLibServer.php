<?php



/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

declare(strict_types=1);

namespace raklib\server;



use function array_reverse;
use function class_exists;
use function error_reporting;
use function function_exists;
use function gc_enable;
use function get_class;
use function getcwd;
use function gettype;
use function ini_set;
use function interface_exists;
use function is_object;
use function method_exists;
use function register_shutdown_function;
use function rtrim;
use function set_error_handler;
use function str_replace;
use function strpos;
use function strval;
use function substr;
use function xdebug_get_function_stack;

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use const DIRECTORY_SEPARATOR;
use const E_ALL;
use const E_COMPILE_ERROR;
use const E_COMPILE_WARNING;
use const E_CORE_ERROR;
use const E_CORE_WARNING;
use const E_DEPRECATED;
use const E_ERROR;
use const E_NOTICE;
use const E_PARSE;
use const E_RECOVERABLE_ERROR;
use const E_STRICT;
use const E_USER_DEPRECATED;
use const E_USER_ERROR;
use const E_USER_NOTICE;
use const E_USER_WARNING;
use const E_WARNING;

class RakLibServer extends Thread
{
    protected int $port;
    protected string $interface;
    /** @var \ThreadedLogger */
    protected \ThreadedLogger $logger;
    /** @var \ClassLoader */
    protected \ClassLoader $loader;

    protected ThreadSafeArray $loadPaths;

    protected bool $shutdown;

    /** @var ThreadSafeArray */
    protected ThreadSafeArray $externalQueue;
    /** @var ThreadSafeArray */
    protected ThreadSafeArray $internalQueue;

    protected string $mainPath;

    /**
     * @throws \Exception
     */
    public function __construct(\ThreadedLogger $logger, \ClassLoader $loader, int $port, string $interface = "0.0.0.0")
    {
        $this->port = $port;
        if($port < 1 || $port > 65536){
            throw new \Exception("Invalid port range");
        }

        $this->interface = $interface;
        $this->logger = $logger;
        $this->loader = $loader;
        $loadPaths = [];
        $this->addDependency($loadPaths, new \ReflectionClass($logger));
        $this->addDependency($loadPaths, new \ReflectionClass($loader));
        $this->loadPaths = new ThreadSafeArray();

        foreach(array_reverse($loadPaths, true) as $name => $path){
            $this->loadPaths[$name] = $path;
        }
        $this->shutdown = false;

        $this->externalQueue = new ThreadSafeArray;
        $this->internalQueue = new ThreadSafeArray;

        if(\Phar::running(true) !== ""){
            $this->mainPath = \Phar::running(true);
        }else{
            $this->mainPath = getcwd() . DIRECTORY_SEPARATOR;
        }
        $this->start(Thread::INHERIT_ALL);
    }

    protected function addDependency(array &$loadPaths, \ReflectionClass $dep) : void
    {
        if($dep->getFileName() !== false){
            $loadPaths[$dep->getName()] = $dep->getFileName();
        }

        if($dep->getParentClass() instanceof \ReflectionClass){
            $this->addDependency($loadPaths, $dep->getParentClass());
        }

        foreach($dep->getInterfaces() as $interface){
            $this->addDependency($loadPaths, $interface);
        }
    }

    public function isShutdown() : bool
    {
        return $this->shutdown === true;
    }

    public function shutdown() : void
    {
        $this->shutdown = true;
    }

    public function getPort() : int
    {
        return $this->port;
    }

    public function getInterface() : string
    {
        return $this->interface;
    }

    public function getLogger() : \ThreadedLogger
    {
        return $this->logger;
    }

    public function getExternalQueue() : ThreadSafeArray
    {
        return $this->externalQueue;
    }

    public function getInternalQueue() : ThreadSafeArray
    {
        return $this->internalQueue;
    }

    public function pushMainToThreadPacket(string $str) : void
    {
        $this->internalQueue[] = $str;
    }

    public function readMainToThreadPacket() : ?string
    {
        return $this->internalQueue->shift();
    }

    public function pushThreadToMainPacket(string $str) : void
    {
        $this->externalQueue[] = $str;
    }

    public function readThreadToMainPacket() : ?string
    {
        return $this->externalQueue->shift();
    }

    public function shutdownHandler() : void
    {
        if($this->shutdown !== true){
            $this->getLogger()->emergency("RakLib crashed!");
        }
    }

    public function errorHandler(int $errno, string $errstr, string $errfile, int $errline, ?array $trace = null) : bool
    {
        if(error_reporting() === 0){
            return false;
        }
        $errorConversion = [
            E_ERROR => "E_ERROR",
            E_WARNING => "E_WARNING",
            E_PARSE => "E_PARSE",
            E_NOTICE => "E_NOTICE",
            E_CORE_ERROR => "E_CORE_ERROR",
            E_CORE_WARNING => "E_CORE_WARNING",
            E_COMPILE_ERROR => "E_COMPILE_ERROR",
            E_COMPILE_WARNING => "E_COMPILE_WARNING",
            E_USER_ERROR => "E_USER_ERROR",
            E_USER_WARNING => "E_USER_WARNING",
            E_USER_NOTICE => "E_USER_NOTICE",
            E_STRICT => "E_STRICT",
            E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
            E_DEPRECATED => "E_DEPRECATED",
            E_USER_DEPRECATED => "E_USER_DEPRECATED",
        ];
        $errno = isset($errorConversion[$errno]) ? $errorConversion[$errno] : $errno;
        if(($pos = strpos($errstr, "\n")) !== false){
            $errstr = substr($errstr, 0, $pos);
        }

        $errfile = $this->cleanPath($errfile);

        $this->getLogger()->debug("An $errno error happened: \"$errstr\" in \"$errfile\" at line $errline");

        foreach(($trace = $this->getTrace($trace === null ? 3 : 0, $trace)) as $i => $line){
            $this->getLogger()->debug($line);
        }

        return true;
    }

    public function getTrace(int $start = 1, ?array $trace = null) : array
    {
        if($trace === null){
            if(function_exists("xdebug_get_function_stack")){
                $trace = array_reverse(xdebug_get_function_stack());
            }else{
                $e = new \Exception();
                $trace = $e->getTrace();
            }
        }

        $messages = [];
        $j = 0;
        for($i = (int) $start; isset($trace[$i]); ++$i, ++$j){
            $params = "";
            if(isset($trace[$i]["args"]) || isset($trace[$i]["params"])){
                if(isset($trace[$i]["args"])){
                    $args = $trace[$i]["args"];
                }else{
                    $args = $trace[$i]["params"];
                }
                foreach($args as $name => $value){
                    $params .= (is_object($value) ? get_class($value) . " " . (method_exists($value, "__toString") ? $value->__toString() : "object") : gettype($value) . " " . @strval($value)) . ", ";
                }
            }
            $messages[] = "#$j " . (isset($trace[$i]["file"]) ? $this->cleanPath($trace[$i]["file"]) : "") . "(" . (isset($trace[$i]["line"]) ? $trace[$i]["line"] : "") . "): " . (isset($trace[$i]["class"]) ? $trace[$i]["class"] . (($trace[$i]["type"] === "dynamic" || $trace[$i]["type"] === "->") ? "->" : "::") : "") . $trace[$i]["function"] . "(" . substr($params, 0, -2) . ")";
        }

        return $messages;
    }

    public function cleanPath(string $path) : string
    {
        return rtrim(str_replace(["\\", ".php", "phar://", rtrim(str_replace(["\\", "phar://"], ["/", ""], $this->mainPath), "/")], ["/", "", "", ""], $path), "/");
    }

    public function run() : void
    {
        try{
            //Load removed dependencies, can't use require_once()
            foreach($this->loadPaths as $name => $path){
                if(!class_exists($name, false) && !interface_exists($name, false)){
                    require($path);
                }
            }
            $this->loader->register(true);

            gc_enable();
            error_reporting(-1);
            ini_set("display_errors", '1');
            ini_set("display_startup_errors", '1');

            set_error_handler([$this, "errorHandler"], E_ALL);
            register_shutdown_function([$this, "shutdownHandler"]);

            $sessionManager = new SessionManager($this, new UDPServerSocket($this->getLogger(), $this->port, $this->interface));

            $sessionManager->registerPackets();
            $sessionManager->initialize($this->mainPath);
            $sessionManager->run();
        }catch(\Throwable $e){
            $this->logger->logException($e);
        }
    }
}
