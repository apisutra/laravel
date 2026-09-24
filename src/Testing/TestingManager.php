<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Testing;

use ApiSutra\Core\AbstractClient;
use ApiSutra\Exceptions\Configuration\ConfigurationException;
use ApiSutra\Testing\ClientFakeSession;
use Illuminate\Container\Container;
use Throwable;
use WeakMap;

/** Сессии принадлежат тесту и конкретному экземпляру клиента. */
final class TestingManager
{
    /** @var WeakMap<AbstractClient, ClientFakeSession> */
    private WeakMap $sessions;
    private bool $active = false;

    public function __construct(private readonly Container $container)
    {
        $this->sessions = new WeakMap();
    }

    public function begin(): void
    {
        if ($this->active) {
            throw new ConfigurationException('ApiSutra test lifecycle is already active.');
        }
        $this->active = true;
    }

    /** @param AbstractClient|string $client */
    public function for(AbstractClient|string $client): ClientFakeSession
    {
        if (!$this->active) {
            throw new ConfigurationException('Use InteractsWithApiSutra on your Laravel TestCase before calling ApiSutra::for().');
        }
        $instance = is_string($client) ? $this->resolve($client) : $client;
        return $this->sessions[$instance] ??= $instance->beginFakeSession();
    }

    public function close(): void
    {
        if (!$this->active) {
            return;
        }
        $failure = null;
        foreach ($this->sessions as $session) {
            try {
                $session->verify();
            } catch (Throwable $error) {
                $failure ??= $error;
            } finally {
                try {
                    $session->close();
                } catch (Throwable $error) {
                    $failure ??= $error;
                }
            }
        }
        $this->sessions = new WeakMap();
        $this->active = false;
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @param string $class */
    private function resolve(string $class): AbstractClient
    {
        $id = $this->container->getAlias($class);
        if (!$this->container->isShared($id)) {
            throw new ConfigurationException('ApiSutra::for() requires a shared binding or the actual client instance.');
        }
        foreach ($this->container->contextual as $bindings) {
            if (array_key_exists($id, $bindings)) {
                throw new ConfigurationException('Pass the actual client instance for a contextual binding.');
            }
        }
        $client = $this->container->make($id);
        if (!$client instanceof AbstractClient) {
            throw new ConfigurationException('ApiSutra::for() requires an AbstractClient instance.');
        }
        return $client;
    }
}
