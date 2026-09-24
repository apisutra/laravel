<?php

declare(strict_types=1);

namespace Integration;

use ApiSutra\Contracts\Interfaces\Timing\ClockInterface;
use ApiSutra\Contracts\Interfaces\Timing\SleeperInterface;

final class CooldownClock implements ClockInterface, SleeperInterface
{
    private int $now = 0;
    public function monotonicMs(): int
    {
        return $this->now;
    }
    public function unixTime(): int
    {
        return 1_800_000_000 + intdiv($this->now, 1000);
    }
    public function sleepMs(int $milliseconds): void
    {
        $this->now += $milliseconds;
    }
}
