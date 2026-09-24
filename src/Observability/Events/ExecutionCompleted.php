<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Observability\Events;

use ApiSutra\Diagnostics\ExecutionSnapshot;

/** Итог уже завершённого исполнения; событие не управляет retry/release. */
final readonly class ExecutionCompleted
{
    /** @param array<string, scalar|null> $correlation */
    public function __construct(public ExecutionSnapshot $snapshot, public array $correlation = [])
    {
    }
}
