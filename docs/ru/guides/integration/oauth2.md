<!-- languages --> <a href="../../../en/guides/integration/oauth2.md">English</a> · <a href="oauth2.md">Русский</a> <!-- /languages -->
# OAuth2 в Laravel <a id="section-1"></a>

OAuth2 принадлежит ядру; Laravel-адаптер не добавляет реализацию grants или обязательную
конфигурацию. [Контракт OAuth2 ядра](https://github.com/apisutra/php/blob/master/docs/ru/reference/auth/oauth2.md)
описывает Client Credentials, Authorization Code, state/PKCE, хранение и retry.
Храните OAuth-настройки в `config/services.php` как скалярные значения, а runtime-объекты
создавайте в фабрике клиента SDK. Им не место в Laravel config cache.

## Создание клиента <a id="client"></a>

`$app` — приложение Laravel. `$oauth` — `OAuth2Config` из настроек провайдера.
Передайте полученный конфиг конструктору своего SDK-клиента:

```php
use ApiSutra\Auth\OAuth2\OAuth2Authenticator;
use ApiSutra\Laravel\ClientConfigFactory;

$config = $app->make(ClientConfigFactory::class)->make([
    'baseUrl' => 'https://api.example.test',
    'auth' => OAuth2Authenticator::clientCredentials($oauth),
]);
```

Публикация сервисов и регистрация хранилища не требуются. Сохраняются defaults фабрики
и явные overrides приложения. Для Authorization Code контроллер управляет redirect,
разбором callback, привязкой к сессии и атомарным погашением attempt. Обмен выполняется
через `$client->send($request)->dataOrFail()` либо
`$client->sendAsync($request)->wait()->dataOrFail()`; job обязан дождаться async-результата.
`exchange()` может бросить `OAuth2Exception` при проверке callback, до отправки SDK
и создания промиса; обработайте его в контроллере. Настройка `throwOnErrors` клиента
не подавляет это исключение. См. [границы ошибок и восстановление](https://github.com/apisutra/php/blob/master/docs/ru/reference/auth/oauth2.md#execution).

## Пользовательские подключения и jobs <a id="workers"></a>

Восстанавливайте отдельный `OAuth2Credential` для подключения данного job/request;
не регистрируйте изменяемый credential одного пользователя как singleton процесса.
Защищённо сохраняйте `OAuth2TokenSet::export()` и восстанавливайте через
`OAuth2TokenSet::restore()`. Передавайте стабильный ID записи подключения как `identity`.
Синхронно сохраняйте ротацию в `onTokensChanged`, бросая исключение при отказе хранилища.
После такой ошибки `retryPersistence()` повторяет сохранение без token HTTP.
Первый результат обмена code сохраните явно: создание credential не вызывает
callback. Снимки токенов не сохраняют блокировку credential; хранилище приложения
должно переносить решения о восстановлении между jobs/процессами.

При нескольких workers с **одним подключением** координацией владеет приложение.
В эскизе предполагаются callbacks приложения: `$withOwnership($id, $operation)` гарантирует
исключительное владение до конца операции, `$loadTokens` читает запись, `$saveTokens`
сохраняет снимок либо бросает исключение, `$createClient` создаёт клиент вашего SDK,
`$resourceRequest` — его API-операция. Эти callbacks не являются сервисами SDK.

```php
use ApiSutra\Auth\OAuth2\OAuth2Authenticator;
use ApiSutra\Auth\OAuth2\OAuth2Credential;
use ApiSutra\Auth\OAuth2\OAuth2TokenSet;

$result = $withOwnership($connectionId, function () use (
    $connectionId, $loadTokens, $saveTokens, $createClient, $config, $oauth, $resourceRequest,
) {
    $tokens = OAuth2TokenSet::restore($loadTokens($connectionId)); // Читать после захвата владения.
    $credential = new OAuth2Credential($tokens, $connectionId, function (OAuth2TokenSet $next) use ($connectionId, $saveTokens): void {
        $saveTokens($connectionId, $next->export());
    });
    $client = $createClient($config->with(auth: OAuth2Authenticator::authorizationCode($oauth, $credential)));
    return $client->sendAsync($resourceRequest)->wait()->dataOrFail(); // Сохранить ротации до освобождения владения.
});
```

Этот простой рецепт последовательно выполняет операции одного подключения, включая
ресурсный HTTP. Разные подключения независимы. Выбирайте владение и восстановление
под приложение: одного `Cache::lock()` с большим TTL недостаточно для гарантии владения
после истечения lease или восстановления после падения между удалённой ротацией и
локальной записью. Координация refresh в памяти SDK не заменяет межпроцессную.
См. [границы и восстановление](https://github.com/apisutra/php/blob/master/docs/ru/reference/auth/oauth2.md#workers).
Адаптер не добавляет сессию, модель, backend распределённых блокировок или worker hooks.
