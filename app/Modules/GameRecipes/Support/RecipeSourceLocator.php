<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\GameRecipes\Exceptions\RecipeException;
use Symfony\Component\Process\Process;

/**
 * Materialises a recipe source into a local directory:
 *   - a plain directory (bundled `resources/recipes`, a checkout, a USB stick)
 *   - `smb://host/share/path` via smbclient (the NAS recipe share)
 *   - `git+https://...` via a shallow clone
 *
 * Downloads go to a temporary directory that is removed after the sync.
 */
final class RecipeSourceLocator
{
    /** @return array{path: string, temporary: bool, ref: string} */
    public function materialize(string $source): array
    {
        $source = trim($source);

        if ($source === '') {
            throw RecipeException::source($source, 'the recipe source is empty');
        }

        if (str_starts_with($source, 'smb://')) {
            return $this->fromSmb($source);
        }

        if (str_starts_with($source, 'git+') || str_ends_with($source, '.git')) {
            return $this->fromGit($source);
        }

        $path = str_starts_with($source, '~/') ? (string) getenv('HOME').substr($source, 1) : $source;

        if (! is_dir($path)) {
            throw RecipeException::source($source, 'the directory does not exist');
        }

        return ['path' => realpath($path) ?: $path, 'temporary' => false, 'ref' => $source];
    }

    /** @return array{path: string, temporary: bool, ref: string} */
    private function fromSmb(string $source): array
    {
        $binary = trim((string) shell_exec('command -v smbclient 2>/dev/null'));

        if ($binary === '') {
            throw RecipeException::source($source, 'smbclient is not installed (apk add samba-client / apt install smbclient)');
        }

        $url = parse_url($source);
        $host = (string) ($url['host'] ?? '');
        $share = ltrim((string) ($url['path'] ?? ''), '/');
        $sub = '';

        if ($host === '' || preg_match('/[\s\x00-\x1F\x7F]/', $host) === 1) {
            throw RecipeException::source($source, 'expected smb://host/share[/path]');
        }

        if ($share === '') {
            throw RecipeException::source($source, 'expected smb://host/share[/path]');
        }

        [$share, $sub] = array_pad(explode('/', $share, 2), 2, '');

        if ($share === '' || $share === '.' || $share === '..') {
            throw RecipeException::source($source, 'expected smb://host/share[/path]');
        }

        $sub = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $sub);

        $temp = $this->tempDir('lanomat-recipes-smb-');
        $user = (string) config('recipes.smb.user', '');
        $password = (string) config('recipes.smb.password', '');

        $command = $this->smbCommand($temp, $host, $share, $sub, $user, $password);

        $process = Process::fromShellCommandline($command, timeout: 300);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->remove($temp);

            throw RecipeException::source($source, trim($process->getErrorOutput()) ?: 'smbclient failed');
        }

        return ['path' => $temp, 'temporary' => true, 'ref' => $source];
    }

    /**
     * The smbclient invocation as one shell string.
     *
     * Every value that comes from a source URL or the environment is passed
     * through escapeshellarg and the options themselves are literals, so a
     * host like "-oProxyCommand=..." or a share containing "; rm -rf /" is an
     * argument, never a second command.
     */
    public function smbCommand(string $temp, string $host, string $share, string $sub, string $user, string $password): string
    {
        $command = 'cd '.escapeshellarg($temp)
            .' && smbclient '.escapeshellarg('//'.$host.'/'.$share);

        $command .= $user !== ''
            ? ' -U '.escapeshellarg($password !== '' ? $user.'%'.$password : $user)
            : ' -N';

        if ($sub !== '') {
            $command .= ' '.escapeshellarg($sub);
        }

        return $command.' -c '.escapeshellarg('recurse ON; prompt OFF; mget *');
    }

    /** @return array{path: string, temporary: bool, ref: string} */
    private function fromGit(string $source): array
    {
        $url = $this->gitUrl($source);
        $temp = $this->tempDir('lanomat-recipes-git-');

        // `--` ends option parsing: a URL that starts with "-" must never be
        // read as a git option (e.g. --upload-pack=..., which runs a command).
        // The env keeps git from blocking on an interactive credential prompt.
        $process = new Process(
            ['git', 'clone', '--depth', '1', '--', $url, $temp],
            timeout: 300,
            env: ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => 'true'],
        );
        $process->run();

        if (! $process->isSuccessful()) {
            $this->remove($temp);

            throw RecipeException::source($source, trim($process->getErrorOutput()) ?: 'git clone failed');
        }

        return ['path' => $temp, 'temporary' => true, 'ref' => $source];
    }

    /**
     * A git source must name a remote over a known transport. Everything else
     * (a bare path, `file://`, a leading "-", ext:: helpers) is either a local
     * read or a command execution vector in git's own URL parser.
     */
    public function gitUrl(string $source): string
    {
        $url = str_starts_with($source, 'git+') ? substr($source, 4) : $source;
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '-') || preg_match('/[\x00-\x1F\x7F\s]/', $url) === 1) {
            throw RecipeException::source($source, 'git sources must be a URL without spaces or a leading "-"');
        }

        $allowed = preg_match('~^(https?://|ssh://|git://)~i', $url) === 1
            || preg_match('~^[a-z0-9._-]+@[a-z0-9._-]+:~i', $url) === 1
            || str_starts_with($url, '/');

        if (! $allowed) {
            throw RecipeException::source($source, 'git sources must use https://, ssh://, git:// or scp-style git@host:path');
        }

        return $url;
    }

    private function tempDir(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(6));

        if (! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw RecipeException::source($path, 'could not create a temporary directory');
        }

        return $path;
    }

    public function cleanup(string $path, bool $temporary): void
    {
        if ($temporary) {
            $this->remove($path);
        }
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
