<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Server\Methods;

use JsonException;
use Laravel\Mcp\Enums\ErrorCode;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * Cursor pagination for `tools/list`, owned by LANoMAT.
 *
 * The vendor implementation is correct for well-formed cursors, but treats a
 * cursor it cannot decode as offset 0. A client that drops the base64 padding
 * (`==`) therefore silently receives page 1 again and never sees the rest of
 * the catalogue. This handler normalises base64url/unpadded cursors, rejects a
 * genuinely malformed cursor with -32602 instead of replaying page 1, accepts
 * `per_page` as a number or a numeric string, and omits `nextCursor` on the last page (the MCP
 * schema allows a string or no field, a `null` makes strict clients such as Claude Code reject
 * the whole `tools/list` result).
 */
final class ListTools implements Method
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $request->validate([
            'cursor' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $tools = $context->tools()->values();
        $offset = $this->offsetFromCursor($request->cursor());
        $perPage = $context->perPage($this->requestedPerPage($request->get('per_page')));

        $page = $tools->slice($offset, $perPage)->values()->toArray();
        $nextCursor = $offset + $perPage < $tools->count()
            ? $this->cursorFor($offset + $perPage)
            : null;

        return JsonRpcResponse::result($request->id, array_filter([
            'tools' => $page,
            'nextCursor' => $nextCursor,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function requestedPerPage(mixed $requested): ?int
    {
        if (is_int($requested)) {
            return $requested;
        }

        if (is_string($requested) && ctype_digit($requested)) {
            return (int) $requested;
        }

        return null;
    }

    private function offsetFromCursor(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }

        // Accept base64 and base64url, with or without `=` padding. Some MCP
        // clients hand the cursor through a URL or a log line and lose the
        // padding; decoding that as offset 0 is what replays the first page.
        $normalized = strtr($cursor, '-_', '+/');
        $remainder = strlen($normalized) % 4;

        if ($remainder !== 0) {
            $normalized .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($normalized, true);

        if ($decoded === false) {
            throw $this->invalidCursor();
        }

        try {
            $data = json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalidCursor();
        }

        if (! is_array($data) || ! is_int($data['offset'] ?? null) || $data['offset'] < 0) {
            throw $this->invalidCursor();
        }

        return $data['offset'];
    }

    private function cursorFor(int $offset): string
    {
        return base64_encode((string) json_encode(['offset' => $offset]));
    }

    private function invalidCursor(): JsonRpcException
    {
        return new JsonRpcException(
            'Invalid params: The [cursor] member is not a pagination cursor returned by this server.',
            ErrorCode::INVALID_PARAMS->value,
        );
    }
}
