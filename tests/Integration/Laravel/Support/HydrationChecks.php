<?php

declare(strict_types=1);

namespace Integration;

use ApiSutra\Config\HydrationConfig;
use ApiSutra\Laravel\ClientConfigFactory;
use ApiSutra\Resolver\ClientRegistry;
use ApiSutra\Testing\MockResponse;
use ApiSutra\Transport\MockTransport;
use Illuminate\Contracts\Foundation\Application;
use Integration\First\Client;
use Integration\Hydration\ItemFactory;
use Integration\Hydration\ItemHydrator;
use Integration\Hydration\ItemRequest;

final class HydrationChecks
{
    public static function run(Application $app): void
    {
        $factory = $app->make(ClientConfigFactory::class);
        $hydrator = $app->make(ItemHydrator::class);
        $config = $factory->make(['baseUrl' => 'https://hydrator.fixture.test', 'hydration' => new HydrationConfig(hydrator: $hydrator)]);
        check($config->hydration->hydrator === $hydrator, 'Фабрика потеряла общий гидратор');
        $transport = new MockTransport();
        $transport->fake(['*' => MockResponse::success(['id' => 7])]);
        $client = new Client($config, $transport);
        $app->make(ClientRegistry::class)->register($client, 'Integration\\Hydration');
        check(!$app->bound(ItemHydrator::class) && !$app->bound(ItemFactory::class), 'Проверка должна использовать обычный autowiring');
        $request = $app->make(ItemRequest::class);
        check($request->send()->dataOrFail()->id === 7, 'Request-first не разрешил гидратор и фабрику');
        $app->instance(ItemHydrator::class, $hydrator);
        try {
            check($client->sendAsync($app->make(ItemRequest::class))->wait()->dataOrFail()->id === 7, 'Явный binding гидратора не работает');
        } finally {
            $app->forgetInstance(ItemHydrator::class);
        }
    }
}
