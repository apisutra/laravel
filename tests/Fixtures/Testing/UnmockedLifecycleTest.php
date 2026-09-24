<?php

declare(strict_types=1);

use ApiSutra\Config\ClientConfig;
use ApiSutra\Laravel\Facades\ApiSutra;
use ApiSutra\Laravel\Tests\Stubs\Requests\DiRequest;
use ApiSutra\Laravel\Tests\Stubs\TestClient;
use ApiSutra\Laravel\Tests\Stubs\Testing\FrameworkTestCase;
use ApiSutra\Transport\MockTransport;

/** Намеренно падающий тест: вызывается только внешней проверкой lifecycle. */
final class UnmockedLifecycleTest extends FrameworkTestCase
{
    public function testIgnoredFailureStillFailsTheTest(): void
    {
        $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example'), new MockTransport());
        ApiSutra::for($client)->fake([]);
        $client->send(new DiRequest())->raw();
        $this->assertTrue(true);
    }
}
