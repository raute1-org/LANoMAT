<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Models;

use App\Models\User;
use App\Modules\Assistant\Enums\AssistantActionStatus;
use Database\Factories\AssistantActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit row of one write proposal of the assistant (one per tool call).
 *
 * `status` and `secret` are never mass-assignable: only the audit log service
 * moves a row through its lifecycle. `secret` holds a card-entered password
 * (encrypted) from the approval until the tool ran, then it is nulled.
 *
 * @property int $id
 * @property int $user_id
 * @property string $conversation_id
 * @property string|null $tool_call_id
 * @property string $tool
 * @property array<string, mixed> $arguments
 * @property string $arguments_hash
 * @property AssistantActionStatus $status
 * @property string|null $secret
 * @property string|null $result_summary
 * @property string|null $error
 */
class AssistantAction extends Model
{
    /** @use HasFactory<AssistantActionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'conversation_id', 'tool_call_id', 'tool', 'arguments', 'arguments_hash',
        'result_summary', 'error',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'proposed'];

    /** @var list<string> */
    protected $hidden = ['secret'];

    protected static function newFactory(): AssistantActionFactory
    {
        return AssistantActionFactory::new();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'status' => AssistantActionStatus::class,
            'secret' => 'encrypted',
        ];
    }
}
