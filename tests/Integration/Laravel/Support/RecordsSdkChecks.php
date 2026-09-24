<?php

declare(strict_types=1);

namespace Integration;

use ApiSutra\Contracts\Interfaces\Core\TransportInterface;
use ApiSutra\Enums\Configuration\Environment;
use ApiSutra\Testing\MockResponse;
use ApiSutra\Transport\MockTransport;
use Example\Records\DemoClient;
use Example\Records\Laravel\DemoServiceProvider;
use Example\Records\Resources\Records\Get\GetRecordRequest;
use Example\Records\Resources\Records\Get\GetRecordResponseDto;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

final class RecordsSdkChecks
{
    /** @return array{data: array<string, mixed>} */
    public static function payload(): array
    {
        return ['data' => ['record_id' => 7, 'title' => 'Laravel', 'created_at' => '2026-09-16T12:00:00+00:00', 'new_field' => false]];
    }

    public static function transport(): MockTransport
    {
        $transport = new MockTransport();
        $transport->preventStrayRequests();
        $transport->fake([GetRecordRequest::class => MockResponse::success(self::payload())]);
        return $transport;
    }

    public static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public static function installed(Application $app): void
    {
        self::check($app->getProvider(DemoServiceProvider::class) !== null, 'Composer discovery не подключил Records SDK');
        self::check(!$app->resolved(DemoClient::class), 'Bootstrap создал клиент Records SDK');
        $transport = self::transport();
        $app->instance(TransportInterface::class, $transport);
        $app['config']->set('apisutra.records.base_url', 'https://laravel.example.test/v2');
        $request = $app->makeWith(GetRecordRequest::class, ['id' => 7]);
        $demo = $app->make(DemoClient::class);
        self::check($request->getClient() === $demo, 'Запрос первым не получил клиент SDK');
        $record = $request->send()->dataOrFail();
        self::check($record instanceof GetRecordResponseDto && $record->id === 7 && $record->_extra === ['new_field' => false], 'Потеряны DTO и правила SDK');
        self::check($record->createdAt->format('c') === '2026-09-16T12:00:00+00:00', 'Потеряна дата DTO SDK');
        self::check($demo->getConfig()->baseUrl === 'https://laravel.example.test/v2', 'Потерян URL SDK');
        self::check($demo->getConfig()->environment === Environment::Testing, 'Потеряно окружение Laravel');
        self::check($demo->getConfig()->debug && $demo->getConfig()->containerProvider === null, 'Неверные defaults или захвачен контейнер');
        self::check($app->make(DemoClient::class) === $demo && count($transport->getRecorded()) === 1, 'Потеряны singleton/транспорт');
    }
}
