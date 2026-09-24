<?php

declare(strict_types=1);

use ApiSutra\Config\ClientConfig;
use ApiSutra\Config\HydrationConfig;
use ApiSutra\Laravel\Pagination;
use ApiSutra\Laravel\Tests\Stubs\PaginationItem;
use ApiSutra\Laravel\Tests\Stubs\PaginationRequest;
use ApiSutra\Laravel\Tests\Stubs\TestClient;
use ApiSutra\Testing\MockResponse;
use ApiSutra\Transport\MockTransport;
use Illuminate\Support\LazyCollection;

it('работает без Application, сохраняет DTO и заново обходит источник при потреблении', function (): void {
    $transport = new MockTransport();
    $transport->fake(['*' => function (PaginationRequest $request): MockResponse {
        $page = $request->getContext()->paginationOptions->getPage();
        return MockResponse::success(['data' => [['id' => $page * 10], ['id' => $page * 10 + 1]], 'meta' => ['page' => $page, 'has_more' => $page < 2]]);
    }]);
    $client = new TestClient(new ClientConfig(baseUrl: 'https://items.test', hydration: new HydrationConfig()), $transport);
    $collection = Pagination::collect(new PaginationRequest()->setClient($client)->paginate());
    expect($collection)->toBeInstanceOf(LazyCollection::class)->and($transport->getRecorded())->toBe([]);
    $first = $collection->take(1)->all();
    expect($first[0])->toBeInstanceOf(PaginationItem::class)->and($first[0]->id)->toBe(10)
        ->and($transport->getRecorded())->toHaveCount(1);
    $seen = [];
    $collection->filter(fn (PaginationItem $item) => $item->id % 2 === 0)
        ->map(fn (PaginationItem $item) => $item->id)
        ->each(function (int $id) use (&$seen): void {
            $seen[] = $id;
        });
    expect($seen)->toBe([10, 20])->and($transport->getRecorded())->toHaveCount(3);
});

it('не скрывает ошибку страницы за успешной неполной коллекцией', function (): void {
    $transport = new MockTransport();
    $transport->fake(['*' => MockResponse::sequence([
        MockResponse::success(['data' => [['id' => 7]], 'meta' => ['page' => 1, 'has_more' => true]]),
        MockResponse::make(['error' => 'fixture'], status: 400),
    ])]);
    $client = new TestClient(new ClientConfig(baseUrl: 'https://items.test', hydration: new HydrationConfig(), throwOnErrors: false), $transport);
    $seen = [];
    $failure = null;
    try {
        Pagination::collect(new PaginationRequest()->setClient($client)->paginate())->each(function ($item) use (&$seen): void {
            $seen[] = $item->id;
        });
    } catch (Throwable $exception) {
        $failure = $exception;
    }
    expect($seen)->toBe([7])->and($failure)->not->toBeNull()->and($transport->getRecorded())->toHaveCount(2);
});
