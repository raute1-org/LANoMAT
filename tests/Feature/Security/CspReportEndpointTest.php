<?php

beforeEach(function () {
    $this->cspLog = sys_get_temp_dir().'/csp-test-'.uniqid().'.log';
    config(['logging.channels.csp' => ['driver' => 'single', 'path' => $this->cspLog, 'level' => 'debug']]);
    app('log')->forgetChannel('csp');
});

afterEach(function () {
    @unlink($this->cspLog);
});

function cspLog(): string
{
    return is_file(test()->cspLog) ? (string) file_get_contents(test()->cspLog) : '';
}

it('logs one compact line for a legacy report-uri payload', function () {
    $this->postJson('/csp-report', ['csp-report' => [
        'document-uri' => 'https://lan.example.org/events/spring?token=secret#frag',
        'violated-directive' => 'script-src-elem',
        'effective-directive' => 'script-src-elem',
        'blocked-uri' => 'https://evil.example.net/x.js?leak=1',
        'source-file' => 'https://lan.example.org/build/app.js',
        'line-number' => 12,
        'disposition' => 'report',
    ]], ['Content-Type' => 'application/csp-report'])->assertNoContent();

    $log = cspLog();
    expect(substr_count($log, "\n"))->toBe(1)
        ->and($log)->toContain('script-src-elem')
        ->toContain('https://evil.example.net/x.js')
        ->toContain('/events/spring')
        ->not->toContain('secret')
        ->not->toContain('leak=1')
        ->not->toContain('#frag');
});

it('accepts the Reporting API format with several reports', function () {
    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], json_encode([
        ['type' => 'csp-violation', 'url' => 'https://lan.example.org/screen/x', 'body' => [
            'documentURL' => 'https://lan.example.org/screen/x',
            'effectiveDirective' => 'img-src',
            'blockedURL' => 'inline',
            'disposition' => 'report',
        ]],
        ['type' => 'deprecation', 'body' => ['id' => 'x']],
        ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'style-src-elem', 'blockedURL' => 'eval']],
    ]))->assertNoContent();

    $log = cspLog();
    expect(substr_count($log, "\n"))->toBe(2)
        ->and($log)->toContain('img-src')->toContain('style-src-elem');
});

it('rejects an oversized body and logs nothing', function () {
    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], str_repeat('a', 20000))
        ->assertStatus(413);

    expect(cspLog())->toBe('');
});

it('rejects garbage without logging or failing', function () {
    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], 'not json {{')
        ->assertStatus(400);
    $this->postJson('/csp-report', ['unexpected' => 'shape'])->assertStatus(400);
    $this->postJson('/csp-report', [])->assertStatus(400);

    expect(cspLog())->toBe('');
});

it('needs no session, csrf token or login and sets no cookies', function () {
    $response = $this->postJson('/csp-report', ['csp-report' => ['violated-directive' => 'img-src', 'blocked-uri' => 'inline']]);

    $response->assertNoContent();
    expect($response->headers->getCookies())->toBeEmpty();
});

it('is throttled', function () {
    $status = 204;
    for ($i = 0; $i < 70 && $status === 204; $i++) {
        $status = $this->postJson('/csp-report', ['csp-report' => ['violated-directive' => 'img-src']])->status();
    }

    expect($status)->toBe(429);
});

it('does not log client addresses, user agents or cookies', function () {
    $this->withHeaders(['User-Agent' => 'SecretAgent/1.0'])
        ->postJson('/csp-report', ['csp-report' => ['violated-directive' => 'img-src', 'blocked-uri' => 'inline']], ['REMOTE_ADDR' => '203.0.113.9'])
        ->assertNoContent();

    expect(cspLog())->not->toContain('SecretAgent')->not->toContain('203.0.113.9');
});
