<?php

namespace pocketmine\scheduler;

use pmmp\thread\Runnable;
use pocketmine\Server;
use function in_array;
use function is_scalar;
use function serialize;
use function unserialize;

abstract class AsyncTask extends Runnable
{

    /**
     * @var AsyncWorker|null
     */
    public $worker = null;

    protected $result = null;
    protected $serialized = false;
    protected $cancelRun = false;

    /**
     * @var int|null
     */
    protected $taskId = null;

    protected $crashed = false;
    protected $isGarbage = false;
    protected $isFinished = false;


    public function isGarbage(): bool
    {
        return $this->isGarbage;
    }

    public function setGarbage()
    {
        $this->isGarbage = true;
    }

    public function isFinished(): bool
    {
        return $this->isFinished;
    }


    public function run(): void
    {
        $this->result = null;
        $this->isGarbage = false;

        if (!$this->cancelRun) {
            try {
                $this->onRun();
            } catch (\Throwable $e) {
                $this->crashed = true;

                if ($this->worker !== null && method_exists($this->worker, "handleException")) {
                    $this->worker->handleException($e);
                } else {
                    echo "[AsyncTask] " . $e->getMessage() . PHP_EOL;
                }
            }
        }

        $this->isFinished = true;

        $this->worker->getNotifier()->wakeupSleeper();
    }


    public function isCrashed(): bool
    {
        return $this->crashed;
    }


    public function getResult()
    {
        return $this->serialized ? unserialize($this->result) : $this->result;
    }


    public function cancelRun()
    {
        $this->cancelRun = true;
    }


    public function hasCancelledRun(): bool
    {
        return $this->cancelRun === true;
    }


    public function hasResult(): bool
    {
        return $this->result !== null;
    }


    public function setResult($result, bool $serialize = true)
    {

        if (!$serialize) {
            $this->result = $result;
            $this->serialized = false;
            return;
        }


        if (is_scalar($result) || $result === null) {
            $this->result = $result;
            $this->serialized = false;
            return;
        }


        $this->result = serialize($result);
        $this->serialized = true;
    }


    public function setTaskId($taskId)
    {
        $this->taskId = $taskId;
    }


    public function getTaskId()
    {
        return $this->taskId;
    }


    public function getFromThreadStore($identifier)
    {

        global $store;

        if ($this->isGarbage()) {
            return null;
        }

        return $store[$identifier] ?? null;
    }


    public function saveToThreadStore($identifier, $value)
    {

        global $store;

        if (!$this->isGarbage()) {
            $store[$identifier] = $value;
        }
    }


    /**
     * Ejecutado en el thread secundario.
     */
    abstract public function onRun();


    /**
     * Ejecutado en el hilo principal.
     */
    public function onCompletion(Server $server) {}



    /**
     * Limpieza segura para PHP 8.
     */
    public function cleanObject()
    {

        foreach (get_object_vars($this) as $p => $v) {

            if (
                !($v instanceof \pmmp\thread\ThreadSafe) &&
                !in_array(
                    $p,
                    [
                        "isFinished",
                        "isGarbage",
                        "cancelRun"
                    ],
                    true
                )
            ) {

                try {
                    $this->{$p} = null;
                } catch (\Throwable $e) {
                    // Ignorar propiedades internas
                }
            }
        }
    }
}
