<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The body of "Spiel teilen". A path is what the recipe later interpolates
 * into a command and into `{{target}}/<path>`, so it must stay one relative
 * path: forward slashes, no drive, no traversal, no shell metacharacters.
 */
final class ShareGameRequest extends FormRequest
{
    public const TITLE_MAX = 120;

    public const SUMMARY_MAX = 200;

    public const EXECUTABLE_MAX = 200;

    /** Characters a path may never contain; they mean something to a shell. */
    private const SHELL_CHARACTERS = "\"'`$;|&<>*?";

    /** Free text stays on one line: a break would end a SKILL.md block. */
    private const ONE_LINE = 'not_regex:/[\x00-\x1F\x7F]/';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:'.self::TITLE_MAX, self::ONE_LINE],
            // No `exists` rule: an unknown id and somebody else's file must get the
            // same answer, which the action gives after its visibility check.
            'shared_file_id' => ['required', 'integer'],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['string', 'in:windows,linux'],
            'executable' => ['required', 'string', 'max:'.self::EXECUTABLE_MAX, $this->pathRule()],
            'linux_runner' => [
                'nullable',
                'string',
                'in:native,wine',
                Rule::requiredIf(fn (): bool => in_array('linux', (array) $this->input('platforms'), true)),
            ],
            'linux_executable' => [
                'nullable',
                'string',
                'max:'.self::EXECUTABLE_MAX,
                $this->pathRule(),
                Rule::requiredIf(fn (): bool => $this->input('linux_runner') === 'native'),
            ],
            'summary' => ['nullable', 'string', 'max:'.self::SUMMARY_MAX, self::ONE_LINE],
            'version' => ['nullable', 'string', 'max:40', self::ONE_LINE],
            'homepage' => ['nullable', 'url:http,https', 'max:500'],
            'players' => ['nullable', 'string', 'max:20', self::ONE_LINE],
            'multiplayer' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A control character anywhere in free text is one message, not Laravel's
     * generic "invalid format".
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $oneLine = (string) __('game_share.errors.one_line');

        return [
            'title.not_regex' => $oneLine,
            'summary.not_regex' => $oneLine,
            'version.not_regex' => $oneLine,
            'players.not_regex' => $oneLine,
        ];
    }

    /**
     * German field names for the default messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = __('game_share.attributes');

        return is_array($attributes) ? $attributes : [];
    }

    /**
     * Backslashes become the one separator a recipe understands. Trimming stays
     * out: a leading slash is a validation error, not a silently fixed value.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'executable' => $this->normalizedPath($this->input('executable')),
            'linux_executable' => $this->normalizedPath($this->input('linux_executable')),
        ]);
    }

    private function normalizedPath(mixed $value): ?string
    {
        return is_string($value) ? str_replace('\\', '/', $value) : null;
    }

    /** One relative path inside the unpacked game folder. */
    private function pathRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                $fail(__('game_share.errors.path'));

                return;
            }

            if (mb_strlen($value) > self::EXECUTABLE_MAX) {
                $fail(__('game_share.errors.path_too_long'));

                return;
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1
                || str_starts_with($value, '/')
                || preg_match('/^[A-Za-z]:/', $value) === 1
                || strpbrk($value, self::SHELL_CHARACTERS) !== false
                || in_array('.', explode('/', $value), true)
                || in_array('..', explode('/', $value), true)) {
                $fail(__('game_share.errors.path'));

                return;
            }

            if (str_ends_with($value, '/')) {
                $fail(__('game_share.errors.path'));
            }
        };
    }
}
