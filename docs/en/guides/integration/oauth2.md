<!-- languages --> <a href="oauth2.md">English</a> · <a href="../../../ru/guides/integration/oauth2.md">Русский</a> <!-- /languages -->
# OAuth2 in Laravel <a id="section-1"></a>

OAuth2 belongs to the core; the Laravel adapter adds no grant implementation or
mandatory configuration. Use [the core OAuth2 contract](https://github.com/apisutra/php/blob/master/docs/en/reference/auth/oauth2.md)
for Client Credentials, Authorization Code, state/PKCE, storage and retry semantics.
Keep OAuth configuration in `config/services.php` as scalar values, and construct
runtime objects in your SDK's client factory. They do not belong in Laravel's config cache.

## Construct a client <a id="client"></a>

`$app` is your Laravel application. `$oauth` is an `OAuth2Config` constructed from your
provider's settings. Pass the resulting config to your SDK client constructor:

```php
use ApiSutra\Auth\OAuth2\OAuth2Authenticator;
use ApiSutra\Laravel\ClientConfigFactory;

$config = $app->make(ClientConfigFactory::class)->make([
    'baseUrl' => 'https://api.example.test',
    'auth' => OAuth2Authenticator::clientCredentials($oauth),
]);
```

No service publication or storage registration is required. Existing factory defaults
and explicit application overrides remain available. For Authorization Code, your
controller owns redirect, callback parsing, session binding and atomic consumption of
the saved attempt. Exchange through `$client->send($request)->dataOrFail()` or
`$client->sendAsync($request)->wait()->dataOrFail()`; a job must await async results.
`exchange()` may throw `OAuth2Exception` while validating the callback, before any
SDK send or promise exists; handle it in the controller. The client's `throwOnErrors`
setting does not suppress that exception. See [failure boundaries and recovery](https://github.com/apisutra/php/blob/master/docs/en/reference/auth/oauth2.md#execution).

## User connections and jobs <a id="workers"></a>

Restore a fresh `OAuth2Credential` for the connection used by a job/request; do not bind
one mutable user's credential as a process-wide singleton. Store `OAuth2TokenSet::export()`
securely and restore with `OAuth2TokenSet::restore()`. Use the stable connection record ID
as `identity`. Save each rotated pair synchronously in `onTokensChanged`, throwing when
storage fails. After such a failure, `retryPersistence()` repeats saving without token HTTP.
Save the initial code-exchange result explicitly: constructing a credential does
not invoke the callback. Token snapshots do not preserve a credential's blocked
state; application storage must retain recovery decisions across jobs/processes.

For several workers using the **same connection**, the application owns coordination.
This sketch assumes application callbacks: `$withOwnership($id, $operation)` guarantees
exclusive ownership for the entire operation, `$loadTokens` reads the record,
`$saveTokens` commits its new snapshot or throws, `$createClient` builds your SDK client,
and `$resourceRequest` is its API operation. These callbacks are not SDK services.

```php
use ApiSutra\Auth\OAuth2\OAuth2Authenticator;
use ApiSutra\Auth\OAuth2\OAuth2Credential;
use ApiSutra\Auth\OAuth2\OAuth2TokenSet;

$result = $withOwnership($connectionId, function () use (
    $connectionId, $loadTokens, $saveTokens, $createClient, $config, $oauth, $resourceRequest,
) {
    $tokens = OAuth2TokenSet::restore($loadTokens($connectionId)); // Read after acquiring ownership.
    $credential = new OAuth2Credential($tokens, $connectionId, function (OAuth2TokenSet $next) use ($connectionId, $saveTokens): void {
        $saveTokens($connectionId, $next->export());
    });
    $client = $createClient($config->with(auth: OAuth2Authenticator::authorizationCode($oauth, $credential)));
    return $client->sendAsync($resourceRequest)->wait()->dataOrFail(); // Save rotations before releasing ownership.
});
```

This simple recipe serializes operations per connection, including resource HTTP.
Connections remain independent. Choose ownership and recovery suitable for your
application; `Cache::lock()` with a large TTL alone does not guarantee ownership after
lease expiry, nor recover a crash between remote rotation and local saving. The SDK's
in-memory refresh coordination cannot replace interprocess coordination. See the
[limits and recovery contract](https://github.com/apisutra/php/blob/master/docs/en/reference/auth/oauth2.md#workers).
The adapter adds no session, model, distributed lock backend or worker lifecycle hooks.
