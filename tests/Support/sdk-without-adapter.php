<?php

declare(strict_types=1);

use ApiSutra\Exceptions\Configuration\ConfigurationException;
use Composer\InstalledVersions;
use Example\Records\Config\ClientConfigFactory;
use Example\Records\DemoClient;
use Example\Records\Laravel\DemoServiceProvider;
use Example\Records\Resources\Records\Get\GetRecordRequest;
use Illuminate\Contracts\Console\Kernel;
use Integration\RecordsSdkChecks;

$application = $argv[1];
require $application . '/vendor/autoload.php';
RecordsSdkChecks::check(
    !InstalledVersions::isInstalled('apisutra/laravel'),
    'Suggest установил необязательный адаптер',
);
$app = require $application . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
RecordsSdkChecks::check($app->getProvider(DemoServiceProvider::class) !== null, 'Discovery SDK не выполнен');
RecordsSdkChecks::check(!$app->resolved(DemoClient::class), 'Bootstrap попытался создать клиент');

// Запускаем тот же опубликованный файл, который читает пользователь SDK.
ob_start();
require InstalledVersions::getInstallPath('example/records-sdk') . '/run.php';
$output = json_decode((string) ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
RecordsSdkChecks::check($output['id'] === 7 && $output['status'] === 404, 'Явная сборка SDK не работает в Laravel');

foreach ([DemoClient::class, GetRecordRequest::class] as $class) {
    try {
        $app->makeWith($class, ['id' => 7]);
        throw new RuntimeException('DI без адаптера не сообщил об отсутствии интеграции');
    } catch (ConfigurationException $error) {
        RecordsSdkChecks::check(
            str_contains($error->getMessage(), 'composer require apisutra/laravel'),
            'DI не объяснил установку адаптера',
        );
    }
}

// Явный клиент и уже связанный запрос остаются выбором приложения без адаптера.
$client = new DemoClient(ClientConfigFactory::create(), RecordsSdkChecks::transport());
$request = (new GetRecordRequest(7))->setClient($client);
$app->instance(DemoClient::class, $client);
$app->instance(GetRecordRequest::class, $request);
RecordsSdkChecks::check($app->make(DemoClient::class) === $client, 'Перезаписан явный клиент');
RecordsSdkChecks::check($app->make(GetRecordRequest::class) === $request, 'Перезаписан связанный запрос');
RecordsSdkChecks::check($request->send()->dataOrFail()->id === 7, 'Связанный запрос не выполнился');
echo "Records SDK in Laravel without adapter — OK.\n";
