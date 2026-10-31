<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Testing;

use App\Modules\Provisioning\Contracts\HostKeyScanner;

class FakeHostKeyScanner implements HostKeyScanner
{
    /** @var list<string|null> answers handed out in order; the last one repeats */
    public array $answers;

    public int $scans = 0;

    public function __construct(?string $fingerprint = null)
    {
        $this->answers = [$fingerprint];
    }

    public function scan(string $address, int $port): ?string
    {
        $answer = $this->answers[min($this->scans, count($this->answers) - 1)];
        $this->scans++;

        return $answer;
    }
}
