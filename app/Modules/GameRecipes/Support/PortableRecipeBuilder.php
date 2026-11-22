<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Actions\ContributeRecipe;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns the "Spiel teilen" form and one pinned LAN archive into the payload
 * {@see ContributeRecipe} expects.
 *
 * The result is a portable recipe: the archive is a ready-to-run game tree,
 * unpacking it is the whole install, and the launcher's generic engine needs
 * nothing more than the base requirement, a file check and a launch target.
 * Every step carries a real `detail` because a draft is also SKILL.md prose for
 * an assistant; the commands are the manual fallback, never run by the
 * launcher.
 */
final class PortableRecipeBuilder
{
    /** Archive names the share page accepts, lower case, with the dot. */
    public const ARCHIVE_EXTENSIONS = ['.zip', '.tar.bz2'];

    /** Game files never travel with the recipe; they stay on the LAN file host. */
    public const LICENSE = 'Game files are user-supplied; shared on the LAN file host.';

    /** Whether a file name is an archive the share page can describe. */
    public static function isArchiveName(string $name): bool
    {
        $name = mb_strtolower(self::baseName($name));

        foreach (self::ARCHIVE_EXTENSIONS as $extension) {
            if (str_ends_with($name, $extension)) {
                return true;
            }
        }

        return false;
    }

    /** The archive file name without any directory part. */
    public static function baseName(string $name): string
    {
        $normalised = trim(str_replace('\\', '/', $name));

        return trim(basename($normalised));
    }

    /**
     * Whether a file name survives the places it is used: a shell literal, a
     * Markdown code span and the requirement's glob. A backtick would end a
     * code span, a control character a line, a newline a front matter, and
     * `*`/`?` would turn the pinned glob into a wildcard for other archives.
     * The name must also be one the installing client keeps as it is.
     */
    public static function hasUnsafeArchiveName(string $name): bool
    {
        $name = self::baseName($name);

        return str_contains($name, '`')
            || str_contains($name, '*')
            || str_contains($name, '?')
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            // The installing client stores a download under a name it rewrites
            // (`: " < > |` and a ".." become "-", leading dots go). A recipe
            // command that still names the original would never find the file.
            || preg_match('/[:"<>|]/', $name) === 1
            || str_contains($name, '..')
            || str_starts_with($name, '.');
    }

    /**
     * A glob that still names the file: brackets become `*`, but the remaining
     * pattern must hold at least three literal letters or digits before the
     * extension, or it would match a stranger's archive once the pin is gone.
     */
    public static function hasReliableMatchPattern(string $name): bool
    {
        $core = self::baseName($name);
        $lower = mb_strtolower($core);

        foreach (self::ARCHIVE_EXTENSIONS as $extension) {
            if (str_ends_with($lower, $extension)) {
                $core = mb_substr($core, 0, mb_strlen($core) - mb_strlen($extension));

                break;
            }
        }

        $core = str_replace(['[', ']'], '*', $core);

        return preg_match_all('/[\p{L}\p{N}]/u', $core) >= 3;
    }

    /** Everything the share action needs to accept an archive. */
    public static function isShareableArchive(string $name): bool
    {
        return self::isArchiveName($name)
            && ! self::hasUnsafeArchiveName($name)
            && self::hasReliableMatchPattern($name);
    }

    /**
     * What the generated text says while the recipe is a draft, each with what
     * it says once the orga has published it. The catalogue asks a draft to say
     * that it still needs verification on the LAN; a published game that keeps
     * calling itself a draft would be telling its players something untrue.
     *
     * @var array{0: string, 1: string}
     */
    private const array DRAFT_STEP_NOTE = [
        'The launcher checks these files itself; this draft needs verification on the LAN before the orga publishes it.',
        'The launcher checks these files itself.',
    ];

    /** @var array{0: string, 1: string} */
    private const array DRAFT_DESCRIPTION_NOTE = [
        'This draft needs verification on the LAN before the orga publishes it.',
        'The orga reviewed and published it for this LAN.',
    ];

    /** @var array{0: string, 1: string} */
    private const array DRAFT_SKILL_NOTE = [
        'It is a draft recipe: it needs verification on the LAN before the orga publishes it.',
        'The orga reviewed and published this recipe for the LAN.',
    ];

    /** @var array{0: string, 1: string} */
    private const array DRAFT_TROUBLE_NOTE = [
        '- Still failing: this draft needs verification on the LAN; note what failed for the orga.',
        '- Still failing: note what failed and tell the orga.',
    ];

