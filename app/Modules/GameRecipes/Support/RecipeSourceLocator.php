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
        $host = $url['host'] ?? '';
        $share = ltrim((string) ($url['path'] ?? ''), '/');
        $sub = '';

        if ($share === '') {
            throw RecipeException::source($source, 'expected smb://host/share[/path]');
        }

        [$share, $sub] = array_pad(explode('/', $share, 2), 2, '');

        $temp = $this->tempDir('lanomat-recipes-smb-');
        $user = (string) config('recipes.smb.user', '');
        $password = (string) config('recipes.smb.password', '');

        $auth = $user !== ''
            ? ['-U', $password !== '' ? $user.'%'.$password : $user]
            : ['-N'];

        $authFile = null;

        if ($user !== '') {
            // A credentials file keeps the password out of `ps` and the logs.
            $authFile = $temp.'/.smbcredentials';
            file_put_contents($authFile, "username={$user}\npassword={$password}\n");
            chmod($authFile, 0600);
            $auth = ['-A', $authFile];
        }

        $remote = $sub !== '' ? 'cd "'.$sub.'"; recurse ON; prompt OFF; mget *' : 'recurse ON; prompt OFF; mget *';

        $command = sprintf(
            'cd %s && smbclient %s %s -c %s',
            escapeshellarg($temp),
            escapeshellarg('//'.$host.'/'.$share),
            implode(' ', array_map('escapeshellarg', $auth)),
            escapeshellarg($remote),
        );

        $process = Process::fromShellCommandline($command, timeout: 300);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->remove($temp);

            throw RecipeException::source($source, trim($process->getErrorOutput()) ?: 'smbclient failed');
        }

        if ($authFile !== null) {
            @unlink($authFile);
        }

        return ['path' => $temp, 'temporary' => true, 'ref' => $source];
    }

    /** @return array{path: string, temporary: bool, ref: string} */
    private function fromGit(string $source): array
    {
        $url = str_starts_with($source, 'git+') ? substr($source, 4) : $source;

        // `git clone` would parse a value starting with `--` as an option
        // (`--upload-pack=<cmd>` is code execution); `--` ends option parsing
        // and the scheme allowlist keeps the source to transports we expect.
        if (str_starts_with($url, '-') || preg_match('#^(https?://|ssh://|git@|file://)#', $url) !== 1) {
            throw RecipeException::source($source, 'expected https://, ssh://, git@host:path or file:// for a git source');
        }

        $temp = $this->tempDir('lanomat-recipes-git-');

        $process = new Process(['git', 'clone', '--depth', '1', '--', $url, $temp], timeout: 300);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->remove($temp);

            throw RecipeException::source($source, trim($process->getErrorOutput()) ?: 'git clone failed');
        }

        return ['path' => $temp, 'temporary' => true, 'ref' => $source];
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
