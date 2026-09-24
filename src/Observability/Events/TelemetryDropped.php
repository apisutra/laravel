<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Observability\Events;

final readonly class TelemetryDropped
{
    /** @param array<string, int> $outcomes */
    public function __construct(public int $count, public float $firstAt, public float $lastAt, public array $outcomes)
    {
    }
}
