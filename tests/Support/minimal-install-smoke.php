<?php

declare(strict_types=1);

use ApiSutra\Laravel\ClientConfigFactory;
use ApiSutra\Laravel\ClientResponseAdapter;
use ApiSutra\Laravel\Container\CurrentContainerProvider;
use ApiSutra\Laravel\LaravelContainerProvider;
use ApiSutra\Laravel\RequestFactory;
use ApiSutra\Laravel\SdkServiceProvider;
use ApiSutra\Response\ClientResponse;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Worker;
use Laravel\Telescope\Telescope;

require $argv[1] . '/vendor/autoload.php';

foreach ([Application::class, Command::class, Worker::class, Telescope::class] as $optional) {
    if (class_exists($optional)) {
        throw new RuntimeException('Unexpected full-framework dependency: ' . $optional);
    }
}
$application = new stdClass();
$provider = new CurrentContainerProvider($application)->resolve();
$config = new ClientConfigFactory($provider)->make(['baseUrl' => 'https://example.test']);
$response = new ClientResponseAdapter()->toResponse(new ClientResponse(status: 200, body: ['ok' => true]));
if (!$provider instanceof LaravelContainerProvider || $config->baseUrl !== 'https://example.test'
    || $response->getStatusCode() !== 200 || !class_exists(SdkServiceProvider::class)) {
    throw new RuntimeException('Minimal adapter installation failed');
}
new RequestFactory();
echo "Minimal adapter without Foundation, Queue, Console or Telescope — OK\n";
