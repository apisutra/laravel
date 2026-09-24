<?php

declare(strict_types=1);

use ApiSutra\Contracts\Interfaces\Core\TransportInterface;
use ApiSutra\Laravel\Contracts\RequestFactoryInterface;
use ApiSutra\Laravel\SdkServiceProvider;
use ApiSutra\RateLimiting\Backends\PhpRedisRateLimitBackend;
use ApiSutra\RateLimiting\RateLimitQuota;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Integration\First\Client;
use Integration\First\ItemsRequest;
use Integration\Second\Client as SecondClient;
use Integration\Second\ItemsRequest as SecondRequest;
use Integration\ProbeJob;
use Integration\RecordsSdkChecks;
use Integration\AsyncChecks;
use Integration\OAuthChecks;
use Integration\CooldownChecks;
use Integration\PaginationChecks;
use Integration\HydrationChecks;
use Integration\ObservationChecks;

// Laravel может обработать исключение с нулевым exit code; успех требует полного прохода.
$completed = false;
register_shutdown_function(static function () use (&$completed): void {
    if (!$completed) {
        fwrite(STDERR, "Laravel integration did not complete.\n");
        exit(1);
    }
});

$vendor = require __DIR__ . '/bootstrap/vendor-path.php';
require $vendor . '/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cooldownGroup = 'bootstrap-' . bin2hex(random_bytes(12));
foreach ([false, true] as $cached) {
    $app = require __DIR__ . '/bootstrap/app.php';
    $console = $app->make(ConsoleKernel::class);
    $console->bootstrap();
    check($app->getProvider(SdkServiceProvider::class) !== null, 'Package discovery не подключил SDK');
    check($app->configurationIsCached() === $cached, 'Неверный режим config cache');
    if (getenv('APISUTRA_TEST_REDIS') === '1') {
        CooldownChecks::shared($app, $cooldownGroup, !$cached);
        $backend = $app->make(PhpRedisRateLimitBackend::class);
        $quota = new RateLimitQuota('laravel-' . bin2hex(random_bytes(8)), 1, 1000);
        check($backend->tryAcquire([$quota])->granted, 'Laravel Redis не выдал разрешение');
        check(!$backend->tryAcquire([$quota])->granted, 'Laravel Redis не сохранил общий счётчик');
        check($app->make(PhpRedisRateLimitBackend::class) === $backend, 'Backend пересоздан');
    }
    $client = $app->make(Client::class);
    $request = $app->make(ItemsRequest::class);
    check($request->limit === '20' && $request->getClient() === $client, 'DI изменил значения или клиента');
    check($app->make(SecondRequest::class)->getClient() === $app->make(SecondClient::class), 'Перепутаны клиенты');
    $transport = $app->make(TransportInterface::class);
    $app->register(SdkServiceProvider::class);
    check($app->make(TransportInterface::class) === $transport, 'Повторная регистрация заменила binding');
    $kernel = $app->make(HttpKernel::class);
    $incoming = Request::create('/probe?limit=500');
    $response = $kernel->handle($incoming);
    check($response->getStatusCode() === 200 && json_decode($response->getContent(), true) === ['limit' => '20', 'success' => true], 'HTTP DI подменил limit: ' . $response->getContent());
    $kernel->terminate($incoming, $response);
    $explicit = $app->make(RequestFactoryInterface::class)->make(ItemsRequest::class, $incoming);
    check($explicit->limit === '500', 'Явная фабрика не перенесла входящие данные');
    $app->make(Dispatcher::class)->dispatchSync(new ProbeJob('15'));
    $app->make(Dispatcher::class)->dispatchSync(new ProbeJob('30'));
    $next = $app->make(ItemsRequest::class);
    check($next->limit === '20' && $next->getOptions()->getTimeoutOverride() === null && $next->getClient() === $client, 'Worker сохранил состояние задания');
    check($console->call('about', ['--only' => 'environment']) === 0, 'Artisan не запускается');
    RecordsSdkChecks::installed($app);
    AsyncChecks::run($app);
    ObservationChecks::run($app);
    OAuthChecks::run($app);
    CooldownChecks::run($app);
    PaginationChecks::run($app);
    HydrationChecks::run($app);
    if (!$cached) {
        check($console->call('config:cache') === 0, 'config:cache не прошёл');
    } else {
        check($console->call('config:clear') === 0, 'config:clear не прошёл');
    }
    $app->flush();
}
$completed = true;
echo "Laravel 13: discovery, HTTP, explicit factory, two clients, Artisan, sequential jobs, config:cache — OK.\n";
