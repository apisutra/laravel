<?php

declare(strict_types=1);

use ApiSutra\Config\ClientConfig;
use ApiSutra\Laravel\Observability\ObservationManager;
use ApiSutra\Laravel\Tests\Stubs\Requests\DiRequest;
use ApiSutra\Laravel\Tests\Stubs\TestClient;
use ApiSutra\Laravel\Tests\Stubs\Testing\FrameworkTestCase;
use ApiSutra\Transport\MockTransport;
use ApiSutra\Testing\MockResponse;
use Illuminate\Foundation\Application;

/** Первый тест намеренно падает; второй подтверждает независимую очистку без fake. */
final class ObservationLifecycleTest extends FrameworkTestCase
{
    private static ?ObservationManager $previous = null;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app['config']->set('apisutra.integration.observation.enabled', true);
        return $app;
    }

    public function testExceptionalBodyWithoutFake(): void
    {
        self::$previous = $this->app->make(ObservationManager::class);
        $transport = new MockTransport();
        $transport->fake([DiRequest::class => MockResponse::success()]);
        new TestClient(new ClientConfig(baseUrl: 'https://test.example'), $transport)->send(new DiRequest());
        throw new RuntimeException('intentional observation lifecycle failure');
    }

    public function testNextTestIsClean(): void
    {
        $this->assertNotNull(self::$previous);
        $this->assertNull(self::$previous->observer());
        $this->assertFalse(self::$previous->flush());
        $this->assertNotNull($this->app->make(ObservationManager::class)->observer());
        self::$previous = null;
    }
}
