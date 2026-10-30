<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives CSP violation reports from browsers (`report-uri` sends
 * `application/csp-report` with a `csp-report` object; `report-to` sends the
 * Reporting API's `application/reports+json` array) and logs one compact line
 * per violation to the `csp` channel.
 *
 * Deliberately anonymous and cookie-less (no session, no CSRF: browsers send
 * these on their own), size-limited and throttled. Privacy: only directive,
 * blocked resource, document path, source file, line and disposition are
 * kept — query strings and fragments are stripped from every URL and neither
 * client IP, user agent nor cookies are logged.
 */
class CspReportController extends Controller
{
    private const MAX_BYTES = 8192;

    public function __invoke(Request $request): Response|JsonResponse
    {
        $length = $request->headers->get('Content-Length');

        if (($length !== null && (int) $length > self::MAX_BYTES)) {
            return response('', 413);
        }

        $raw = $request->getContent();

        if (strlen($raw) > self::MAX_BYTES) {
            return response('', 413);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response('', 400);
        }

        $reports = $this->extract($payload);

        if ($reports === []) {
            return response('', 400);
        }

        foreach (array_slice($reports, 0, 10) as $report) {
            Log::channel('csp')->info($this->line($report));
        }

        return response('', 204);
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extract(array $payload): array
    {
        // Legacy report-uri: {"csp-report": {...}}
        if (isset($payload['csp-report']) && is_array($payload['csp-report'])) {
            return [$this->normalizeLegacy($payload['csp-report'])];
        }

        // Reporting API: [{"type": "csp-violation", "body": {...}}, ...]
        if (array_is_list($payload)) {
            $reports = [];

            foreach ($payload as $item) {
                if (is_array($item) && ($item['type'] ?? null) === 'csp-violation' && is_array($item['body'] ?? null)) {
                    $reports[] = $this->normalizeModern($item['body']);
                }
            }

            return $reports;
        }

        return [];
    }

    /**
     * @param  array<mixed>  $body
     * @return array<string, mixed>
     */
    private function normalizeLegacy(array $body): array
    {
        return [
            'directive' => $body['effective-directive'] ?? $body['violated-directive'] ?? null,
            'blocked' => $body['blocked-uri'] ?? null,
            'document' => $body['document-uri'] ?? null,
            'source' => $body['source-file'] ?? null,
            'line' => $body['line-number'] ?? null,
            'disposition' => $body['disposition'] ?? null,
        ];
    }

    /**
     * @param  array<mixed>  $body
     * @return array<string, mixed>
     */
    private function normalizeModern(array $body): array
    {
        return [
            'directive' => $body['effectiveDirective'] ?? $body['violatedDirective'] ?? null,
            'blocked' => $body['blockedURL'] ?? null,
            'document' => $body['documentURL'] ?? null,
            'source' => $body['sourceFile'] ?? null,
            'line' => $body['lineNumber'] ?? null,
            'disposition' => $body['disposition'] ?? null,
        ];
    }

    /** @param  array<string, mixed>  $report */
    private function line(array $report): string
    {
        $parts = [
            'directive='.$this->token($report['directive'] ?? null),
            'blocked='.$this->url($report['blocked'] ?? null),
            'document='.$this->url($report['document'] ?? null, pathOnly: true),
            'source='.$this->url($report['source'] ?? null, pathOnly: true),
            'line='.(is_numeric($report['line'] ?? null) ? (int) $report['line'] : '-'),
            'disposition='.$this->token($report['disposition'] ?? null),
        ];

        return 'csp-violation '.implode(' ', $parts);
    }

    private function token(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '-';
        }

        return substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $value) ?? '', 0, 60) ?: '-';
    }

    /**
     * Keywords (`inline`, `eval`, `data`, ...) pass through; URLs lose query
     * and fragment, and for document/source URLs also the host.
     */
    private function url(mixed $value, bool $pathOnly = false): string
    {
        if (! is_string($value) || $value === '') {
            return '-';
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'])) {
            return substr(preg_replace('/[^A-Za-z0-9_\-:.\/]/', '', $value) ?? '', 0, 60) ?: '-';
        }

        if (in_array($parts['scheme'], ['data', 'blob', 'about', 'javascript'], true)) {
            return $parts['scheme'];
        }

        $path = $parts['path'] ?? '/';
        $out = $pathOnly
            ? $path
            : $parts['scheme'].'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '').$path;

        return substr(preg_replace('/[^\x21-\x7E]/', '', $out) ?? '', 0, 200);
    }
}
