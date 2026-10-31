<?php

use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Support\ProvisioningScript;
use App\Modules\Provisioning\Support\Redactor;

/** Built at runtime so no PEM-looking literal sits in the repository (secret scanners). */
function pem(string $type, string $body): string
{
    return '-----'.'BEGIN '.$type.' PRIVATE KEY-----'."\n".$body."\n".'-----'.'END '.$type.' PRIVATE KEY-----';
}

it('quotes every substituted value so it cannot leave its word', function () {
    $script = ProvisioningScript::make('t', 'echo {{A}} {{B}}', ['A' => "a'; rm -rf /; echo '", 'B' => 7]);

    expect($script->render())->toBe("echo 'a'\\''; rm -rf /; echo '\\''' '7'\n");
    // Run for real: the hostile value is printed verbatim, nothing is executed.
    $out = shell_exec('bash -c '.escapeshellarg($script->render()));
    expect($out)->toBe("a'; rm -rf /; echo ' 7\n");
});

it('wraps the script as one bash -c argument with strict mode', function () {
    $command = ProvisioningScript::make('t', 'true')->command();

    expect($command)->toBe("bash -c 'set -euo pipefail\ntrue\n'");
});

it('fails on unfilled placeholders and unused values', function () {
    expect(fn () => ProvisioningScript::make('t', 'echo {{A}}'))->toThrow(ProvisioningException::class, 'unfilled');
    expect(fn () => ProvisioningScript::make('t', 'echo', ['A' => 'x']))->toThrow(ProvisioningException::class, 'unused');
});

it('redacts secrets, private keys, API tokens and credential assignments', function () {
    $text = "using PVEAPIToken=user@pve!t=abcdef-secret now\n"
        .pem('OPENSSH', 'AAAAB3Nza')."\n"
        ."db_password = hunter22 and token: abc123def\n"
        .'value known-secret-value here';

    $clean = Redactor::redact($text, ['known-secret-value', 'abc']);

    expect($clean)->not->toContain('abcdef-secret')->not->toContain('AAAAB3Nza')->not->toContain('hunter22')
        ->not->toContain('abc123def')->not->toContain('known-secret-value')
        ->and($clean)->toContain('[redacted]');
});

it('redacts a truncated private key block too and truncates long logs from the front', function () {
    expect(Redactor::redact('x '.substr(pem('RSA', 'MIIabc'), 0, 45)))->not->toContain('MIIabc');
    expect(Redactor::truncate(str_repeat('a', 50).'END', 10))->toBe('[...] aaaaaaaEND');
});
