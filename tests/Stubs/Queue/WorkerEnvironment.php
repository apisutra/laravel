<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs\Queue;

use ApiSutra\Laravel\SdkServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Facade;

final readonly class WorkerEnvironment
{
    public Application $app;
    public Worker $worker;
    public MemoryQueue $queue;

    public function __construct()
    {
        $this->app = new Application(sys_get_temp_dir());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->instance('env', 'testing');
        $this->app->instance('config', new Repository(['queue' => ['default' => 'sync'], 'app' => ['key' => 'test']]));
        $this->app->register(EventServiceProvider::class);
        $this->app->register(BusServiceProvider::class);
        $this->app->register(QueueServiceProvider::class);
        $this->app->register(SdkServiceProvider::class);
        $this->app->boot();
        $this->worker = new Worker($this->app->make('queue'), $this->app->make('events'), new Handler($this->app), static fn (): bool => false);
        $this->worker->setCache(new Cache(new ArrayStore()));
        $this->queue = new MemoryQueue();
        $this->queue->setContainer($this->app);
    }
}
