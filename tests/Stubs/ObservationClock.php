<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs;

use ApiSutra\Contracts\Interfaces\Timing\ClockInterface;

final class ObservationClock implements ClockInterface
{
    public int $ms = 0;
    public function monotonicMs(): int { return $this->ms; }
    public function unixTime(): int { return intdiv($this->ms, 1000); }
}
