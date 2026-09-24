<?php

declare(strict_types=1);

namespace Integration;

use Illuminate\Contracts\Queue\ShouldQueue;
use Integration\First\Client;
use Integration\First\ItemsRequest;
use RuntimeException;
use Illuminate\Bus\Queueable;

final class ObservationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public bool $fail = false)
    {
    }

    public function handle(Client $client): void
    {
        $client->send(new ItemsRequest());
        if ($this->fail) {
            throw new RuntimeException('Observation job failure');
        }
    }
}
