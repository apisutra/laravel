<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Console;

use ApiSutra\Contracts\Interfaces\Core\TransportInterface;
use Illuminate\Container\Container;

final readonly class AboutInformation
{
    public function __construct(private Container $app)
    {
    }

    /** @return array<string, string> */
    public function __invoke(): array
    {
        return [
            'Transport binding' => $this->app->bound(TransportInterface::class) ? 'Registered (resolved on demand)' : 'Not registered',
            'Observation' => (string) ($this->app->make('config')->get('apisutra.integration.observation.enabled') === false ? 'Disabled' : 'Automatic / configured'),
            'Testing' => 'Isolated client fake',
        ];
    }
}
