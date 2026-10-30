<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

use InvalidArgumentException;

/**
 * Pure rendering of the VM's two firewall layers plus the egress isolation
 * from ONE list of allowed sources (docs/infra/proxmox-test-vm-lancache.md,
 * steps 1c, 2 and 5). No IO: the output is plain text that steps upload.
 *
 * - ufw: SSH (22/tcp) from every allowed source.
 * - DOCKER-USER (inside /etc/ufw/after.rules): Docker-published ports are
 *   reachable only from the allowed sources, on the WAN interface.
 * - nftables: the VM may not initiate connections into private networks
 *   (egress isolation); exempt are the gateway (DNS/ICMP) and
 *   `$egressAllowedCidrs` (for example a VPN subnet).
 *
 * The private ranges are standard RFC 1918 / link-local / CGNAT constants,
 * not site data.
 */
final readonly class FirewallPolicy
{
    public const string BLOCK_BEGIN = '# >>> LANoMAT docker-user >>>';

    public const string BLOCK_END = '# <<< LANoMAT docker-user <<<';

    public const string NFT_TABLE = 'lanomat_isolation';

    private const string PRIVATE4 = '10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16, 100.64.0.0/10';

    /** @var list<string> */
    public array $allowedSourceCidrs;

    /** @var list<string> */
    public array $egressAllowedCidrs;

    /**
     * @param  list<string>  $allowedSourceCidrs  VM LAN, administrator workstation as the VM sees it, VPN subnet
     * @param  list<string>  $egressAllowedCidrs  destinations exempt from the egress isolation (besides the gateway)
     */
    public function __construct(
        public string $wanIf,
        public string $gateway,
        array $allowedSourceCidrs,
        array $egressAllowedCidrs = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{0,14}$/', $wanIf) !== 1) {
            throw new InvalidArgumentException('Invalid network interface name.');
        }

        if (self::validIp($gateway) === false) {
            throw new InvalidArgumentException('Invalid gateway address.');
        }

        $allowedSourceCidrs = self::uniqueCidrs($allowedSourceCidrs);
        $egressAllowedCidrs = self::uniqueCidrs($egressAllowedCidrs);

        if ($allowedSourceCidrs === []) {
            throw new InvalidArgumentException('At least one allowed source subnet is required (otherwise SSH is locked out).');
        }

        $this->allowedSourceCidrs = $allowedSourceCidrs;
        $this->egressAllowedCidrs = $egressAllowedCidrs;
    }

    /** True when the address lies inside one of the allowed source subnets. */
    public function allowsSource(string $ip): bool
    {
        foreach ($this->allowedSourceCidrs as $cidr) {
            if (self::cidrContains($cidr, $ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> ufw commands (run with sudo), in order.
     */
    public function ufwCommands(): array
    {
        $commands = ['ufw default deny incoming', 'ufw default allow outgoing'];

        foreach ($this->allowedSourceCidrs as $cidr) {
            $commands[] = "ufw allow from {$cidr} to any port 22 proto tcp comment 'ssh from {$cidr}'";
        }

        $commands[] = 'ufw --force enable';

        return $commands;
    }

    /** The managed block that is appended to /etc/ufw/after.rules (markers included). */
    public function dockerUserBlock(): string
    {
        $lines = [
            self::BLOCK_BEGIN,
            '*filter',
            ':DOCKER-USER - [0:0]',
            "-A DOCKER-USER -i {$this->wanIf} -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN",
        ];

        foreach ($this->allowedSourceCidrs as $cidr) {
            $lines[] = "-A DOCKER-USER -i {$this->wanIf} -s {$cidr} -j RETURN";
        }

        $lines[] = "-A DOCKER-USER -i {$this->wanIf} -j DROP";
        $lines[] = '-A DOCKER-USER -j RETURN';
        $lines[] = 'COMMIT';
        $lines[] = self::BLOCK_END;

        return implode("\n", $lines)."\n";
    }

    /** /etc/nftables.d/10-isolation.nft */
    public function nftTable(): string
    {
        $allowed = $this->egressAllowedCidrs === [] ? null : '{ '.implode(', ', $this->egressAllowedCidrs).' }';
        $table = self::NFT_TABLE;

        $head = ["define WAN_IF = \"{$this->wanIf}\"", "define GATEWAY = {$this->gateway}"];

        if ($allowed !== null) {
            $head[] = "define ALLOWED_NETS = {$allowed}";
        }

        $head[] = 'define PRIVATE4 = { '.self::PRIVATE4.' }';

        $allowRule = $allowed === null ? [] : ["\t\tip daddr \$ALLOWED_NETS accept"];

        $output = array_merge([
            "\t\ttype filter hook output priority filter; policy accept;",
            "\t\tct state established,related accept",
            "\t\toif lo accept",
            "\t\toifname != \$WAN_IF accept",
            "\t\ticmpv6 type { nd-router-solicit, nd-router-advert, nd-neighbor-solicit, nd-neighbor-advert } accept",
            "\t\tip daddr \$GATEWAY udp dport 53 accept",
            "\t\tip daddr \$GATEWAY tcp dport 53 accept",
            "\t\tip daddr \$GATEWAY icmp type { echo-request, echo-reply, destination-unreachable, time-exceeded } accept",
        ], $allowRule, [
            "\t\tip daddr \$PRIVATE4 counter reject with icmp type admin-prohibited",
            "\t\tip6 daddr { fc00::/7, fe80::/10 } counter reject with icmpv6 type admin-prohibited",
        ]);

        $forward = array_merge([
            "\t\ttype filter hook forward priority filter; policy accept;",
            "\t\tct state established,related accept",
            "\t\toifname != \$WAN_IF accept",
            "\t\tip daddr \$GATEWAY udp dport 53 accept",
            "\t\tip daddr \$GATEWAY tcp dport 53 accept",
        ], $allowRule, [
            "\t\tip daddr \$PRIVATE4 counter reject with icmp type admin-prohibited",
            "\t\tip6 daddr { fc00::/7, fe80::/10 } counter reject with icmpv6 type admin-prohibited",
        ]);

        return implode("\n", array_merge(
            $head,
            ['', "table inet {$table}", "flush table inet {$table}", "table inet {$table} {", "\tchain output {"],
            $output,
            ["\t}", "\tchain forward {"],
            $forward,
            ["\t}", '}'],
        ))."\n";
    }

    /** /etc/nftables.conf: includes the drop-ins, never `flush ruleset` (Docker and ufw own other tables). */
    public function nftConf(): string
    {
        return "#!/usr/sbin/nft -f\n"
            ."# No \"flush ruleset\": docker and ufw (iptables-nft) own other tables.\n"
            ."# Each file flushes only its own table.\n"
            ."include \"/etc/nftables.d/*.nft\"\n";
    }

    private static function validIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * @param  list<string>  $cidrs
     * @return list<string>
     */
    private static function uniqueCidrs(array $cidrs): array
    {
        $unique = [];

        foreach ($cidrs as $cidr) {
            if (preg_match('/^(.+)\/(\d{1,2})$/', $cidr, $m) !== 1 || ! self::validIp($m[1]) || (int) $m[2] > 32) {
                throw new InvalidArgumentException("Invalid IPv4 subnet: {$cidr}");
            }

            $unique[$cidr] = $cidr;
        }

        return array_values($unique);
    }

    private static function cidrContains(string $cidr, string $ip): bool
    {
        if (! self::validIp($ip)) {
            return false;
        }

        [$net, $prefix] = explode('/', $cidr);
        $mask = (int) $prefix === 0 ? 0 : (~0 << (32 - (int) $prefix)) & 0xFFFFFFFF;

        return (ip2long($ip) & $mask) === (ip2long($net) & $mask);
    }
}
