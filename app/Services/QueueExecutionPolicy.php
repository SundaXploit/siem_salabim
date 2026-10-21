<?php

namespace App\Services;

class QueueExecutionPolicy
{
    /**
     * Determine whether every possible driver for a queue connection executes
     * outside the current PHP request.
     */
    public function runsOutsideRequest(?string $connection = null): bool
    {
        $connection ??= config('queue.default');

        if (! is_string($connection) || $connection === '') {
            return false;
        }

        return $this->connectionRunsOutsideRequest($connection, []);
    }

    /** @param array<string, true> $visited */
    private function connectionRunsOutsideRequest(string $connection, array $visited): bool
    {
        if (isset($visited[$connection])) {
            return false;
        }

        $visited[$connection] = true;
        $driver = config("queue.connections.{$connection}.driver");
        if (! is_string($driver) || in_array($driver, ['sync', 'deferred', 'null'], true)) {
            return false;
        }

        if ($driver !== 'failover') {
            return true;
        }

        $connections = config("queue.connections.{$connection}.connections", []);
        if (! is_array($connections) || $connections === []) {
            return false;
        }

        foreach ($connections as $fallback) {
            if (! is_string($fallback)
                || ! $this->connectionRunsOutsideRequest($fallback, $visited)) {
                return false;
            }
        }

        return true;
    }
}
