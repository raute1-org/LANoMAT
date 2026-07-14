<?php

namespace App\Modules\Discord;

use App\Modules\Discord\Contracts\DiscordClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class HttpDiscordClient implements DiscordClient
{
    private string $base = 'https://discord.com/api/v10';

    public function __construct(private readonly string $botToken) {}

    public function sendMessage(string $channelId, string $content, array $embeds = []): void
    {
        $this->http()->post("{$this->base}/channels/{$channelId}/messages", $this->withoutNulls([
            'content' => $content,
            'embeds' => $embeds ?: null,
        ]))->throw();
    }

    public function createChannel(string $guildId, string $name, ?string $parentId = null): string
    {
        $response = $this->http()->post("{$this->base}/guilds/{$guildId}/channels", $this->withoutNulls([
            'name' => $name,
            'type' => 0, // text
            'parent_id' => $parentId,
        ]))->throw();

        return (string) $response->json('id');
    }

    public function deleteChannel(string $channelId): void
    {
        $this->http()->delete("{$this->base}/channels/{$channelId}")->throw();
    }

    public function sendDm(string $userDiscordId, string $content): void
    {
        $channel = $this->http()
            ->post("{$this->base}/users/@me/channels", ['recipient_id' => $userDiscordId])
            ->throw()->json('id');

        $this->sendMessage((string) $channel, $content);
    }

    public function upsertPermissionOverwrites(string $channelId, array $overwrites): void
    {
        foreach ($overwrites as $overwrite) {
            $this->http()->put(
                "{$this->base}/channels/{$channelId}/permissions/{$overwrite['id']}",
                $overwrite,
            )->throw();
        }
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => "Bot {$this->botToken}"])
            ->acceptJson()
            ->retry(3, function (int $attempt, Throwable $exception) {
                return $this->retryDelayMilliseconds($exception);
            }, throw: false);
    }

    /**
     * Backoff between retries. Discord's 429 responses carry a Retry-After
     * header (seconds) telling us exactly how long to wait before the rate
     * limit clears; honor it when present, otherwise fall back to a fixed
     * short delay for transient errors (5xx, connection issues).
     */
    private function retryDelayMilliseconds(Throwable $exception): int
    {
        if ($exception instanceof RequestException
            && $exception->response->status() === 429) {
            $retryAfter = $exception->response->header('Retry-After');

            if (is_numeric($retryAfter)) {
                return (int) round(((float) $retryAfter) * 1000);
            }
        }

        return 100;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutNulls(array $payload): array
    {
        return array_filter($payload, fn ($value) => $value !== null);
    }
}
