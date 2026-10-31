<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

/**
 * The ports LANoMAT may hand to game servers (`services.pelican.port_pool`):
 * a comma separated list of single ports and `from-to` ranges, default
 * 27015-27050 (the Source engine range; the client port 27005 is outbound only).
 */
final readonly class PortPool
{
    public const string DEFAULT = '27015-27050';

    /**
     * @param  list<int>  $ports  sorted, unique
     */
    private function __construct(private array $ports) {}

    public static function parse(string $spec): self
    {
        $spec = trim($spec) === '' ? self::DEFAULT : $spec;
        $ports = [];

        foreach (explode(',', $spec) as $part) {
            $part = trim($part);

            if (preg_match('/^(\d{1,5})-(\d{1,5})$/', $part, $m) === 1) {
                [$from, $to] = [min((int) $m[1], (int) $m[2]), max((int) $m[1], (int) $m[2])];

                foreach (range($from, min($to, 65535)) as $port) {
                    $ports[$port] = $port;
                }
            } elseif (preg_match('/^\d{1,5}$/', $part) === 1 && (int) $part <= 65535) {
                $ports[(int) $part] = (int) $part;
            }
        }

        sort($ports);

        return new self($ports);
    }

    public static function fromConfig(): self
    {
        return self::parse((string) config('services.pelican.port_pool', self::DEFAULT));
    }

    public function contains(int $port): bool
    {
        return in_array($port, $this->ports, true);
    }

    /**
     * @return list<int>
     */
    public function ports(): array
    {
        return $this->ports;
    }
}
