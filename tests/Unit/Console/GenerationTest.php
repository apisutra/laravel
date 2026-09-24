<?php

declare(strict_types=1);

use ApiSutra\Config\ClientConfig;
use ApiSutra\Laravel\Observability\Events\ExecutionCompleted;
use ApiSutra\Laravel\Observability\ObservationManager;
use ApiSutra\Laravel\Tests\Stubs\Console\ObservedCommand;
use ApiSutra\Laravel\Tests\Stubs\Queue\WorkerEnvironment;
use ApiSutra\Laravel\Tests\Stubs\Requests\DiRequest;
use ApiSutra\Laravel\Tests\Stubs\TestClient;
use ApiSutra\Testing\MockResponse;
use ApiSutra\Tooling\Generation\GenerationCommand;
use ApiSutra\Transport\MockTransport;
use ApiSutra\Laravel\Tests\Stubs\Console\PreparedConsoleKernel;

it('Artisan использует те же шаблоны и закрывает только свою вложенную область', function (): void {
    $environment = new WorkerEnvironment();
    $app = $environment->app;
    $app['config']->set('apisutra.integration.observation.enabled', true);
    $console = new PreparedConsoleKernel($app, $app['events'])->console();
    $console->add(new ObservedCommand());
    $transport = new MockTransport();
    $transport->fake([DiRequest::class => MockResponse::success()]);
    $app->instance(TestClient::class, new TestClient(new ClientConfig(baseUrl: 'https://test.example'), $transport));
    $events = [];
    $app['events']->listen(ExecutionCompleted::class, static function ($event) use (&$events): void { $events[] = $event; });
    expect($console->call('sdk:observed', ['--nested' => true]))->toBe(0)->and($events)->toHaveCount(3)
        ->and($app->make(ObservationManager::class)->observer())->toBeNull();
    $directory = sys_get_temp_dir() . '/apisutra-artisan-' . bin2hex(random_bytes(4));
    mkdir($directory);
    try {
        foreach (['client', 'request', 'dto'] as $kind) {
            $options = ['name' => ucfirst($kind), '--namespace' => 'Acme\\Sdk', '--directory' => $directory];
            expect($console->call('make:apisutra-' . $kind, $options))->toBe(0);
            $generated = file_get_contents($directory . '/' . ucfirst($kind) . '.php');
            unlink($directory . '/' . ucfirst($kind) . '.php');
            $path = new GenerationCommand()->execute(['make:' . $kind, ucfirst($kind), '--namespace=Acme\\Sdk', '--directory=' . $directory], $directory);
            expect(file_get_contents($path))->toBe($generated);
            expect($console->call('make:apisutra-' . $kind, $options))->toBe(1);
            unlink($path);
        }
    } finally { rmdir($directory); }
});

it('по умолчанию выбирает namespace приложения при нескольких PSR-4 корнях', function (): void {
    $environment = new WorkerEnvironment();
    $directory = sys_get_temp_dir() . '/apisutra-artisan-default-' . bin2hex(random_bytes(4));
    mkdir($directory);
    mkdir($directory . '/app');
    file_put_contents($directory . '/composer.json', json_encode(['autoload' => ['psr-4' => [
        'Acme\\' => 'app/', 'Database\\Factories\\' => 'database/factories/',
    ]]]));
    $environment->app->setBasePath($directory);
    $environment->app->useAppPath($directory . '/app');
    try {
        $console = new PreparedConsoleKernel($environment->app, $environment->app['events'])->console();
        expect($console->call('make:apisutra-client', ['name' => 'CatalogClient']))->toBe(0)
            ->and(file_get_contents($directory . '/app/CatalogClient.php'))->toContain('namespace Acme;');
    } finally {
        @unlink($directory . '/app/CatalogClient.php');
        unlink($directory . '/composer.json');
        rmdir($directory . '/app');
        rmdir($directory);
    }
});
