<?php

declare(strict_types=1);

namespace Example\ApiSutra;

use ApiSutra\Laravel\Observability\Events\ExecutionCompleted;
use Illuminate\Contracts\Cache\Repository;

/** Слушатель приложения: метрики пишутся на безопасной границе доставки события. */
final readonly class SdkMetricsListener
{
    public function __construct(private Repository $cache)
    {
    }

    public function handle(ExecutionCompleted $event): void
    {
        $this->cache->increment('sdk.completed.' . $event->snapshot->status());
        $this->cache->increment('sdk.duration_ms', (int) $event->snapshot->durationMs());
    }
}
