<?php

use App\Modules\Provisioning\Domain\FirewallPolicy;

function fwPolicy(array $egress = ['198.51.100.0/24']): FirewallPolicy
{
    return new FirewallPolicy('eth0', '203.0.113.1', ['203.0.113.0/24', '198.51.100.64/26', '203.0.113.0/24'], $egress);
}

it('renders ufw rules from one deduplicated source list', function () {
    expect(fwPolicy()->ufwCommands())->toBe([
        'ufw default deny incoming',
        'ufw default allow outgoing',
        "ufw allow from 203.0.113.0/24 to any port 22 proto tcp comment 'ssh from 203.0.113.0/24'",
        "ufw allow from 198.51.100.64/26 to any port 22 proto tcp comment 'ssh from 198.51.100.64/26'",
        'ufw --force enable',
    ]);
});

it('renders the DOCKER-USER block with markers from the same list', function () {
    expect(fwPolicy()->dockerUserBlock())->toBe(<<<'TXT'
# >>> LANoMAT docker-user >>>
*filter
:DOCKER-USER - [0:0]
-A DOCKER-USER -i eth0 -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN
-A DOCKER-USER -i eth0 -s 203.0.113.0/24 -j RETURN
-A DOCKER-USER -i eth0 -s 198.51.100.64/26 -j RETURN
-A DOCKER-USER -i eth0 -j DROP
-A DOCKER-USER -j RETURN
COMMIT
# <<< LANoMAT docker-user <<<

TXT);
});

it('renders the egress isolation table exactly like the verified manual recipe', function () {
    $expected = <<<'NFT'
define WAN_IF = "eth0"
define GATEWAY = 203.0.113.1
define ALLOWED_NETS = { 198.51.100.0/24 }
define PRIVATE4 = { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16, 100.64.0.0/10 }

table inet lanomat_isolation
flush table inet lanomat_isolation
table inet lanomat_isolation {
	chain output {
		type filter hook output priority filter; policy accept;
		ct state established,related accept
		oif lo accept
		oifname != $WAN_IF accept
		icmpv6 type { nd-router-solicit, nd-router-advert, nd-neighbor-solicit, nd-neighbor-advert } accept
		ip daddr $GATEWAY udp dport 53 accept
		ip daddr $GATEWAY tcp dport 53 accept
		ip daddr $GATEWAY icmp type { echo-request, echo-reply, destination-unreachable, time-exceeded } accept
		ip daddr $ALLOWED_NETS accept
		ip daddr $PRIVATE4 counter reject with icmp type admin-prohibited
		ip6 daddr { fc00::/7, fe80::/10 } counter reject with icmpv6 type admin-prohibited
	}
	chain forward {
		type filter hook forward priority filter; policy accept;
		ct state established,related accept
		oifname != $WAN_IF accept
		ip daddr $GATEWAY udp dport 53 accept
		ip daddr $GATEWAY tcp dport 53 accept
		ip daddr $ALLOWED_NETS accept
		ip daddr $PRIVATE4 counter reject with icmp type admin-prohibited
		ip6 daddr { fc00::/7, fe80::/10 } counter reject with icmpv6 type admin-prohibited
	}
}

NFT;

    expect(fwPolicy()->nftTable())->toBe($expected);
});

it('omits the allowed-nets rules when there are no exemptions and never flushes the whole ruleset', function () {
    $nft = fwPolicy([])->nftTable();

    expect($nft)->not->toContain('ALLOWED_NETS')->and($nft)->not->toContain('flush ruleset');
    expect(fwPolicy()->nftConf())->toContain('include "/etc/nftables.d/*.nft"')
        ->and(preg_match('/^flush ruleset/m', fwPolicy()->nftConf()))->toBe(0);
});

it('knows which sources it admits so the automation can refuse a lock-out', function () {
    expect(fwPolicy()->allowsSource('203.0.113.77'))->toBeTrue()
        ->and(fwPolicy()->allowsSource('198.51.100.100'))->toBeTrue()
        ->and(fwPolicy()->allowsSource('198.51.100.200'))->toBeFalse()
        ->and(fwPolicy()->allowsSource('not-an-ip'))->toBeFalse();
});

it('validates its inputs', function (string $iface, string $gw, array $sources) {
    expect(fn () => new FirewallPolicy($iface, $gw, $sources))->toThrow(InvalidArgumentException::class);
})->with([
    'bad iface' => ['eth0; rm', '203.0.113.1', ['203.0.113.0/24']],
    'bad gateway' => ['eth0', '203.0.113.300', ['203.0.113.0/24']],
    'bad cidr' => ['eth0', '203.0.113.1', ['203.0.113.0/33']],
    'injection in cidr' => ['eth0', '203.0.113.1', ["203.0.113.0/24\n-A INPUT -j ACCEPT"]],
    'no sources' => ['eth0', '203.0.113.1', []],
]);
