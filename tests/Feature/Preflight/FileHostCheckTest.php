<?php

use App\Modules\Preflight\Checks\FileHostCheck;
use App\Modules\Preflight\Support\ServedCertificate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fileHostConfigured(?string $tusEndpoint = 'https://files.test:8443/upload/'): void
{
    config([
        'files.store' => 'host',
        'files.host.url' => 'https://files.test:8443',
        'files.tus.endpoint' => $tusEndpoint,
    ]);
}

function certificateExpiringIn(?int $days): void
{
    app()->instance(ServedCertificate::class, new class($days) extends ServedCertificate
    {
        public function __construct(private readonly ?int $days) {}

        public function expiresAt(string $host, int $port): ?CarbonImmutable
        {
            return $this->days === null ? null : CarbonImmutable::now()->addDays($this->days);
        }
    });
}

it('is skipped while the files are kept locally or no file host url is set', function () {
    config(['files.store' => 'local', 'files.host.url' => 'https://files.test:8443']);
    expect(app(FileHostCheck::class)->run()->status->value)->toBe('skipped');

    config(['files.store' => 'host', 'files.host.url' => null]);
    expect(app(FileHostCheck::class)->run()->status->value)->toBe('skipped');
});

it('is ok when nginx refuses an unsigned link, tusd answers and the certificate is far from expiry', function () {
    fileHostConfigured();
    certificateExpiringIn(60);
    Http::fake([
        'files.test:8443/dl/*' => Http::response('', 403),
        'files.test:8443/upload/*' => Http::response('', 200, ['Tus-Resumable' => '1.0.0']),
    ]);

    expect(app(FileHostCheck::class)->run()->status->value)->toBe('ok');

    Http::assertSent(fn (Request $r) => $r->method() === 'OPTIONS' && str_contains($r->url(), '/upload/'));
});

it('is down when nginx answers an unsigned link with anything but 403', function () {
    fileHostConfigured();
    certificateExpiringIn(60);
    Http::fake([
        'files.test:8443/dl/*' => Http::response('secret', 200),
        'files.test:8443/upload/*' => Http::response('', 200),
    ]);

    expect(app(FileHostCheck::class)->run()->status->value)->toBe('down');
});

it('is down when tusd does not answer as a tus server', function () {
    fileHostConfigured();
    certificateExpiringIn(60);
    Http::fake([
        'files.test:8443/dl/*' => Http::response('', 403),
        'files.test:8443/upload/*' => Http::response('', 502),
    ]);

    expect(app(FileHostCheck::class)->run()->status->value)->toBe('down');
});

it('is down when the file host is not reachable', function () {
    fileHostConfigured();
    certificateExpiringIn(60);
    Http::fake(fn () => throw new ConnectionException('refused'));

    expect(app(FileHostCheck::class)->run()->status->value)->toBe('down');
});

it('only probes the download refusal when no tus endpoint is configured', function () {
    fileHostConfigured(null);
    certificateExpiringIn(60);
    Http::fake(['files.test:8443/dl/*' => Http::response('', 403)]);

    expect(app(FileHostCheck::class)->run()->status->value)->toBe('ok');
    Http::assertSentCount(1);
});

it('warns when the served certificate runs out soon and is down when it is about to expire', function () {
    fileHostConfigured();
    Http::fake([
        'files.test:8443/dl/*' => Http::response('', 403),
        'files.test:8443/upload/*' => Http::response('', 200),
    ]);

    certificateExpiringIn(10);
    $result = app(FileHostCheck::class)->run();
    expect($result->status->value)->toBe('warn')->and($result->message)->toContain('10');

    certificateExpiringIn(2);
    expect(app(FileHostCheck::class)->run()->status->value)->toBe('down');

    certificateExpiringIn(-1);
    expect(app(FileHostCheck::class)->run()->status->value)->toBe('down');
});

it('warns instead of failing when the certificate cannot be read', function () {
    fileHostConfigured();
    certificateExpiringIn(null);
    Http::fake([
        'files.test:8443/dl/*' => Http::response('', 403),
        'files.test:8443/upload/*' => Http::response('', 200),
    ]);

    expect(app(FileHostCheck::class)->run()->status->value)->toBe('warn');
});
