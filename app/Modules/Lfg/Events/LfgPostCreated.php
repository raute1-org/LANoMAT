<?php

namespace App\Modules\Lfg\Events;

use App\Modules\Lfg\Models\LfgPost;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after a new `LfgPost` has been created. Consumed by the Discord
 * module (Task 17) to announce the post; declared here so `CreateLfgPost`
 * has something to dispatch without coupling to that listener.
 *
 * Implements {@see ShouldDispatchAfterCommit} so listeners never react to a
 * post creation that is later rolled back.
 */
class LfgPostCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly LfgPost $post,
    ) {}
}
