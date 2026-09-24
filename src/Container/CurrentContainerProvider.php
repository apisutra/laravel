<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Container;

use ApiSutra\Contracts\Interfaces\Container\ContainerProviderInterface;
use ApiSutra\Laravel\LaravelContainerProvider;
use ApiSutra\Laravel\SdkServiceProvider;
use ApiSutra\Support\NullContainerProvider;
use Illuminate\Container\Container;
use WeakReference;

/** Текущее приложение определяется только до входа в область исполнения SDK. */
final readonly class CurrentContainerProvider
{
    /** @var WeakReference<object> */
    private WeakReference $fallback;

    public function __construct(object $application)
    {
        $this->fallback = WeakReference::create($application);
    }

    public function resolve(): ContainerProviderInterface
    {
        $current = class_exists(Container::class) ? Container::getInstance() : null;
        $container = $current?->bound(SdkServiceProvider::class . '.registered')
            ? $current : $this->fallback->get();
        return $container === null ? new NullContainerProvider() : new LaravelContainerProvider($container);
    }
}
