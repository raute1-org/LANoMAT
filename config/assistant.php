<?php

/*
| AI assistant (orga chat). See docs/infra/assistant.md and
| docs/superpowers/specs/2026-11-21-ai-assistant-design.md.
|
| `provider` names an entry of config/ai.php `providers` (anthropic by default,
| ollama as the offline alternative; failover does not cover a network outage,
| so switch the provider explicitly at the venue).
*/
return [
    'provider' => env('ASSISTANT_PROVIDER', 'anthropic'),

    'model' => env('ASSISTANT_MODEL', 'claude-opus-5-5'),

    // Model used when the provider is `ollama` (must support tool calling).
    'ollama_model' => env('ASSISTANT_OLLAMA_MODEL'),

    // Further providers tried on rate-limit/overload/credit errors (not on a network outage).
    'failover' => [],

    // Upper bound of model/tool round trips per prompt.
    'max_steps' => (int) env('ASSISTANT_MAX_STEPS', 12),

    // Requests per minute and user across the chat and decision endpoints.
    'throttle_per_minute' => (int) env('ASSISTANT_THROTTLE_PER_MINUTE', 20),
];
