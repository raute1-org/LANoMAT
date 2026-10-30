<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Support;

use App\Modules\Provisioning\Exceptions\ProvisioningException;

/**
 * A shell script template with `{{PLACEHOLDER}}` markers. Every value is
 * `escapeshellarg`-quoted on substitution, so a value can never break out of
 * its word; unknown or unfilled placeholders are an error. {@see render()} is
 * what dry runs print, {@see command()} what the executor runs (the script is
 * passed to `bash -c` as ONE quoted argument, with `set -euo pipefail`).
 */
final readonly class ProvisioningScript
{
    private function __construct(public string $name, private string $body) {}

    /**
     * @param  array<string, string|int>  $values
     */
    public static function make(string $name, string $template, array $values = []): self
    {
        $used = [];

        $body = (string) preg_replace_callback('/\{\{([A-Z0-9_]+)\}\}/', function (array $m) use ($values, $name, &$used): string {
            if (! array_key_exists($m[1], $values)) {
                throw ProvisioningException::stepFailed("Script [{$name}] has an unfilled placeholder [{$m[1]}].");
            }

            $used[$m[1]] = true;

            return escapeshellarg((string) $values[$m[1]]);
        }, $template);

        $unused = array_diff(array_keys($values), array_keys($used));

        if ($unused !== []) {
            throw ProvisioningException::stepFailed("Script [{$name}] was given unused values [".implode(', ', $unused).'].');
        }

        return new self($name, trim($body)."\n");
    }

    public function render(): string
    {
        return $this->body;
    }

    public function command(): string
    {
        return 'bash -c '.escapeshellarg("set -euo pipefail\n".$this->body);
    }
}
