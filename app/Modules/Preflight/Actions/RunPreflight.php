<?php

namespace App\Modules\Preflight\Actions;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Throwable;

/**
 * Runs every registered {@see HealthCheck} and flattens the results. A check
 * that throws is reported `down` (with its message), never propagated — the
 * ampel must always render.
 */
final class RunPreflight
{
    /** @param iterable<HealthCheck> $checks */
    public function __construct(private readonly iterable $checks) {}

    /**
     * @return list<array{key: string, label: string, status: string, message: string}>
     */
    public function handle(): array
    {
        $out = [];

        foreach ($this->checks as $check) {
            try {
                $result = $check->run();
            } catch (Throwable $e) {
                $result = HealthResult::down($e->getMessage());
            }

            $out[] = [
                'key' => $check->key(),
                'label' => $check->label(),
                'status' => $result->status->value,
                'message' => $result->message,
            ];
        }

        return $out;
    }
}
