<?php

declare(strict_types=1);

namespace App\Modules\Voice\Actions;

use App\Modules\Voice\Models\VoiceClientInstaller;
use Illuminate\Support\Facades\DB;

/**
 * Marks a {@see VoiceClientInstaller} as the current download for its
 * (provider, platform) pair, unsetting any previously-current installer for
 * that same pair inside a transaction — so there is never more than one
 * current installer per (provider, platform). Deliberately not enforced via
 * a partial unique index (stays portable across DB drivers per the task
 * brief); this transaction is the single source of truth for the invariant.
 */
class SetCurrentInstaller
{
    public function handle(VoiceClientInstaller $installer): void
    {
        DB::transaction(function () use ($installer): void {
            VoiceClientInstaller::query()
                ->where('provider', $installer->provider->value)
                ->where('platform', $installer->platform->value)
                ->where('id', '!=', $installer->id)
                ->where('is_current', true)
                ->get()
                ->each(function (VoiceClientInstaller $other): void {
                    $other->forceFill(['is_current' => false])->save();
                });

            $installer->forceFill(['is_current' => true])->save();
        });
    }
}
