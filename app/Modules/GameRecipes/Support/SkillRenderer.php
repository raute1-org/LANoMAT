<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use Illuminate\Support\Str;

/** Renders a portable Agent Skills SKILL.md when a recipe directory has none. */
final class SkillRenderer
{
    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        $skill = is_array($data['skill'] ?? null) ? $data['skill'] : [];
        $name = (string) ($skill['name'] ?? $data['slug'] ?? 'game');
        $description = (string) ($skill['description'] ?? ($data['summary'] ?? 'Install '.($data['title'] ?? $name).' on the player PC.'));
        $compatibility = isset($skill['compatibility']) ? "\ncompatibility: ".$this->yamlScalar((string) $skill['compatibility']) : '';

        $lines = [
            '---',
            'name: '.$this->yamlScalar($name),
            'description: '.$this->yamlScalar($description).$compatibility,
            '---',
            '',
            '# '.($data['title'] ?? $name),
            '',
            trim((string) ($data['summary'] ?? '')),
            '',
            '## When to use',
            '',
            'Use this skill when the user asks to install '.($data['title'] ?? $name),
            'or uses one of: '.implode(', ', array_map('strval', (array) ($data['aliases'] ?? []))).'.',
            '',
            '## Steps',
            '',
        ];

        foreach (array_values((array) ($data['steps'] ?? [])) as $index => $step) {
            if (! is_array($step)) {
                continue;
            }

            $optional = (bool) ($step['optional'] ?? false) ? ' (optional)' : '';
            $lines[] = ((int) $index + 1).'. **'.($step['title'] ?? $step['id'] ?? 'Step').'**'.$optional;
            $detail = trim((string) ($step['detail'] ?? ''));

            if ($detail !== '') {
                foreach (preg_split('/\R/', $detail) ?: [] as $detailLine) {
                    $lines[] = '   '.$detailLine;
                }
            }

            $lines[] = '';
        }

        return Str::of(implode("\n", $lines))->trim()->append("\n")->toString();
    }

    private function yamlScalar(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
