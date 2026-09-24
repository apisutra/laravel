<?php

declare(strict_types=1);

namespace ApiSutra\Laravel;

use ApiSutra\Config\ClientConfig;
use ApiSutra\Contracts\Interfaces\Container\ContainerProviderInterface;
use ApiSutra\Enums\Configuration\Environment;

/**
 * Defaults конкретного приложения без автоматического удержания его в конфиге.
 */
final readonly class ClientConfigFactory
{
    public function __construct(private ContainerProviderInterface $provider)
    {
    }

    /** @param array<string, mixed> $overrides */
    public function make(array $overrides): ClientConfig
    {
        $provider = $overrides['containerProvider'] ?? null;
        $provider = $provider instanceof ContainerProviderInterface ? $provider : $this->provider;
        $overrides['baseUrl'] ??= '';
        $overrides['debug'] ??= $provider->isDebug() ?? false;
        $overrides['environment'] ??= match (strtolower($provider->environment() ?? '')) {
            'local' => Environment::Local,
            'testing' => Environment::Testing,
            'staging' => Environment::Staging,
            default => Environment::Production,
        };

        return new ClientConfig(...$overrides);
    }
}
