<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Actions;

use App\Models\User;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Hosts\Rules\ValidHostAddress;
use App\Modules\Provisioning\Models\Hypervisor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Stores a hypervisor credential. The token secret is a separate parameter
 * (never part of `$data`, so it cannot be mass-assigned or logged next to the
 * descriptive fields) and is stored with the `encrypted` cast.
 */
class RegisterHypervisor
{
    /**
     * @param  array{name: string, base_url: string, node: string, token_id: string, tls_pin?: string|null, jump_host_id?: int|null}  $data
     */
    public function handle(array $data, string $tokenSecret, User $actor): Hypervisor
    {
        Gate::forUser($actor)->authorize('create', Hypervisor::class);

        $errors = [];

        if (! self::validBaseUrl((string) $data['base_url'])) {
            $errors['base_url'] = __('provisioning.errors.invalid_hypervisor');
        }

        if (preg_match('/^[A-Za-z0-9-]+$/', (string) $data['node']) !== 1) {
            $errors['node'] = __('provisioning.errors.invalid_hypervisor');
        }

        if (preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+![A-Za-z0-9._-]+$/', (string) $data['token_id']) !== 1) {
            $errors['token_id'] = __('provisioning.errors.invalid_hypervisor');
        }

        if ($tokenSecret === '' || preg_match('/\s/', $tokenSecret) === 1) {
            $errors['token_secret'] = __('provisioning.errors.invalid_hypervisor');
        }

        $pin = $data['tls_pin'] ?? null;
        if ($pin !== null && $pin !== '' && preg_match('/^sha256\/\/[A-Za-z0-9+\/]{43}=(;sha256\/\/[A-Za-z0-9+\/]{43}=)*$/', $pin) !== 1) {
            $errors['tls_pin'] = __('provisioning.errors.invalid_hypervisor');
        }

        $jump = $data['jump_host_id'] ?? null;
        if ($jump !== null && ! RemoteHost::query()->whereKey($jump)->exists()) {
            $errors['jump_host_id'] = __('provisioning.errors.invalid_hypervisor');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $hypervisor = new Hypervisor([
            'name' => $data['name'],
            'base_url' => rtrim((string) $data['base_url'], '/'),
            'node' => $data['node'],
            'token_id' => $data['token_id'],
            'tls_pin' => $pin === '' ? null : $pin,
            'jump_host_id' => $jump,
        ]);
        $hypervisor->token_secret = $tokenSecret;
        $hypervisor->save();

        return $hypervisor;
    }

    private static function validBaseUrl(string $baseUrl): bool
    {
        $url = parse_url($baseUrl);

        if ($url === false) {
            return false;
        }

        $path = $url['path'] ?? '';

        return ($url['scheme'] ?? '') === 'https'
            && ValidHostAddress::isValid($url['host'] ?? '')
            && ! isset($url['user'])
            && ! isset($url['query'])
            && ($path === '' || $path === '/');
    }
}
