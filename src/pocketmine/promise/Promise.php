<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\promise;

final class Promise{

    /** @var PromiseSharedData */
    private $shared;

    /**
     * @internal Do NOT call this directly; create a new Resolver and call Resolver->promise()
     * @see PromiseResolver
     */
    public function __construct(PromiseSharedData $shared) {
        $this->shared = $shared;
    }

    public function onCompletion(\Closure $onSuccess, \Closure $onFailure) {
        $state = $this->shared->state;
        if($state === true){
            $onSuccess($this->shared->result);
        }elseif($state === false){
            $onFailure();
        }else{
            static $idCounter = 0;
            $id = ++$idCounter;
            $this->shared->onSuccess[$id] = $onSuccess;
            $this->shared->onFailure[$id] = $onFailure;
        }
    }

    public function isResolved() : bool{
        return $this->shared->state === true;
    }

    /**
     * Returns a promise that will resolve only once all the Promises in
     * `$promises` have resolved. The resolution value of the returned promise
     * will be an array containing the resolution values of each Promises in
     * `$promises` indexed by the respective Promises' array keys.
     *
     * @param Promise[] $promises
     */
    public static function all(array $promises) : Promise{
        if(count($promises) === 0){
            throw new \InvalidArgumentException("At least one promise must be provided");
        }

        $resolver = new PromiseResolver();
        $values = [];
        $toResolve = count($promises);
        $continue = true;

        foreach($promises as $key => $promise){
            $promise->onCompletion(
                function($value) use ($resolver, $key, $toResolve, &$values) {
                    $values[$key] = $value;

                    if(count($values) === $toResolve){
                        $resolver->resolve($values);
                    }
                },
                function() use ($resolver, &$continue) {
                    if($continue){
                        $continue = false;
                        $resolver->reject();
                    }
                }
            );

            if(!$continue) break;
        }

        return $resolver->getPromise();
    }

}