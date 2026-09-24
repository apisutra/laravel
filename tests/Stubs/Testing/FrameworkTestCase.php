<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs\Testing;

use ApiSutra\Laravel\SdkServiceProvider;
use ApiSutra\Laravel\Testing\InteractsWithApiSutra;
use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Facade;

abstract class FrameworkTestCase extends TestCase
{
    use InteractsWithApiSutra;

    public function createApplication(): Application
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('env', 'testing');
        $app->instance('config', new Repository(['app' => ['env' => 'testing']]));
        $app->register(EventServiceProvider::class);
        $app->register(SdkServiceProvider::class);
        Facade::setFacadeApplication($app);
        return $app;
    }
}
