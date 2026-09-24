<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Observability;

use ApiSutra\Contracts\Interfaces\Diagnostics\ExecutionObserverInterface;
use ApiSutra\Diagnostics\ExecutionSnapshot;
use ApiSutra\Diagnostics\ExecutionTrace;
use Override;

/** Один экземпляр на исполнение, с корреляцией приложения на его входе. */
final class ExecutionObserver implements ExecutionObserverInterface
{
    private bool $started = false;

    /** @param array<string, scalar|null> $correlation */
    public function __construct(private readonly ObservationArea $area, private readonly array $correlation)
    {
    }

    #[Override]
    public function started(ExecutionTrace $trace): void
    {
        if (!$this->started) {
            $this->started = true;
            $this->area->active++;
        }
    }

    #[Override]
    public function completed(ExecutionSnapshot $snapshot): void
    {
        $this->area->accept($snapshot, $this->correlation);
    }

    #[Override]
    public function released(ExecutionTrace $trace): void
    {
        if ($this->started) {
            $this->started = false;
            $this->area->active--;
        }
    }

    #[Override]
    public function includeAttempts(): bool
    {
        return $this->area->options->details;
    }
}
