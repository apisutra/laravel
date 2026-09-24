<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Facades;

use ApiSutra\Core\AbstractClient;
use ApiSutra\Laravel\Testing\TestingManager;
use ApiSutra\Testing\ClientFakeSession;
use Illuminate\Support\Facades\Facade;
use Override;

/** @method static ClientFakeSession for(AbstractClient|string $client) */
final class ApiSutra extends Facade
{
    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return TestingManager::class;
    }
}
