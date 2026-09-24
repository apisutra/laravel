<?php

declare(strict_types=1);

use ApiSutra\Config\ClientConfig;
use ApiSutra\Exceptions\Configuration\ConfigurationException;
use ApiSutra\Exceptions\Testing\UnmockedRequestException;
use ApiSutra\Laravel\Testing\TestingManager;
use ApiSutra\Laravel\Tests\Stubs\Requests\DiRequest;
use ApiSutra\Laravel\Tests\Stubs\TestClient;
use ApiSutra\Testing\MockResponse;
use ApiSutra\Transport\MockTransport;
use Illuminate\Container\Container;

it('требует жизненный цикл и работает с реальным shared binding и его alias', function (): void {
    $app = new Container();
    $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example'), new MockTransport());
    $app->instance(TestClient::class, $client);
    $app->alias(TestClient::class, 'catalog');
    $manager = new TestingManager($app);
    expect(fn () => $manager->for($client))->toThrow(ConfigurationException::class, 'InteractsWithApiSutra');
    $manager->begin();
    $session = $manager->for(TestClient::class)->fake([DiRequest::class => MockResponse::success(['ok' => true])]);
    expect($manager->for('catalog'))->toBe($session)->and($manager->for($client))->toBe($session);
    expect($app->make(TestClient::class)->send(new DiRequest())->raw()->isSuccess())->toBeTrue();
    $session->assertSent(DiRequest::class, times: 1);
    $manager->close();
});

it('не угадывает transient и contextual клиента', function (): void {
    $app = new Container();
    $manager = new TestingManager($app);
    $manager->begin();
    expect(fn () => $manager->for(TestClient::class))->toThrow(ConfigurationException::class, 'actual client');
    $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example'), new MockTransport());
    $app->instance(TestClient::class, $client);
    $app->when('Consumer')->needs(TestClient::class)->give(static fn () => $client);
    expect(fn () => $manager->for(TestClient::class))->toThrow(ConfigurationException::class, 'contextual');
    $manager->for($client)->fake([])->assertNothingSent();
    $manager->close();
});

it('закрывает все сессии даже после нарушения и не переносит его в следующий тест', function (): void {
    $manager = new TestingManager(new Container());
    $a = new TestClient(new ClientConfig(baseUrl: 'https://test.example'), new MockTransport());
    $b = new TestClient(new ClientConfig(baseUrl: 'https://test.example'), new MockTransport());
    $manager->begin();
    $manager->for($a)->fake([]);
    $manager->for($b)->fake([DiRequest::class => MockResponse::success([])]);
    $a->send(new DiRequest())->raw();
    $b->send(new DiRequest())->raw();
    expect(fn () => $manager->close())->toThrow(UnmockedRequestException::class);
    $a->assertNothingSent();
    $b->assertNothingSent();
    $manager->begin();
    $manager->for($a)->fake([])->assertNothingSent();
    $manager->close();
});
