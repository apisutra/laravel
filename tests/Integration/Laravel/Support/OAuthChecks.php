<?php

declare(strict_types=1);

namespace Integration;

use ApiSutra\Auth\OAuth2\AuthorizationCodeFlow;
use ApiSutra\Auth\OAuth2\Internal\TokenRequest;
use ApiSutra\Auth\OAuth2\OAuth2TokenSet;
use ApiSutra\Config\OAuth2Config;
use ApiSutra\Laravel\ClientConfigFactory;
use ApiSutra\Testing\MockResponse;
use ApiSutra\Transport\MockTransport;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Integration\First\Client;
use Integration\First\ItemsRequest;
use Revolt\EventLoop;

final class OAuthChecks
{
    public static function run(Application $app): void
    {
        $store = new Repository(new ArrayStore());
        $transport = new MockTransport();
        $transport->fake([
            TokenRequest::class => static function (TokenRequest $request): MockResponse {
                parse_str($request->getContext()->preparedRequest->body, $body);
                $id = $body['refresh_token'] ?? 'code';
                return MockResponse::success(['access_token' => 'access-' . $id, 'refresh_token' => 'next-' . $id, 'token_type' => 'Bearer']);
            },
            ItemsRequest::class => MockResponse::success(['ok' => true]),
        ]);
        // Отдельные объекты на каждое выполнение; никакого singleton пользовательских credentials.
        foreach (['user-a', 'user-b'] as $id) {
            $store->put($id, (new OAuth2TokenSet('expired', $id, 1))->export());
        }
        foreach ([false, true] as $async) {
            foreach (['user-a', 'user-b'] as $id) {
                $app->make(Dispatcher::class)->dispatchSync(new class ($id, $async, $store, $transport) {
                    public function __construct(private string $id, private bool $async, private Repository $store, private MockTransport $transport) {}
                    public function handle(ClientConfigFactory $configs): void
                    {
                        (new OAuthProbeJob($this->id, $this->async))->handle($configs, $this->store, $this->transport);
                    }
                });
            }
        }
        $transport->assertSent(TokenRequest::class, times: 2);
        check($store->get('user-a')['refreshToken'] === 'next-user-a', 'Ротация первого пользователя потеряна');
        check($store->get('user-b')['refreshToken'] === 'next-user-b', 'Credentials пользователей смешались');
        $flow = new AuthorizationCodeFlow(new OAuth2Config('https://identity.test/token', 'client', 'fixture-secret'), 'https://identity.test/authorize', 'https://app.test/callback');
        $attempt = $flow->begin();
        $request = $flow->exchange($attempt, ['state' => $attempt->state, 'code' => 'fixture-code'], 'https://app.test/callback');
        $client = new Client($app->make(ClientConfigFactory::class)->make(['baseUrl' => 'https://oauth-api.test']), $transport);
        check($client->sendAsync($request)->wait()->dataOrFail() instanceof OAuth2TokenSet, 'Async обмен code в Laravel не выполнен');
        EventLoop::run();
        check(EventLoop::getIdentifiers() === [], 'OAuth оставил watchers после job');
    }
}
