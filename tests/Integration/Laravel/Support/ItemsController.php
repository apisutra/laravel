<?php

declare(strict_types=1);

namespace Integration;

use Illuminate\Http\JsonResponse;
use Integration\First\ItemsRequest;

final class ItemsController
{
    public function __invoke(ItemsRequest $request): JsonResponse
    {
        return new JsonResponse(['limit' => $request->limit, 'success' => $request->send()->raw()->isSuccess()]);
    }
}
