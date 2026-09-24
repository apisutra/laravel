<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Observability;

use ApiSutra\Diagnostics\RedactionPolicy;
use ApiSutra\Execution\ExecutionLocal;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Log\Context\Repository as ContextRepository;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;
use WeakMap;

/** Области приложения; события отправляются только на явных границах lifecycle. */
final class ObservationManager
{
    /** @var ExecutionLocal<ObservationArea> */
    private ExecutionLocal $current;
    /** @var array<string, array{ObservationArea, Closure(): void}> */
    private array $scopes = [];
    /** @var WeakMap<ObservationArea, true> */
    private WeakMap $areas;

    public function __construct(private readonly Container $app)
    {
        $this->current = new ExecutionLocal();
        $this->areas = new WeakMap();
    }

    public function belongsTo(Container $app): bool
    {
        return $this->app === $app;
    }

    public function begin(string $key): void
    {
        if (!$this->enabled() || isset($this->scopes[$key])) {
            return;
        }
        $area = new ObservationArea($this->options());
        $this->areas[$area] = true;
        $this->scopes[$key] = [$area, $this->current->enter($area)];
    }

    public function end(string $key): bool
    {
        $scope = $this->scopes[$key] ?? null;
        if ($scope === null) {
            return false;
        }
        [$area, $leave] = $scope;
        try {
            $pending = $area->hasPending();
            return $this->isIdle() && $area->flush($this->dispatch(...)) && $pending;
        } finally {
            unset($this->scopes[$key]);
            $area->close();
            $leave();
        }
    }

    public function close(): void
    {
        foreach (array_reverse(array_keys($this->scopes)) as $key) {
            $this->end($key);
        }
    }

    /** false означает отсутствие безопасной области; loop не запускается. */
    public function flush(): bool
    {
        return $this->isIdle() && ($this->current->get()?->flush($this->dispatch(...)) ?? false);
    }

    public function isIdle(): bool
    {
        foreach ($this->areas as $area => $_) {
            if ($area->active !== 0) {
                return false;
            }
        }
        return true;
    }

    public function observer(): ?ExecutionObserver
    {
        $area = $this->current->get();
        if (!$this->enabled() || $area === null || $area->closed) {
            return null;
        }
        $correlation = [];
        if ($this->app->bound(ContextRepository::class)) {
            $context = $this->app->make(ContextRepository::class);
            foreach (array_slice($area->options->correlationKeys, 0, 16) as $key) {
                $value = $context->get($key);
                if (is_scalar($value) || $value === null) {
                    $correlation[substr($key, 0, 128)] = is_string($value) ? substr($value, 0, 256) : $value;
                }
            }
        }
        return new ExecutionObserver($area, new RedactionPolicy()->context($correlation));
    }

    private function enabled(): bool
    {
        $enabled = $this->setting('enabled');
        if (is_bool($enabled)) {
            return $enabled;
        }
        if ($this->app->bound('env') && $this->app->make('env') === 'testing') {
            return false;
        }
        return class_exists(Telescope::class) && (Telescope::isRecording()
            || (method_exists($this->app, 'getProvider') && $this->app->getProvider(TelescopeServiceProvider::class) !== null
                && $this->app->make('config')->get('telescope.enabled', true)));
    }

    private function options(): ObservationOptions
    {
        return new ObservationOptions(
            maxSnapshots: (int) $this->setting('max_snapshots', 256),
            maxBytes: (int) $this->setting('max_bytes', 262144),
            perWindow: (int) $this->setting('per_window', 200),
            windowSeconds: (int) $this->setting('window_seconds', 60),
            details: (bool) $this->setting('details', false),
            correlationKeys: $this->setting('correlation_keys', ['request_id', 'trace_id']),
        );
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return $this->app->bound('config') ? $this->app->make('config')->get('apisutra.integration.observation.' . $key, $default) : $default;
    }

    private function dispatch(object $event): void
    {
        if ($this->app->bound('events')) {
            $this->app->make('events')->dispatch($event);
        }
    }
}
