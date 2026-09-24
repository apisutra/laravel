<?php

declare(strict_types=1);

namespace Integration;

use ApiSutra\Laravel\Observability\Events\ExecutionCompleted;
use ApiSutra\Laravel\Observability\ObservationManager;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Integration\First\Client;
use Integration\First\ItemsRequest;
use RuntimeException;

final class ObservationChecks
{
    public static function run(Application $app): void
    {
        $app['config']->set('apisutra.integration.observation.enabled', true);
        $events = [];
        $app['events']->listen(ExecutionCompleted::class, static function ($event) use (&$events): void { $events[] = $event; });
        $app['router']->get('/sdk-observation', static function () use ($app): array {
            $app->make(Client::class)->send(new ItemsRequest());
            return ['ok' => true];
        });
        $kernel = $app->make(Kernel::class);
        $request = Request::create('/sdk-observation');
        $response = $kernel->handle($request);
        self::ensure($response->getStatusCode() === 200 && $events === [], 'HTTP observation must be deferred');
        $kernel->terminate($request, $response);
        self::ensure(count($events) === 1, 'HTTP observation missing or duplicated');
        $manager = $app->make(ObservationManager::class);
        self::ensure($manager->observer() === null, 'HTTP scope leaked');
        foreach ([false, true, false] as $fail) {
            $before = count($events);
            try {
                $app->make(Dispatcher::class)->dispatchSync(new ObservationJob($fail));
            } catch (RuntimeException $exception) {
                if (!$fail || $exception->getMessage() !== 'Observation job failure') {
                    throw $exception;
                }
            }
            self::ensure(count($events) === $before + 1, 'SyncQueue observation missing or duplicated');
            self::ensure($manager->observer() === null, 'Queue scope leaked');
        }
        $app['config']->set('apisutra.integration.observation.enabled', false);
    }

    private static function ensure(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
