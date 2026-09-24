<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Lifecycle;

use ApiSutra\Laravel\Observability\ObservationManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ObserveRequest
{
    public function __construct(private ObservationManager $observations)
    {
    }

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $this->observations->begin('http:' . spl_object_id($request));
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->observations->end('http:' . spl_object_id($request));
    }
}
