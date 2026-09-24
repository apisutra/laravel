<?php

declare(strict_types=1);

namespace ApiSutra\Laravel;

use ApiSutra\Contracts\Interfaces\Container\ContainerProviderInterface;
use Illuminate\Contracts\Validation\Factory;

/**
 * Адаптер контейнера Laravel к интерфейсу провайдера.
 */
final readonly class LaravelContainerProvider implements ContainerProviderInterface
{
    public function __construct(
        private object $container,
    ) {
    }

    #[\Override]
    public function bound(string $id): bool
    {
        return method_exists($this->container, 'bound')
            ? (bool) $this->container->bound($id)
            : false;
    }

    #[\Override]
    public function make(string $id): ?object
    {
        if (!method_exists($this->container, 'make')) {
            return null;
        }

        $resolved = $this->container->make($id);
        return is_object($resolved) ? $resolved : null;
    }

    #[\Override]
    public function basePath(): ?string
    {
        if (!method_exists($this->container, 'basePath')) {
            return null;
        }

        $basePath = $this->container->basePath();
        return is_string($basePath) ? $basePath : null;
    }

    #[\Override]
    public function environment(): ?string
    {
        if (!method_exists($this->container, 'environment')) {
            return null;
        }

        $environment = $this->container->environment();
        return is_string($environment) ? $environment : null;
    }

    #[\Override]
    public function isDebug(): ?bool
    {
        if ($this->bound('config')) {
            $config = $this->make('config');
            if (is_object($config) && method_exists($config, 'get')) {
                $debug = $config->get('app.debug');
                return is_bool($debug) ? $debug : null;
            }
        }

        return null;
    }

    #[\Override]
    public function validatorFactory(): ?object
    {
        if (!$this->bound('validator')) {
            return null;
        }

        $factory = $this->make('validator');
        return $factory instanceof Factory ? $factory : null;
    }
}
