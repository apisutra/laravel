<?php

declare(strict_types=1);

namespace Integration;

use ApiSutra\Laravel\ClientConfigFactory;
use ApiSutra\Laravel\Pagination;
use ApiSutra\Laravel\LaravelContainerProvider;
use ApiSutra\Pagination\PaginationRule;
use ApiSutra\Resolver\ClientRegistry;
use ApiSutra\Testing\MockResponse;
use ApiSutra\Transport\DefaultTransportFactory;
use ApiSutra\Transport\MockTransport;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Foundation\Application;
use Integration\First\Client;
use Integration\Pagination\PagesRequest;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Revolt\EventLoop;

final class PaginationChecks
{
    public static function run(Application $app): void
    {
        $watchers = EventLoop::getIdentifiers();
        $config = $app->make(ClientConfigFactory::class)->make([
            'baseUrl' => 'https://pagination.fixture.test',
            'paginationRule' => PaginationRule::all(concurrency: 3),
        ]);
        $transport = new MockTransport();
        $transport->fake(['*' => static function (PagesRequest $request): MockResponse {
            $page = $request->getContext()->paginationOptions->getPage();
            return MockResponse::success(['data' => [$page], 'meta' => ['page' => $page, 'per_page' => 1, 'total' => 4]]);
        }]);
        $client = new Client($config, $transport);
        $app->instance('pagination.client', $client);
        $app->make(ClientRegistry::class)->register($app->make('pagination.client'), 'Integration\\Pagination');
        $request = $app->make(PagesRequest::class);
        check($request->getClient() === $client, 'DI не привязал клиент страниц');
        check($request->sendAsync()->wait()->raw()->data === [1, 2, 3, 4], 'Фабрика/DI потеряли правило конкурентности');
        check($request->paginate()->range(2, 3)->items() === [2, 3], 'Прямой paginator потерял правило клиента');

        check(Pagination::collect($request->paginate()->withConcurrency(1))->take(2)->all() === [1, 2], 'Ленивая Laravel-коллекция не прочитала элементы');

        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"data":[1]}')]);
        $app->instance(PsrClientInterface::class, new GuzzleClient(['handler' => HandlerStack::create($mock)]));
        $psr = new DefaultTransportFactory()->create(new LaravelContainerProvider($app));
        $sync = new Client($config->with(timeout: 0, connectTimeout: 0), $psr);
        $result = $sync->send(new PagesRequest())->raw();
        check($result->errors->first()?->code->value === 'configuration_error' && count($mock) === 1, 'Sync-only binding пропустил конкурентный HTTP');
        check($sync->send(new PagesRequest()->rules(PaginationRule::single()))->raw()->isSuccess() && count($mock) === 0, 'PSR binding сломал обычный send');
        $app->forgetInstance(PsrClientInterface::class);
        EventLoop::run();
        check(EventLoop::getIdentifiers() === $watchers, 'Пагинация оставила задачи до следующего bootstrap');
    }
}
