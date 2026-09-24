<?php

declare(strict_types=1);

namespace Integration;

use Integration\First\ItemsRequest;
use Integration\First\Client;
use RuntimeException;

final readonly class ProbeJob
{
    public function __construct(private string $limit)
    {
    }

    public function handle(ItemsRequest $request, Client $client): void
    {
        if ($request->limit !== '20' || $request->getOptions()->getTimeoutOverride() !== null) {
            throw new RuntimeException('Состояние запроса протекло между заданиями');
        }
        $request->limit = $this->limit;
        $execution = $request->withTimeout((int) $this->limit);
        if (!$execution->sendAsync()->wait()->raw()->isSuccess()) {
            throw new RuntimeException('SDK-запрос задания не выполнен');
        }
        $summary = $client->pool([$execution], 1)->consumeAsync()->wait();
        if ($summary->successful !== 1) {
            throw new RuntimeException('Обработка pool задания не завершилась');
        }
    }
}
