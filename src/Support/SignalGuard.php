<?php

namespace RaceLab\LaravelRaceLab\Support;

/**
 * Installs temporary CLI signal handlers so cancelled test runs do not leave
 * Race Lab child processes blocked at barriers.
 */
final class SignalGuard
{
    /** @var array<int,mixed> */
    private array $previous = [];
    private bool $installed = false;

    public function install(callable $cleanup): void
    {
        if ($this->installed || PHP_SAPI !== 'cli' || ! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals')) {
            return;
        }

        $signals = [];
        if (defined('SIGINT')) $signals[] = SIGINT;
        if (defined('SIGTERM')) $signals[] = SIGTERM;
        if ($signals === []) return;

        pcntl_async_signals(true);
        foreach ($signals as $signal) {
            $previous = function_exists('pcntl_signal_get_handler') ? pcntl_signal_get_handler($signal) : SIG_DFL;
            $this->previous[$signal] = $previous;

            pcntl_signal($signal, function (int $received) use ($cleanup, $previous): void {
                try { $cleanup($received); } catch (\Throwable) {}
                $this->restoreOne($received, $previous);

                if (is_callable($previous)) {
                    $previous($received);
                    return;
                }

                if ($previous === SIG_IGN) return;
                if (function_exists('posix_kill')) {
                    @posix_kill(getmypid(), $received);
                } else {
                    exit(128 + $received);
                }
            });
        }

        $this->installed = true;
    }

    public function restore(): void
    {
        if (! $this->installed || ! function_exists('pcntl_signal')) return;
        foreach ($this->previous as $signal => $handler) {
            $this->restoreOne($signal, $handler);
        }
        $this->previous = [];
        $this->installed = false;
    }

    private function restoreOne(int $signal, mixed $handler): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal($signal, $handler === false ? SIG_DFL : $handler);
        }
    }
}
