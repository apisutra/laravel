<?php

declare(strict_types=1);

namespace Integration;

use ApiSutra\Auth\OAuth2\OAuth2Authenticator;
use ApiSutra\Auth\OAuth2\OAuth2Credential;
use ApiSutra\Auth\OAuth2\OAuth2TokenSet;
use ApiSutra\Config\OAuth2Config;
use ApiSutra\Laravel\ClientConfigFactory;
use ApiSutra\Transport\MockTransport;
use Illuminate\Cache\Repository;
use Illuminate\Cache\ArrayStore;
use Integration\First\Client;
use Integration\First\ItemsRequest;

final readonly class OAuthProbeJob
{
    public function __construct(private string $connection, private bool $async)
    {
    }

    public function handle(ClientConfigFactory $configs, Repository $store, MockTransport $transport): void
    {
        // Тестовая блокировка приложения. Production-владение и восстановление выбирает приложение.
        $backend = $store->getStore();
        check($backend instanceof ArrayStore, 'Ожидался изолированный тестовый store');
        $backend->lock('oauth:' . $this->connection, 30)->block(1, function () use ($configs, $store, $transport): void {
            // Чтение только после захвата: следующий job видит сохранённую ротацию.
            $tokens = OAuth2TokenSet::restore($store->get($this->connection));
            $credential = new OAuth2Credential($tokens, $this->connection, function (OAuth2TokenSet $next) use ($store): void {
                $store->put($this->connection, $next->export());
            });
            $auth = OAuth2Authenticator::authorizationCode(new OAuth2Config('https://identity.test/token', 'client', 'fixture-secret'), $credential);
            $client = new Client($configs->make(['baseUrl' => 'https://oauth-api.test', 'auth' => $auth]), $transport);
            $request = new ItemsRequest();
            $result = $this->async ? $client->sendAsync($request)->wait() : $client->send($request);
            check($result->raw()->isSuccess(), 'OAuth job: ' . ($result->raw()->exception?->getMessage() ?? $result->raw()->message() ?? 'unknown'));
        });
    }
}
