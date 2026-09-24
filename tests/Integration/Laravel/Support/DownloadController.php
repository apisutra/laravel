<?php

declare(strict_types=1);

namespace Integration;

use ApiSutra\Laravel\Contracts\ClientResponseAdapterInterface;
use ApiSutra\Response\ClientResponse;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\HttpFoundation\Response;

final class DownloadController
{
    public function __invoke(Application $app, ClientResponseAdapterInterface $adapter): Response
    {
        $file = (new DownloadRequest())->setClient($app->make('async.download.client'))->sendAsync()->wait()->dataOrFail();
        return $adapter->toResponse(new ClientResponse(200, body: $file));
    }
}
