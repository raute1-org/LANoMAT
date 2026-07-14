<?php

namespace App\Modules\Discord\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Delivers the final content for a deferred interaction response
 * (INTERACTION_RESPONSE type 5) by PATCHing the interaction's follow-up
 * webhook, once the slower query/action behind it has finished — outside
 * Discord's 3-second acknowledgement deadline.
 *
 * @see https://docs.discord.com/developers/interactions/receiving-and-responding#followup-messages
 */
class SendFollowupJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $applicationId,
        public readonly string $token,
        public readonly string $content,
    ) {}

    public function handle(): void
    {
        Http::acceptJson()
            ->patch("https://discord.com/api/v10/webhooks/{$this->applicationId}/{$this->token}/messages/@original", [
                'content' => $this->content,
            ])
            ->throw();
    }
}
