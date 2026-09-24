<?php

declare(strict_types=1);

use ApiSutra\Laravel\ClientResponseAdapter;
use ApiSutra\Response\ClientResponse;
use ApiSutra\VO\Files\FileResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

describe('ClientResponseAdapter (Laravel)', function () {
    it('возвращает JsonResponse для массива', function () {
        $adapter = new ClientResponseAdapter();
        $clientResponse = new ClientResponse(
            status: 201,
            headers: ['X-Test' => 'ok'],
            body: ['ok' => true],
        );

        $response = $adapter->toResponse($clientResponse);

        expect($response)->toBeInstanceOf(JsonResponse::class)
            ->and($response->getStatusCode())->toBe(201)
            ->and($response->headers->get('X-Test'))->toBe('ok');
        $payload = json_decode((string) $response->getContent(), true);
        expect($payload)->toBe(['ok' => true]);
    });

    it('возвращает Response для строки', function () {
        $adapter = new ClientResponseAdapter();
        $clientResponse = new ClientResponse(
            status: 202,
            headers: ['X-Mode' => 'text'],
            body: 'hello',
        );

        $response = $adapter->toResponse($clientResponse);

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response->getStatusCode())->toBe(202)
            ->and($response->headers->get('X-Mode'))->toBe('text')
            ->and($response->getContent())->toBe('hello');
    });

    it('возвращает StreamedResponse для FileResponse', function () {
        $adapter = new ClientResponseAdapter();
        $stream = Utils::streamFor('file-content');
        $file = new FileResponse(
            stream: $stream,
            filename: 'report.txt',
            mimeType: 'text/plain',
            size: 12,
        );

        $clientResponse = new ClientResponse(
            status: 200,
            headers: ['X-Test' => 'file'],
            body: $file,
        );

        $response = $adapter->toResponse($clientResponse);

        expect($response)->toBeInstanceOf(StreamedResponse::class)
            ->and($response->getStatusCode())->toBe(200)
            ->and($response->headers->get('X-Test'))->toBe('file')
            ->and($response->headers->get('Content-Type'))->toBe('text/plain');
        $disposition = $response->headers->get('Content-Disposition');
        expect($disposition)->not->toBeNull()
            ->and(str_contains((string)$disposition, 'report.txt'))->toBeTrue()
            ->and($response->headers->get('Content-Length'))->toBe('12');
    });
});

it('формирует безопасное имя скачивания и выдаёт содержимое потока', function (string $filename, string $expected, string $fallback): void {
    $file = new FileResponse(stream: Utils::streamFor('download'), filename: $filename);
    try {
        $response = (new ClientResponseAdapter())->toResponse(new ClientResponse(status: 200, body: $file));
        $header = $response->headers->get('Content-Disposition');
        $parameters = HeaderUtils::combine(HeaderUtils::split($header, ';='));
        $receivedName = isset($parameters['filename*'])
            ? rawurldecode(substr($parameters['filename*'], strlen("utf-8''")))
            : $parameters['filename'];
        expect($receivedName)->toBe($expected)
            ->and($parameters['filename'])->toBe($fallback)
            ->and($parameters['attachment'])->toBeTrue()
            ->and(preg_match('/[^\x20-\x7E]/', $header))->toBe(0);
        ob_start();
        try {
            $response->sendContent();
            expect(ob_get_contents())->toBe('download');
        } finally {
            ob_end_clean();
        }
    } finally {
        $file->close();
    }
})->with([
    ['report"2026.csv', 'report"2026.csv', 'report"2026.csv'],
    ['report;2026.csv', 'report;2026.csv', 'report;2026.csv'],
    ['отчёт.csv', 'отчёт.csv', '_____.csv'],
    ['100%.csv', '100%.csv', '100_.csv'],
    ['reports/2026.pdf', 'reports_2026.pdf', 'reports_2026.pdf'],
    ['C:\\tmp\\a.pdf', 'C:_tmp_a.pdf', 'C:_tmp_a.pdf'],
]);

it('сохраняет явно заданный Content-Disposition без учёта регистра', function (): void {
    $file = new FileResponse(stream: Utils::streamFor('download'), filename: 'unused/name');
    try {
        $response = (new ClientResponseAdapter())->toResponse(new ClientResponse(
            status: 200,
            headers: ['content-disposition' => 'inline; filename="custom.txt"'],
            body: $file,
        ));
        expect($response->headers->get('Content-Disposition'))->toBe('inline; filename="custom.txt"');
    } finally {
        $file->close();
    }
});
