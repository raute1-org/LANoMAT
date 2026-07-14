<?php

namespace App\Modules\Discord\Http;

use App\Modules\Discord\Interactions\CommandRouter;
use App\Modules\Discord\Interactions\InteractionResponseType;
use App\Modules\Discord\Interactions\InteractionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InteractionsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = (array) $request->json()->all();
        $type = InteractionType::tryFrom((int) ($payload['type'] ?? 0));

        if ($type === InteractionType::Ping) {
            return response()->json(['type' => InteractionResponseType::Pong->value]);
        }

        if ($type === InteractionType::ApplicationCommand) {
            return response()->json(CommandRouter::dispatch($payload));
        }

        // Other interaction types (message components, autocomplete, modals)
        // are not wired up yet; deferring is a safe default acknowledgement.
        return response()->json(['type' => InteractionResponseType::DeferredChannelMessageWithSource->value]);
    }
}
