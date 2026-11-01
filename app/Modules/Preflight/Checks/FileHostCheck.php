<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use App\Modules\Preflight\Support\ServedCertificate;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The file host (nginx and tusd on the LanCache machine) behind `FILES_STORE=host`. Self-contained:
 * reads only config, so Preflight keeps no dependency on the Files module.
 *
 * Three things are checked: nginx refuses an unsigned download link (proves it is up and that the
 * signature check is active, the same probe as its container health check), tusd answers a tus
 * `OPTIONS` through nginx, and the certificate it serves is not about to expire. The certificate is
 * a copy that has to be renewed by `docker/file-host/sync-certs.py`, so a stale copy is the likeliest
 * way for this host to break on the day of an event. Whether a 15 GB upload really survives a
 * dropped connection is a manual check, see docs/infra/file-host.md.
 */
class FileHostCheck implements HealthCheck
{
    /** Days left at which the certificate counts as a warning. */
    private const WARN_DAYS = 14;

    /** Days left at which it counts as down: too late to fix it in a calm moment. */
    private const DOWN_DAYS = 3;

    public function __construct(private readonly ServedCertificate $certificate) {}

    public function key(): string
    {
        return 'file_host';
    }

    public function label(): string
    {
        return __('preflight.checks.file_host');
    }

    public function run(): HealthResult
    {
        $base = (string) config('files.host.url');

        if (config('files.store') !== 'host' || blank($base)) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        try {
            $unsigned = Http::timeout(3)->withoutRedirecting()->get(rtrim($base, '/').'/dl/preflight');

            if ($unsigned->status() !== 403) {
                return HealthResult::down(__('preflight.messages.file_host_unsigned', ['status' => $unsigned->status()]));
            }

            $tus = (string) config('files.tus.endpoint');

            if (str_starts_with($tus, 'http')) {
                $options = Http::timeout(3)->withoutRedirecting()->send('OPTIONS', $tus);

                if (! $options->successful()) {
                    return HealthResult::down(__('preflight.messages.file_host_tus', ['status' => $options->status()]));
                }
            }
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }

        return $this->certificateResult($base);
    }

    private function certificateResult(string $base): HealthResult
    {
        $parts = parse_url($base);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? 443);

        $expiresAt = $host === '' ? null : $this->certificate->expiresAt($host, $port);

        if ($expiresAt === null) {
            return HealthResult::warn(__('preflight.messages.file_host_cert_unreadable'));
        }

        $days = (int) ceil(now()->diffInDays($expiresAt, false));

        return match (true) {
            $days <= self::DOWN_DAYS => HealthResult::down(__('preflight.messages.file_host_cert_expiring', ['days' => $days])),
            $days <= self::WARN_DAYS => HealthResult::warn(__('preflight.messages.file_host_cert_expiring', ['days' => $days])),
            default => HealthResult::ok(),
        };
    }
}
