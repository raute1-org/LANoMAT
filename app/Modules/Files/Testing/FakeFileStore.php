<?php

declare(strict_types=1);

namespace App\Modules\Files\Testing;

use App\Modules\Files\Contracts\FileStore;
use App\Modules\Files\Exceptions\FileException;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * In-memory {@see FileStore} for tests. `download()` answers a redirect to a
 * recognisable fake URL so tests can assert that (and when) a link is issued.
 */
class FakeFileStore implements FileStore
{
    /** @var array<string, string> path => contents */
    public array $files = [];

    /** When true every operation fails like an unreachable store. */
    public bool $unreachable = false;

    public function __construct(private readonly string $key = 'fake') {}

    public function key(): string
    {
        return $this->key;
    }

    public function put(string $localPath, string $path): void
    {
        $this->guard();
        $this->files[$path] = (string) file_get_contents($localPath);
    }

    public function delete(string $path): void
    {
        $this->guard();
        unset($this->files[$path]);
    }

    public function exists(string $path): bool
    {
        $this->guard();

        return isset($this->files[$path]);
    }

    public function download(string $path, string $name): Response
    {
        return new RedirectResponse('https://files.fake.test/'.$path.'?n='.rawurlencode($name));
    }

    public function assertStored(string $path): void
    {
        Assert::assertArrayHasKey($path, $this->files);
    }

    public function assertEmpty(): void
    {
        Assert::assertSame([], $this->files, 'Expected the fake store to hold no files.');
    }

    private function guard(): void
    {
        if ($this->unreachable) {
            throw FileException::storeUnavailable();
        }
    }
}
