<?php

declare(strict_types=1);

namespace App\Modules\Lancache\Support;

use App\Modules\Lancache\Domain\LancacheConfig;

/**
 * The shell commands of the compose-based LanCache setup
 * (docs/infra/proxmox-test-vm-lancache.md, step 3), built from the typed
 * {@see LancacheConfig}. Pure strings: nothing runs here. Every dynamic value
 * is `escapeshellarg`-quoted (the config is additionally pattern-validated).
 * The SSH user is not root, so privileged commands use sudo.
 */
final class LancacheStack
{
    public const string ENV_FILE = LancacheConfig::STACK_DIR.'/.env';

    public const string COMPOSE_FILE = LancacheConfig::STACK_DIR.'/docker-compose.yml';

    public static function tmpPath(string $target): string
    {
        return '/tmp/lanomat-'.substr(hash('sha256', $target), 0, 12);
    }

    public static function prepare(LancacheConfig $c): string
    {
        $root = escapeshellarg($c->cacheRoot);

        return 'sudo mkdir -p '.escapeshellarg(LancacheConfig::STACK_DIR)." {$root}/cache {$root}/logs";
    }

    /** Moves an uploaded temp file into place (root-owned). */
    public static function install(string $target, string $mode): string
    {
        $tmp = escapeshellarg(self::tmpPath($target));

        return 'sudo install -D -m '.escapeshellarg($mode)." {$tmp} ".escapeshellarg($target)." && rm -f {$tmp}";
    }

    /** The upstream resolver must answer before the DNS container is pointed at it. */
    public static function resolverCheck(LancacheConfig $c): string
    {
        return 'dig +time=3 +tries=1 @'.escapeshellarg($c->upstreamDns[0]).' example.com +short | grep -q .';
    }

    public static function up(): string
    {
        return 'cd '.escapeshellarg(LancacheConfig::STACK_DIR).' && sudo docker compose config -q && sudo docker compose pull && sudo docker compose up -d';
    }

    public static function down(): string
    {
        return 'cd '.escapeshellarg(LancacheConfig::STACK_DIR).' && sudo docker compose down';
    }

    /** Exit 0 when files and running containers already match the config. */
    public static function satisfied(LancacheConfig $c): string
    {
        $env = escapeshellarg(hash('sha256', $c->envFile()));
        $compose = escapeshellarg(hash('sha256', $c->composeFile()));
        $dir = escapeshellarg(LancacheConfig::STACK_DIR);

        return implode(' && ', [
            '[ "$(sudo sha256sum < '.escapeshellarg(self::ENV_FILE)." | cut -d' ' -f1)\" = {$env} ]",
            '[ "$(sudo sha256sum < '.escapeshellarg(self::COMPOSE_FILE)." | cut -d' ' -f1)\" = {$compose} ]",
            "[ \"\$(cd {$dir} && sudo docker compose ps --status running -q | wc -l)\" = 2 ]",
        ]);
    }

    /** Exit 0 when the cache IP serves 53, 80 and 443 and no other address (besides the local resolver stub) does. */
    public static function portsBoundOnlyToCacheIp(LancacheConfig $c): string
    {
        $ip = escapeshellarg($c->ip.':');
        $listeners = "ss -H -tuln | awk '{print \$5}' | grep -E ':(53|80|443)\$'";

        return "{$listeners} | grep -qF -- {$ip} && ! {$listeners} | grep -vE '^127\\.0\\.0\\.' | grep -vqF -- {$ip}";
    }

    /** Prints the HTTP status of the heartbeat; 204 when the cache answers. */
    public static function heartbeat(LancacheConfig $c): string
    {
        return "curl -s -o /dev/null -m 10 -w '%{http_code}' -H 'Host: lancache.steamcontent.com' ".escapeshellarg("http://{$c->ip}/lancache-heartbeat");
    }
}
