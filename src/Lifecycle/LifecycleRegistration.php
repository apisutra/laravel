<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Lifecycle;

use ApiSutra\Laravel\Observability\ObservationManager;
use ApiSutra\Laravel\Observability\Events\ExecutionCompleted;
use ApiSutra\Laravel\Observability\Listeners\LogExecution;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Contracts\EntriesRepository;
use Throwable;
use WeakMap;

/** Регистрация границ без зависимости минимальной установки от Queue/Console. */
final class LifecycleRegistration
{
    /** @var WeakMap<object, true> Завершённая job не удерживает отметку при пропуске JobAttempted. */
    private readonly WeakMap $jobStores;

    public function __construct(private readonly Container $app)
    {
        $this->jobStores = new WeakMap();
    }

    public function register(): void
    {
        $marker = self::class . '.registered';
        if ($this->app->bound($marker)) {
            return;
        }
        $this->app->instance($marker, true);
        $attach = static function ($kernel): void {
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(ObserveRequest::class);
            }
        };
        $this->app->afterResolving(Kernel::class, $attach);
        if ($this->app->resolved(Kernel::class)) {
            $attach($this->app->make(Kernel::class));
        }
        if (!$this->app->bound('events')) {
            return;
        }
        $events = $this->app->make('events');
        $events->listen(JobProcessing::class, fn ($event) => $this->manager()->begin('job:' . spl_object_id($event->job)));
        // Эти слушатели регистрируются до boot Telescope, чтобы итог попал в текущую job.
        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $type) {
            $events->listen($type, function ($event): void {
                $id = spl_object_id($event->job);
                if ($this->manager()->end('job:' . $id)) {
                    $this->jobStores[$event->job] = true;
                }
            });
        }
        $events->listen(JobAttempted::class, function ($event): void {
            $id = spl_object_id($event->job);
            $exported = $this->manager()->end('job:' . $id) || ($this->jobStores[$event->job] ?? false);
            unset($this->jobStores[$event->job]);
            // После повторяемого исключения Telescope не сохраняет entries сам.
            // Запись не включается принудительно, фильтры и штатное хранилище сохранены.
            if (
                $exported && $event->connectionName !== 'sync' && $this->manager()->isIdle()
                && class_exists(Telescope::class) && Telescope::isRecording() && $this->app->bound(EntriesRepository::class)
            ) {
                try {
                    Telescope::store($this->app->make(EntriesRepository::class));
                } catch (Throwable) {
                    // Отказ диагностики не меняет исход попытки job.
                }
            }
        });
        $events->listen(CommandStarting::class, fn ($event) => $this->manager()->begin('command:' . spl_object_id($event->input)));
        $events->listen(CommandFinished::class, fn ($event) => $this->manager()->end('command:' . spl_object_id($event->input)));
        $events->listen(ExecutionCompleted::class, function (ExecutionCompleted $event): void {
            if ($this->app->bound('config') && $this->app->make('config')->get('apisutra.integration.observation.log_channel') !== null) {
                $this->app->make(LogExecution::class)->handle($event);
            }
        });
    }

    private function manager(): ObservationManager
    {
        return $this->app->make(ObservationManager::class);
    }
}
