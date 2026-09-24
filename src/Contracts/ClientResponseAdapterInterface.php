<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Contracts;

use ApiSutra\Response\ClientResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Контракт адаптера клиентского ответа для Laravel.
 */
interface ClientResponseAdapterInterface
{
    public function toResponse(ClientResponse $response): Response;
}
