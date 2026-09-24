<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Observability\Listeners;

use ApiSutra\Laravel\Observability\Events\ExecutionCompleted;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\LogManager;

final readonly class LogExecution
{
    public function __construct(private Repository $config, private LogManager $logs)
    {
    }

    public function handle(ExecutionCompleted $event): void
    {
        $channel = $this->config->get('apisutra.integration.observation.log_channel');
        if (!is_string($channel) || $channel === '') {
            return;
        }
        $this->logs->channel($channel)->log($event->snapshot->status() === 'failed' ? 'error' : 'info', 'ApiSutra execution completed', [
            'sdk' => $event->snapshot->data, 'correlation' => $event->correlation,
        ]);
    }
}
