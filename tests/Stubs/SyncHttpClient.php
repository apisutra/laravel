<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class SyncHttpClient implements ClientInterface
{
    public int $calls = 0;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls++;
        return new Response(204);
    }
}
