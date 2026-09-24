<?php

declare(strict_types=1);

use ApiSutra\Contracts\Interfaces\Core\TransportInterface;
use Example\Records\DemoClient;
use Example\Records\Resources\Records\Get\GetRecordRequest;
use Illuminate\Contracts\Console\Kernel;
use Integration\RecordsSdkChecks;

$vendor = require __DIR__ . '/bootstrap/vendor-path.php';
require $vendor . '/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
RecordsSdkChecks::check(!$app->resolved(DemoClient::class), 'Bootstrap создал клиент SDK');
$transport = RecordsSdkChecks::transport();
$app->instance(TransportInterface::class, $transport);
$request = $app->makeWith(GetRecordRequest::class, ['id' => 7]);
$client = $app->make(DemoClient::class);
$record = $request->send()->dataOrFail();
echo json_encode([
    'base_url' => $client->getConfig()->baseUrl,
    'timeout' => $client->getConfig()->timeout,
    'has_auth' => isset($transport->getRecorded()[0]->headers['Authorization']),
    'id' => $record->id,
    'extras' => $record->_extra,
    'cached' => $app->configurationIsCached(),
], JSON_THROW_ON_ERROR) . PHP_EOL;
