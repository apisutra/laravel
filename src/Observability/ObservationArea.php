<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Observability;

use ApiSutra\Contracts\Interfaces\Timing\ClockInterface;
use ApiSutra\Diagnostics\ExecutionSnapshot;
use ApiSutra\Laravel\Observability\Events\ExecutionCompleted;
use ApiSutra\Laravel\Observability\Events\TelemetryDropped;
use ApiSutra\Timing\SystemClock;
use Closure;
use Throwable;

/** Ограниченный приёмник; I/O возможен только в flush после освобождения всех задач. */
final class ObservationArea
{
    public int $active = 0;
    public private(set) bool $closed = false;
    /** @var list<ExecutionCompleted> */
    private array $buffer = [];
    private int $bytes = 0;
    private int $windowStarted;
    private int $exported = 0;
    private bool $summarySent = false;
    private int $dropped = 0;
    private float $firstDropped = 0;
    private float $lastDropped = 0;
    /** @var array<string, int> */
    private array $outcomes = [];
    private bool $flushing = false;

    public function __construct(public readonly ObservationOptions $options, private readonly ClockInterface $clock = new SystemClock())
    {
        $this->windowStarted = $clock->monotonicMs();
    }

    /** @param array<string, scalar|null> $correlation */
    public function accept(ExecutionSnapshot $snapshot, array $correlation): void
    {
        if ($this->closed || (!$this->options->details && !$snapshot->isRoot())) {
            return;
        }
        $this->advanceWindow();
        if (
            $this->flushing || count($this->buffer) >= $this->options->maxSnapshots
            || $this->exported + count($this->buffer) >= $this->options->perWindow
        ) {
            $this->drop($snapshot->status());
            return;
        }
        $size = strlen((string) json_encode([$snapshot->data, $correlation], JSON_INVALID_UTF8_SUBSTITUTE));
        if ($this->bytes + $size > $this->options->maxBytes) {
            $this->drop($snapshot->status());
            return;
        }
        $this->buffer[] = new ExecutionCompleted($snapshot, $correlation);
        $this->bytes += $size;
    }

    /** @param Closure(object): void $dispatch */
    public function flush(Closure $dispatch): bool
    {
        if ($this->closed || $this->active !== 0 || $this->flushing) {
            return false;
        }
        $this->advanceWindow();
        $this->flushing = true;
        $events = $this->buffer;
        $this->buffer = [];
        $this->bytes = 0;
        try {
            foreach ($events as $event) {
                if ($this->active !== 0 || $this->exported >= $this->options->perWindow) {
                    $this->drop($event->snapshot->status());
                    continue;
                }
                $this->exported++;
                $this->dispatch($dispatch, $event);
            }
            if ($this->dropped > 0 && !$this->summarySent && $this->active === 0) {
                $summary = new TelemetryDropped($this->dropped, $this->firstDropped, $this->lastDropped, $this->outcomes);
                $this->dropped = 0;
                $this->outcomes = [];
                $this->summarySent = true;
                $this->dispatch($dispatch, $summary);
            }
        } finally {
            $this->flushing = false;
        }
        return true;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->buffer = [];
        $this->bytes = $this->dropped = 0;
        $this->outcomes = [];
    }

    public function hasPending(): bool
    {
        return $this->buffer !== [] || $this->dropped > 0;
    }

    private function advanceWindow(): void
    {
        $now = $this->clock->monotonicMs();
        if ($now - $this->windowStarted >= $this->options->windowSeconds * 1000) {
            $this->windowStarted = $now;
            $this->exported = 0;
            $this->summarySent = false;
        }
    }

    private function drop(string $status): void
    {
        if ($this->dropped++ === 0) {
            $this->firstDropped = (float) $this->clock->unixTime();
        }
        $this->lastDropped = (float) $this->clock->unixTime();
        $status = in_array($status, ['success', 'partial', 'failed', 'abandoned'], true) ? $status : 'unknown';
        $this->outcomes[$status] = ($this->outcomes[$status] ?? 0) + 1;
    }

    /** @param Closure(object): void $dispatch */
    private function dispatch(Closure $dispatch, object $event): void
    {
        try {
            $dispatch($event);
        } catch (Throwable) {
            // Никаких повторов доставки или рекурсивного логирования.
        }
    }
}
