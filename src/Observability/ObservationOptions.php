<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Observability;

use InvalidArgumentException;

/** Ограничения относятся к области, а не к каждому клиенту. */
final readonly class ObservationOptions
{
    /** @param list<string> $correlationKeys */
    public function __construct(
        public int $maxSnapshots = 256,
        public int $maxBytes = 262144,
        public int $perWindow = 200,
        public int $windowSeconds = 60,
        public bool $details = false,
        public array $correlationKeys = ['request_id', 'trace_id'],
    ) {
        if (min($maxSnapshots, $maxBytes, $perWindow, $windowSeconds) < 1) {
            throw new InvalidArgumentException('Observation limits must be positive.');
        }
    }
}
