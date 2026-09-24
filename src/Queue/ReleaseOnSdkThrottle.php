<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Queue;

use ApiSutra\Exceptions\ControlFlow\AdmissionRefused;
use ApiSutra\Exceptions\Configuration\ConfigurationException;
use ApiSutra\Execution\Admission\AdmissionScope;
use Closure;
use Illuminate\Support\Facades\Lang;

/** Opt-in повтор всей job; handle остаётся обычным, режим не меняет конфиг клиента. */
final class ReleaseOnSdkThrottle
{
    /** @param object $job Job с Laravel InteractsWithQueue.
     * @param Closure(object): mixed $next
     */
    public function handle(object $job, Closure $next): void
    {
        if (!is_callable([$job, 'release'])) {
            throw new ConfigurationException(Lang::get('apisutra::queue.release_required'));
        }
        $scope = new AdmissionScope();
        try {
            $scope->run(static fn (): mixed => $next($job));
        } catch (AdmissionRefused $signal) {
            if ($signal->scope !== $scope) {
                throw $signal;
            }
        }
        if ($scope->refused()) {
            $ms = $scope->remainingMs();
            $job->release(intdiv($ms, 1000) + ($ms % 1000 === 0 ? 0 : 1));
        }
    }
}
