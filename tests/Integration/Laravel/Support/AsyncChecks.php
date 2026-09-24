<?php

declare(strict_types=1);

namespace Integration;

use ApiSutra\Config\ClientConfig;
use ApiSutra\Execution\PoolExecutor;
use ApiSutra\Transport\HttpTransport;
use GuzzleHttp\Promise\CancellationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Integration\First\Client;
use Integration\First\ItemsRequest;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ApiSutra\Resolver\ClientRegistry;
use Generator;

final class AsyncChecks
{
    public static function run(Application $app): void
    {
        $watchers = EventLoop::getIdentifiers();
        $client = $app->make(Client::class);
        check($app->make(ItemsRequest::class)->sendAsync()->wait()->raw()->isSuccess(), 'Async mock/request-first не сработал');
        $source = static function () use ($app): Generator {
            yield $app->make(ItemsRequest::class);
            yield $app->make(ItemsRequest::class);
        };
        $consumed = 0;
        $summary = $client->pool($source())->withResponseHandler(static function () use (&$consumed): void {
            $consumed++;
        })->consume();
        check($summary->successful === 2 && $consumed === 2, 'Consume клиента из контейнера не завершился');
        $cancelled = $client->sendAsync((new ItemsRequest())->withDelay(60000));
        $cancelled->cancel();
        try {
            $cancelled->wait()->raw();
            throw new RuntimeException('Отменённый вызов завершился успешно');
        } catch (CancellationException) {
        }
        $hostError = EventLoop::getErrorHandler();
        try {
            (new PoolExecutor($client, [new ItemsRequest(), new ItemsRequest()]))
                ->withResponseHandler(static fn () => throw new RuntimeException('async-callback-fixture'))
                ->sendAsync()->wait();
            throw new RuntimeException('Ошибка callback потеряна');
        } catch (RuntimeException $error) {
            check($error->getMessage() === 'async-callback-fixture', 'Неверная ошибка callback');
        }
        check(EventLoop::getErrorHandler() === $hostError, 'SDK заменил обработчик ошибок приложения');
        self::download($app);
        EventLoop::run();
        check(EventLoop::getIdentifiers() === $watchers, 'После запроса остались SDK watchers');
    }

    private static function download(Application $app): void
    {
        // Отдельный процесс отдаёт один бинарный ответ настоящему async cURL-транспорту.
        $source = <<<'SOURCE'
        $server = stream_socket_server('tcp://127.0.0.1:0');
        echo stream_socket_get_name($server, false) . "\n"; flush();
        $socket = stream_socket_accept($server, 10);
        if ($socket === false) { exit(1); }
        while (($line = fgets($socket)) !== false && $line !== "\r\n") {}
        $body = str_repeat("binary\0", 65536);
        fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=fixture.bin\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        fclose($socket); fclose($server);
        SOURCE;
        $process = proc_open([PHP_BINARY, '-r', $source], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process), 'Не запущен сервер файла');
        try {
            stream_set_timeout($pipes[1], 5);
            $address = trim((string) fgets($pipes[1]));
            check($address !== '', 'Сервер не сообщил адрес');
            $app->instance('async.download.client', new Client(new ClientConfig(baseUrl: 'http://' . $address), HttpTransport::createDefault()));
            $app->make(ClientRegistry::class)->register($app->make('async.download.client'), 'Integration');
            $kernel = $app->make(Kernel::class);
            $incoming = Request::create('/async-file');
            $response = $kernel->handle($incoming);
            check($response instanceof StreamedResponse, 'Async download не вернул StreamedResponse');
            // Контроллер уже завершён; вывод не должен зависеть от HTTP или задач SDK.
            check(EventLoop::getIdentifiers() === [], 'Download Promise разрешён до окончания HTTP');
            ob_start();
            $response->sendContent();
            $body = ob_get_clean();
            check($body === str_repeat("binary\0", 65536), 'Отложенная выдача повредила файл');
            $kernel->terminate($incoming, $response);
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }
}
