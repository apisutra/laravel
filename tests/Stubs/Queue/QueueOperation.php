<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Application;
use Illuminate\Queue\InteractsWithQueue;

final class QueueOperation implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;

    public int $tries = 3;
    public int $maxExceptions = 1;
    public ?int $until = null;

    /** @param list<object> $handlers */
    public function __construct(private array $handlers = [])
    {
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return $this->handlers;
    }

    public function retryUntil(): ?int
    {
        return $this->until;
    }

    public function handle(Application $app): void
    {
        ($app->make('job.operation'))();
    }
}
