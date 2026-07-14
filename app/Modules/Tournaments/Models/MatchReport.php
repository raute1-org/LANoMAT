<?php

namespace App\Modules\Tournaments\Models;

use App\Models\User;
use App\Modules\Tournaments\Enums\ReportStatus;
use Database\Factories\MatchReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ReportStatus $status
 */
class MatchReport extends Model
{
    /** @use HasFactory<MatchReportFactory> */
    use HasFactory;

    // status is set only via Actions (later tasks), never client-fillable.
    protected $fillable = [
        'match_id',
        'reported_by',
        'score1',
        'score2',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
        ];
    }

    /** @return BelongsTo<GameMatch, $this> */
    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    protected static function newFactory(): MatchReportFactory
    {
        return MatchReportFactory::new();
    }
}
