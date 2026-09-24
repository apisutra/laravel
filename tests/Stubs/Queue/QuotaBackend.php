<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs\Queue;

use ApiSutra\RateLimiting\RateLimitBackendInterface;
use ApiSutra\RateLimiting\RateLimitDecision;
use Throwable;

final class QuotaBackend implements RateLimitBackendInterface
{
    public int $calls = 0;
    public function __construct(public int $grants = 0, public int $delayMs = 1001, public ?Throwable $failure = null)
    {
    }

    public function tryAcquire(array $quotas, ?int $budgetMs = null): RateLimitDecision
    {
        $this->calls++;
        if ($this->failure !== null) { throw $this->failure; }
        return $this->calls <= $this->grants ? new RateLimitDecision(true) : new RateLimitDecision(false, [$quotas[0]->key], $this->delayMs);
    }
}
