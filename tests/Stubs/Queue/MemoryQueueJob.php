<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs\Queue;

use Illuminate\Queue\Jobs\SyncJob;

/** Настоящий CallQueuedHandler/Worker; подменена только доставка очереди. */
final class MemoryQueueJob extends SyncJob
{
    public int $attempt = 1;
    /** @var list<int> */
    public array $delays = [];

    public function release($delay = 0): void
    {
        $this->delays[] = (int) $delay;
        parent::release($delay);
    }

    public function attempts(): int
    {
        return $this->attempt;
    }
}
