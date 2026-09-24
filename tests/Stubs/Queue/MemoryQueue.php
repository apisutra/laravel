<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs\Queue;

use Illuminate\Queue\SyncQueue;

final class MemoryQueue extends SyncQueue
{
    public function job(object $command): MemoryQueueJob
    {
        return new MemoryQueueJob($this->container, $this->createPayload($command, 'default'), 'memory', 'default');
    }
}
