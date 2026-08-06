<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pocketmine\Worker;
use pocketmine\port\driven\Future;
use function gc_enable;
use function ini_set;

final class WorkerThread extends Worker {
    public function __construct(
        private \ThreadSafe $taskQueue,
        private \ThreadSafe $resultQueue,
    ) {}

    public function run(): void {
        $this->registerClassLoader();
        gc_enable();
        ini_set('memory_limit', '-1');

        while (true) {
            $task = $this->taskQueue->shift();
            if ($task === null) {
                continue;
            }
            if ($task instanceof ShutdownTask) {
                break;
            }

            try {
                $result = $task->execute();
                $task->complete($result);
            } catch (\Throwable $e) {
                $task->fail($e);
            }
        }
    }
}