<?php

namespace App\Modules\Preflight\Contracts;

use App\Modules\Preflight\Support\HealthResult;

interface HealthCheck
{
    /** Stable machine id, e.g. 'database'. */
    public function key(): string;

    /** German UI label (via lang/de/preflight.php). */
    public function label(): string;

    public function run(): HealthResult;
}
