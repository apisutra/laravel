<?php

declare(strict_types=1);

use ApiSutra\Config\ClientConfig;
use ApiSutra\Laravel\Tests\Stubs\Queue\WorkerEnvironment;
use ApiSutra\Laravel\Tests\Stubs\Queue\QueueOperation;
use Illuminate\Queue\WorkerOptions;
use Laravel\Telescope\Contracts\EntriesRepository;
use ApiSutra\Laravel\Observability\Events\ExecutionCompleted;
use ApiSutra\Laravel\Observability\ObservationManager;
use ApiSutra\Laravel\SdkServiceProvider;
use ApiSutra\Laravel\Tests\Stubs\Requests\DiRequest;
use ApiSutra\Laravel\Tests\Stubs\TestClient;
use ApiSutra\Testing\MockResponse;
use ApiSutra\Transport\MockTransport;
use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Watchers\EventWatcher;

it('EventWatcher Telescope принимает один отложенный итог с безопасным payload', function (): void {
    $app = new Application(sys_get_temp_dir());
    $app->instance('config', new Repository());
    $app->register(EventServiceProvider::class);
    $app->register(SdkServiceProvider::class);
    Telescope::$entriesQueue = [];
    Telescope::startRecording(false);
    (new EventWatcher())->register($app);
    try {
        $manager = $app->make(ObservationManager::class);
        $manager->begin('http');
        $transport = new MockTransport();
        $transport->fake([DiRequest::class => MockResponse::success(['password' => 'secret'])]);
        $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example'), $transport);
        $client->send(new DiRequest());
        expect(Telescope::$entriesQueue)->toBe([]);
        $manager->end('http');
        expect(Telescope::$entriesQueue)->toHaveCount(1)
            ->and(Telescope::$entriesQueue[0]->content['name'])->toBe(ExecutionCompleted::class)
            ->and(json_encode(Telescope::$entriesQueue[0]->content))->not->toContain('secret');
    } finally {
        Telescope::stopRecording();
        Telescope::$entriesQueue = [];
    }
});

it('сохраняет Telescope в текущей попытке настоящего worker, включая повторяемое исключение', function (): void {
    $environment = new WorkerEnvironment();
    $app = $environment->app;
    $app['config']->set('apisutra.integration.observation.enabled', true);
    $stored = [];
    $storage = $this->createStub(EntriesRepository::class);
    $storage->method('store')->willReturnCallback(static function ($entries) use (&$stored): void {
        foreach ($entries as $entry) { $stored[] = $entry->content['name']; }
    });
    $storage->method('update')->willReturn(collect());
    $app->instance(EntriesRepository::class, $storage);
    Telescope::$entriesQueue = [];
    Telescope::listenForStorageOpportunities($app);
    (new EventWatcher())->register($app);
    $transport = new MockTransport();
    $transport->fake([DiRequest::class => MockResponse::success()]);
    $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example'), $transport);
    try {
        foreach ([false, true, false] as $fail) {
            $app->instance('job.operation', static function () use ($client, $fail): void {
                $client->send(new DiRequest());
                if ($fail) { throw new RuntimeException('retry'); }
            });
            $command = new QueueOperation();
            $command->maxExceptions = 3;
            $job = $environment->queue->job($command);
            try { $environment->worker->process('memory', $job, new WorkerOptions()); } catch (RuntimeException $e) {
                expect($e->getMessage())->toBe('retry');
            }
            expect($app->make(ObservationManager::class)->observer())->toBeNull()->and(Telescope::$entriesQueue)->toBe([]);
        }
        expect($stored)->toBe(array_fill(0, 3, ExecutionCompleted::class));
    } finally {
        Telescope::stopRecording();
        Telescope::$entriesQueue = [];
    }
});