    /**
     * The generated text of a shared game as it reads once published: every
     * sentence the builder wrote about "this draft" replaced by its published
     * form. Text the builder did not write (an orga's edit, a hand-written
     * SKILL.md) contains none of these sentences and comes back unchanged.
     */
    public static function publishedText(string $text): string
    {
        return strtr($text, [
            self::DRAFT_STEP_NOTE[0] => self::DRAFT_STEP_NOTE[1],
            self::DRAFT_DESCRIPTION_NOTE[0] => self::DRAFT_DESCRIPTION_NOTE[1],
            self::DRAFT_SKILL_NOTE[0] => self::DRAFT_SKILL_NOTE[1],
            self::DRAFT_TROUBLE_NOTE[0] => self::DRAFT_TROUBLE_NOTE[1],
        ]);
    }

    /**
     * The SKILL.md head. Symfony writes the YAML, so every quote, backslash,
     * colon, leading dash, hash, line break and tab survives a parse instead of
     * ending the front matter by hand.
     */
    public static function yamlFrontMatter(string $slug, string $description): string
    {
        $yaml = Yaml::dump(['name' => $slug, 'description' => $description], 2, 2);

        return "---\n".rtrim($yaml, "\n")."\n---\n";
    }

    /**
     * Build the contribution payload for one archive.
     *
     * @param  array<string, mixed>  $input  the validated share form
     * @return array<string, mixed>
     */
    public function build(array $input, SharedFile $file): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $slug = Str::slug($title);
        $platforms = $this->platforms($input['platforms'] ?? []);
        $windowsExe = self::relativePath((string) ($input['executable'] ?? ''));
        $runner = $this->linuxRunner($input, $platforms);
        $linuxExe = $this->linuxExecutable($input, $windowsExe, $runner, $platforms);
        $archive = $this->archiveName($file);
        $summary = $this->summary($input, $title);
        $description = $this->description($title, $windowsExe !== '' ? $windowsExe : $linuxExe);
        $skillDescription = $this->skillDescription($title, $archive, $input);

        $payload = [
            'slug' => $slug,
            'title' => $title,
            'summary' => $summary,
            'description' => $description,
            'version' => $this->version($input),
            'platforms' => $platforms,
            'license' => self::LICENSE,
            'via' => 'web',
            'requires' => [$this->requirement($title, $file, $archive)],
            'steps' => $this->steps($archive, $platforms, $windowsExe, $linuxExe, $runner),
            'launch_targets' => $this->launchTargets($platforms, $windowsExe, $linuxExe, $runner),
            'metadata' => [
                'portable' => true,
                'genre' => 'other',
                'players' => $this->players($input),
                'lan_recommended' => true,
                'tags' => ['shared'],
                'multiplayer' => (bool) ($input['multiplayer'] ?? true),
            ],
            'skill_description' => $skillDescription,
            'skill_markdown' => $this->skillMarkdown(
                $slug,
                $title,
                $summary,
                $archive,
                $platforms,
                $windowsExe,
                $linuxExe,
                $runner,
                $skillDescription,
            ),
        ];

        $homepage = trim((string) ($input['homepage'] ?? ''));

        if ($homepage !== '') {
            $payload['homepage'] = $homepage;
        }

