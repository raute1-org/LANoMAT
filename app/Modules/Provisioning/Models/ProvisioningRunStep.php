<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Models;

use App\Modules\Provisioning\Enums\StepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $provisioning_run_id
 * @property int $position
 * @property string $step
 * @property StepStatus $status
 * @property bool $skipped
 * @property string|null $log redacted
 * @property string|null $error redacted
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class ProvisioningRunStep extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => StepStatus::class,
            'skipped' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProvisioningRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ProvisioningRun::class, 'provisioning_run_id');
    }
}
