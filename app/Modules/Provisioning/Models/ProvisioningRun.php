<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Models;

use App\Models\User;
use App\Modules\Provisioning\Enums\RunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $plan
 * @property string $target_key
 * @property int|null $hypervisor_id
 * @property int|null $requested_by
 * @property RunStatus $status
 * @property array{rollback_failed_step: bool, rollback_all: bool} $options
 * @property array{config: array<string, mixed>, state: array<string, mixed>} $context
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class ProvisioningRun extends Model
{
    // Everything is written by the runner/start action, never from request data.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'options' => 'array',
            'context' => 'encrypted:array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return HasMany<ProvisioningRunStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ProvisioningRunStep::class)->orderBy('position');
    }

    /** @return BelongsTo<Hypervisor, $this> */
    public function hypervisor(): BelongsTo
    {
        return $this->belongsTo(Hypervisor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
