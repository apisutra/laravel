<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Testing;

use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use Throwable;
use ApiSutra\Laravel\Observability\ObservationManager;

/** @mixin TestCase */
trait InteractsWithApiSutra
{
    protected function setUpInteractsWithApiSutra(): void
    {
        $manager = $this->app->make(TestingManager::class);
        $manager->begin();
        $observations = $this->app->make(ObservationManager::class);
        $observations->begin('test');
        $this->beforeApplicationDestroyed(static function () use ($manager, $observations): void {
            try {
                $manager->close();
            } catch (Throwable $error) {
                throw new AssertionFailedError($error->getMessage(), 0, $error);
            } finally {
                $observations->close();
            }
        });
    }
}
