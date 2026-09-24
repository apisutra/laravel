<?php

declare(strict_types=1);

use ApiSutra\Config\ClientConfig;
use ApiSutra\Diagnostics\ExecutionSnapshot;
use ApiSutra\Laravel\Observability\ObservationArea;
use ApiSutra\Laravel\Observability\ObservationManager;
use ApiSutra\Laravel\Observability\ObservationOptions;
use ApiSutra\Laravel\SdkServiceProvider;
use ApiSutra\Laravel\Tests\Stubs\Requests\DiRequest;
use ApiSutra\Laravel\Tests\Stubs\TestClient;
use ApiSutra\Testing\MockResponse;
use ApiSutra\Transport\MockTransport;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = new Application(sys_get_temp_dir());
$app->instance('config', new Repository());
$app->register(SdkServiceProvider::class);
$manager = $app->make(ObservationManager::class);
$transport = new MockTransport();
$transport->fake([DiRequest::class => MockResponse::success()]);
$client = new TestClient(new ClientConfig(baseUrl: 'https://test.example'), $transport);
$rounds = 500;
foreach ([false, true, false, true] as $enabled) {
    $app['config']->set('apisutra.integration.observation.enabled', $enabled);
    $manager->begin('benchmark');
    $start = hrtime(true);
    for ($i = 0; $i < $rounds; $i++) {
        $client->send(new DiRequest());
    }
    printf("observer=%s: %.1f us/operation\n", $enabled ? 'on' : 'off', (hrtime(true) - $start) / $rounds / 1000);
    $manager->end('benchmark');
}
$data = ['traceId' => str_repeat('a', 32), 'executionId' => str_repeat('b', 32), 'parentExecutionId' => null,
    'operation' => DiRequest::class, 'role' => 'root', 'status' => 'success', 'reason' => null, 'stage' => null,
    'durationMs' => 1800, 'attemptCount' => 1, 'httpDurationMs' => 120, 'method' => 'GET', 'origin' => 'https://test.example', 'clientLabel' => 'catalog'];
$area = new ObservationArea(new ObservationOptions());
$before = memory_get_usage();
for ($i = 0; $i < 200; $i++) {
    $area->accept(new ExecutionSnapshot(array_replace($data, ['executionId' => sprintf('%032d', $i)])), []);
}
$filled = memory_get_usage();
for ($i = 0; $i < 100000; $i++) {
    $area->accept(new ExecutionSnapshot(array_replace($data, ['executionId' => sprintf('%032d', $i)])), []);
}
printf("payload=%d bytes; 200 buffered heap=%d bytes; 100000 dropped heap growth=%d bytes\n", strlen(json_encode([$data, []])), $filled - $before, memory_get_usage() - $filled);