        return $payload;
    }

    /**
     * Windows before Linux, duplicates dropped. Anything else is ignored; the
     * request has already narrowed the field to the two supported platforms.
     *
     * @return list<string>
     */
    private function platforms(mixed $platforms): array
    {
        $selected = array_map('strval', is_array($platforms) ? $platforms : []);

        return array_values(array_filter(
            ['windows', 'linux'],
            static fn (string $platform): bool => in_array($platform, $selected, true),
        ));
    }

    /** How Linux starts the game: a native binary or the Windows files under Wine.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $platforms
     */
    private function linuxRunner(array $input, array $platforms): ?string
    {
        if (! in_array('linux', $platforms, true)) {
            return null;
        }

        // `linux_runner` is required for a Linux share; Wine is the safe
        // default when a caller skips the field.
        return ($input['linux_runner'] ?? null) === 'native' ? 'native' : 'wine';
    }

    /**
     * The executable Linux starts. A Wine run starts the Windows binary; a
     * native run needs its own executable, falling back to the Windows path so
     * the check never silently disappears.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $platforms
     */
    private function linuxExecutable(array $input, string $windowsExe, ?string $runner, array $platforms): string
    {
        if (! in_array('linux', $platforms, true)) {
            return '';
        }

        if ($runner !== 'native') {
            return $windowsExe;
        }

        $native = self::relativePath((string) ($input['linux_executable'] ?? ''));

        return $native !== '' ? $native : $windowsExe;
    }

    /**
     * A browser may hand over backslashes; a path in a recipe is always
     * forward-slashed and relative. The request validates the shape.
     */
    private static function relativePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    /** The name in `{{downloads}}`: base name only, never a path. */
    private function archiveName(SharedFile $file): string
    {
        $base = self::baseName($file->original_name);

        return $base !== '' ? $base : 'game-archive.zip';
    }

    /** The exact PowerShell command the recipe runs on the player's PC. */
    private function windowsUnpackCommand(string $archive): string
    {
        return 'Expand-Archive -LiteralPath '.$this->powershellLiteral('{{downloads}}/'.$archive)
            .' -DestinationPath "{{target}}" -Force';
    }

    /** The exact Linux extraction command; `bsdtar` reads ZIP, `tar -xjf` bzip2. */
    private function linuxExtractCommand(string $archive): string
    {
        return (str_ends_with(mb_strtolower($archive), '.tar.bz2') ? 'tar -xjf ' : 'bsdtar -xf ')
            .$this->bashLiteral('{{downloads}}/'.$archive)
            .' -C "{{target}}"';
    }

    /** The exact Windows launch target. */
    private function windowsLaunchCommand(string $executable): string
    {
        return 'Start-Process -FilePath "{{target}}/'.$executable.'" -WorkingDirectory "{{target}}"';
    }

    /** The exact Linux launch target: Wine for a Windows tree, the native binary otherwise. */
    private function linuxLaunchCommand(string $executable, ?string $runner): string
    {
        return $runner === 'native'
            ? '"{{target}}/'.$executable.'"'
            : 'wine "{{target}}/'.$executable.'"';
    }

    /** Every glob metacharacter becomes `*`, so the pinned name still matches. */
    private function matchPattern(string $archive): string
    {
        return str_replace(['*', '?', '[', ']'], '*', $archive);
    }

    /**
     * The one required base file. `shared_file_id` pins the contributor's
     * upload on the file row; ContributeRecipe keeps it out of the recipe text.
     *
     * @return array<string, mixed>
     */
    private function requirement(string $title, SharedFile $file, string $archive): array
    {
        $requirement = [
            'role' => 'base',
            'label' => mb_substr($title.' (portable archive)', 0, 200),
            'match' => [$this->matchPattern($archive)],
            'required' => true,
            'size_bytes' => $file->size_bytes,
            'shared_file_id' => $file->id,
            'notes' => 'Unpack the archive into {{target}}; if it carries a single wrapper folder, move the game files up.',
        ];

        // A recipe only claims a checksum the file host actually has.
        if (is_string($file->sha256) && preg_match('/^[a-f0-9]{64}$/i', $file->sha256) === 1) {
            $requirement['sha256'] = $file->sha256;
        }

        return $requirement;
    }

    /**
     * 3 to 6 steps: fetch the archive, unpack it per selected platform, check
     * the install. The launcher needs the file checks; a human needs the prose.
     *
     * @param  list<string>  $platforms
     * @return list<array<string, mixed>>
     */
    private function steps(string $archive, array $platforms, string $windowsExe, string $linuxExe, ?string $runner): array
    {
        $steps = [[
            'id' => 'get-archive',
            'title' => 'Get the portable archive',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Download '.$this->markdownCode($archive).' from the LAN file host into '.$this->markdownCode('{{downloads}}').'. '
                .'The recipe pins exactly this archive, so no retail disc and no installer is needed.',
            'commands' => [],
        ]];

        if (in_array('windows', $platforms, true)) {
            $steps[] = [
                'id' => 'unpack-windows',
                'title' => 'Unpack the archive on Windows',
                'os' => 'windows',
                'optional' => false,
                'detail' => 'Unpack '.$this->markdownCode($archive).' into '.$this->markdownCode('{{target}}').' with PowerShell. '
                    .'The archive is a ready-to-run game tree; when it carries one wrapper folder, '
                    .'move the game files up so '.$this->markdownCode($windowsExe).' sits directly in '.$this->markdownCode('{{target}}').'.',
                'commands' => [[
                    'shell' => 'powershell',
                    'run' => $this->windowsUnpackCommand($archive),
                ]],
                'verify' => [['type' => 'file_exists', 'path' => '{{target}}/'.$windowsExe]],
            ];
        }

        if (in_array('linux', $platforms, true)) {
            $steps[] = [
                'id' => 'unpack-linux',
                'title' => 'Unpack the archive on Linux',
                'os' => 'linux',
                'optional' => false,
                'detail' => 'Create '.$this->markdownCode('{{target}}').' and unpack '.$this->markdownCode($archive).' into it. '
                    .'`bsdtar` reads ZIP archives without an extra package; a `.tar.bz2` archive is read by `tar -xjf`. '
                    .'When the archive carries one wrapper folder, move its contents up so '.$this->markdownCode($linuxExe).' sits directly in '.$this->markdownCode('{{target}}').'.',
                'commands' => [
                    ['shell' => 'bash', 'run' => 'mkdir -p "{{target}}"'],
                    ['shell' => 'bash', 'run' => $this->linuxExtractCommand($archive)],
                ],
                'verify' => [['type' => 'file_exists', 'path' => '{{target}}/'.$linuxExe]],
            ];
        }

        $steps[] = [
            'id' => 'check-install',
            'title' => 'Check the install and start the game',
            'os' => 'any',
            'optional' => false,
            'detail' => $this->checkDetail($windowsExe, $linuxExe, $runner, $platforms),
            'verify' => $this->installChecks($platforms, $windowsExe, $linuxExe),
        ];

        return $steps;
    }

    /** @param list<string> $platforms */
    private function checkDetail(string $windowsExe, string $linuxExe, ?string $runner, array $platforms): string
    {
        $checks = [];

        if (in_array('windows', $platforms, true)) {
            $checks[] = $this->markdownCode('{{target}}/'.$windowsExe).' on Windows';
        }

        if (in_array('linux', $platforms, true)) {
            $checks[] = $this->markdownCode('{{target}}/'.$linuxExe).' on Linux';
        }

        $detail = count($checks) === 1
            ? 'Confirm that '.$checks[0].' exists. '
            : 'Confirm that '.implode(' exists and ', $checks).' exists. ';

        if ($runner === 'wine') {
            $detail .= 'Linux runs the same Windows game tree through Wine; without a working Wine prefix the game does not start. ';
        } elseif ($runner === 'native') {
            $detail .= 'On Linux the native binary starts directly from the unpacked folder. ';
        }

        return $detail.self::DRAFT_STEP_NOTE[0];
    }

    /**
     * The launcher proves the layout with these checks. A check narrowed to one
     * OS only runs on that OS, so a two-platform recipe never demands the
     * Windows executable on a Linux machine (or the other way round).
     *
     * @param  list<string>  $platforms
     * @return list<array<string, mixed>>
     */
    private function installChecks(array $platforms, string $windowsExe, string $linuxExe): array
    {
        $checks = [];

        if (in_array('windows', $platforms, true)) {
            $checks[] = ['type' => 'file_exists', 'path' => '{{target}}/'.$windowsExe];
        }

        if (in_array('linux', $platforms, true)) {
            // The same path on both platforms is one plain check.
            $duplicate = $checks !== [] && $linuxExe === $windowsExe;

            $checks[] = $duplicate
                ? ['type' => 'file_exists', 'path' => '{{target}}/'.$linuxExe]
                : ['type' => 'file_exists', 'path' => '{{target}}/'.$linuxExe, 'os' => 'linux'];
        }

        return $checks;
    }

    /**
     * Launch targets in the exact forms the launcher resolves: Windows through
     * `Start-Process` (it still finds the `.exe` and starts it directly), Linux
     * native as a quoted absolute path, Wine as the Windows binary under Wine.
     *
     * @param  list<string>  $platforms
     * @return list<array<string, mixed>>
     */
    private function launchTargets(array $platforms, string $windowsExe, string $linuxExe, ?string $runner): array
    {
        $targets = [];

        if (in_array('windows', $platforms, true)) {
            $targets[] = [
                'id' => 'windows-direct',
                'label' => 'Windows ('.$windowsExe.')',
                'os' => 'windows',
                'shell' => 'powershell',
                'command' => $this->windowsLaunchCommand($windowsExe),
                'notes' => 'Starts the unpacked executable directly from the target folder.',
            ];
        }

        if (in_array('linux', $platforms, true)) {
            $targets[] = $runner === 'native'
                ? [
                    'id' => 'linux-native',
                    'label' => 'Linux ('.$linuxExe.')',
                    'os' => 'linux',
                    'shell' => 'bash',
                    'command' => $this->linuxLaunchCommand($linuxExe, $runner),
                    'notes' => 'Starts the native Linux binary from the target folder.',
                ]
                : [
                    'id' => 'linux-wine',
                    'label' => 'Linux (Wine, '.$linuxExe.')',
                    'os' => 'linux',
                    'shell' => 'bash',
                    'command' => $this->linuxLaunchCommand($linuxExe, $runner),
                    'notes' => 'Runs the unpacked Windows executable through Wine.',
                ];
        }

        return $targets;
    }

    /** The form's summary, or a generated one; never longer than 200 chars.
     *
     * @param  array<string, mixed>  $input
     */
    private function summary(array $input, string $title): string
    {
        $summary = trim((string) ($input['summary'] ?? ''));

        if ($summary === '') {
            $summary = 'A portable '.$title.' archive from the LAN file host; unpack it into the target folder and play.';
        }

        return mb_substr($summary, 0, 200);
    }

    /** Two to four English sentences: archive, user-supplied files, LAN verification. */
    private function description(string $title, string $executable): string
    {
        $start = $executable !== '' ? ' start `'.$executable.'`' : ' start the game';

        return $title.' is a portable game archive shared by a participant on the LANoMAT file host. '
            .'The archive holds a ready-to-run game folder: unpack it into the target folder and'.$start.'; '
            .'the game files are user-supplied and never leave the LAN. '
            .self::DRAFT_DESCRIPTION_NOTE[0];
    }

    /** @param array<string, mixed> $input */
    private function skillDescription(string $title, string $archive, array $input): string
    {
        $runner = (string) ($input['linux_runner'] ?? '');

        return 'Install '.$title.' from the portable '.$archive.' archive on the LAN file host by unpacking it into the target folder '
            .($runner === 'native' ? 'and starting the native Linux binary' : 'and starting the game')
            .'; use when a player wants '.$title.' installed for the LAN.';
    }

    /** @param array<string, mixed> $input */
    private function version(array $input): string
    {
        $version = trim((string) ($input['version'] ?? ''));

        return $version !== '' ? mb_substr($version, 0, 40) : '1.0';
    }

    /** @param array<string, mixed> $input */
    private function players(array $input): string
    {
        $players = trim((string) ($input['players'] ?? ''));

        return $players !== '' ? mb_substr($players, 0, 20) : '1+';
    }

    /**
     * A complete SKILL.md (frontmatter plus the sections the recipe standard
     * asks for: when to use, what you need, questions, steps per OS, verify,
     * start, join, troubleshooting).
     *
     * @param  list<string>  $platforms
     */
    private function skillMarkdown(
        string $slug,
        string $title,
        string $summary,
        string $archive,
        array $platforms,
        string $windowsExe,
        string $linuxExe,
        ?string $runner,
        string $skillDescription,
    ): string {
        // Participant prose stays prose: a backtick is escaped so a title or a
        // summary can never start a code span or a fence. Commands are printed
        // with the very strings the recipe runs.
        $titleText = $this->markdownText($title);
        $summaryText = $this->markdownText($summary);
        $frontMatter = self::yamlFrontMatter($slug, $skillDescription);

        $lines = [
            ...explode("\n", rtrim($frontMatter, "\n")),
            '',
            '# '.$titleText,
            '',
            $summaryText,
            '',
            'This skill installs the game from a portable archive that a participant shared on the LAN file host.',
            self::DRAFT_SKILL_NOTE[0],
            '',
            '## When to use',
            '',
            'Use this skill when the player asks to install '.$titleText.',',
            'or when the LANoMAT server browser lists a '.$titleText.' server and the game is not installed yet.',
            'Use it when somebody asks what the shared archive contains.',
            '',
            '## What you need',
            '',
            '- The archive '.$this->markdownCode($archive).' from the LAN file host, downloaded to '.$this->markdownCode('{{downloads}}').'.',
            '- A target folder ('.$this->markdownCode('{{target}}').') the player owns; the game is unpacked into it.',
            '- No installer, no disc and no admin rights: the archive is a ready-to-run game tree.',
            '- On Linux, Wine for a Windows tree, or the native binary when the recipe names one.',
            '',
            '## Questions to ask',
            '',
            '1. Which folder should '.$titleText.' be installed into? The default is '.$this->markdownCode('{{target}}').'.',
            '2. Which platform runs the game: '.implode(' or ', $platforms).'?',
            '3. Should the game be started after the install, and does the player want to join a LAN server right away?',
            '4. Is the archive already approved on the files page, or does it still wait for the orga?',
            '',
        ];

        if (in_array('windows', $platforms, true)) {
            $lines = [...$lines,
                '## Steps on Windows',
                '',
                '1. Download '.$this->markdownCode($archive).' into '.$this->markdownCode('{{downloads}}').'.',
                '2. Run '.$this->markdownCode($this->windowsUnpackCommand($archive)).'.',
                '3. If the archive unpacked one wrapper folder, open it and move the game files one level up.',
                '4. Confirm that '.$this->markdownCode('{{target}}/'.$windowsExe).' exists.',
                '',
            ];
        }

        if (in_array('linux', $platforms, true)) {
            $lines = [...$lines,
                '## Steps on Linux',
                '',
                '1. Download '.$this->markdownCode($archive).' into '.$this->markdownCode('{{downloads}}').'.',
                '2. Run '.$this->markdownCode('mkdir -p "{{target}}"').'.',
                '3. Run '.$this->markdownCode($this->linuxExtractCommand($archive)).'.',
                '4. If the archive unpacked one wrapper folder, move its contents up into '.$this->markdownCode('{{target}}').'.',
                '5. Confirm that '.$this->markdownCode('{{target}}/'.$linuxExe).' exists.',
                '',
            ];
        }

        $verify = ['## Verify', ''];

        if (in_array('windows', $platforms, true)) {
            $verify[] = '- '.$this->markdownCode('{{target}}/'.$windowsExe).' must be a file on Windows.';
        }

        if (in_array('linux', $platforms, true)) {
            $verify[] = '- '.$this->markdownCode('{{target}}/'.$linuxExe).' must be a file on Linux.';
        }

        $verify = [...$verify,
            '- The game folder must contain the data files the archive shipped; an empty folder means the extraction failed.',
            '- Every check in the recipe must pass before the install counts as done.',
            '',
        ];

        $start = ['## Start', ''];

        if (in_array('windows', $platforms, true)) {
            $start[] = 'Windows: '.$this->markdownCode($this->windowsLaunchCommand($windowsExe)).'.';
        }

        if (in_array('linux', $platforms, true)) {
            $start[] = 'Linux: '.$this->markdownCode($this->linuxLaunchCommand($linuxExe, $runner)).'.';
        }

        $start = [...$start,
            'The launcher starts the matching launch target on its own; the commands above are the manual fallback.',
            '',
        ];

        $lines = [...$lines, ...$verify, ...$start,

            '## Join a server via the LANoMAT server browser',
            '',
            '1. Open `/servers` in LANoMAT and find a '.$titleText.' server.',
            '2. If the server offers a launcher join, use it: `lanomat://join/<slug>` starts the installed game against that server.',
            '3. Otherwise copy the address and port from the server card into the game\'s own multiplayer menu.',
            '4. Prefer the LAN address of the server; a public address may not resolve inside the LAN.',
            '',
            '## Troubleshooting',
            '',
            '- The executable sits in a wrapper folder: move the contents of that folder up into `{{target}}` and try again.',
            '- The archive is missing: approve the file on the files page or pin another archive on `/games/share`.',
            '- Windows blocks the executable: unblock the game folder in its file properties or with `Unblock-File`.',
            '- Linux starts Wine but no window appears: check that a 32-bit Wine prefix exists for older games.',
            '- A server does not appear: compare the address and port on the server card with the game\'s server list.',
            self::DRAFT_TROUBLE_NOTE[0],
            '',
        ];

        return implode("\n", $lines);
    }

    /** Prose from a participant: a backtick may never open or close a code span. */
    private function markdownText(string $text): string
    {
        return str_replace('`', '\\`', $text);
    }

    /**
     * One code span around machine text. A backtick that slipped in (a name the
     * action would refuse) falls back to escaped prose instead of ending the span.
     */
    private function markdownCode(string $text): string
    {
        return str_contains($text, '`') ? $this->markdownText($text) : '`'.$text.'`';
    }

    /** A single-quoted PowerShell literal: `$` and backticks stay literal. */
    private function powershellLiteral(string $path): string
    {
        return "'".str_replace("'", "''", $path)."'";
    }

    /** A single-quoted POSIX shell literal, including the `'\''` escape. */
    private function bashLiteral(string $path): string
    {
        return "'".str_replace("'", "'\\''", $path)."'";
    }
}
