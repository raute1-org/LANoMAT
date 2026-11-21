<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Support\DisplayTimezone;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use App\Support\SafeText;
use DateTimeInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Shared plumbing of every assistant tool.
 *
 * - The actor is the User the agent was built for, handed in through the
 *   constructor. Tools never read Auth::user() (a streamed/queued run has no
 *   reliable request context) and never take a user id from the model.
 * - `handle()` is final: it checks that the actor is an orga and that the
 *   wrapped module is on, runs the tool and turns every failure into a short
 *   readable answer so the model can react instead of the request ending in a
 *   500. Unexpected exceptions are logged with a reference and never echoed.
 * - Output is data for the model, never instructions: untrusted text is
 *   clamped with {@see SafeText} before it is returned.
 */
abstract class AssistantTool implements Tool
{
    private const MAX_RESULT_CHARS = 12000;

    public function __construct(protected readonly User $actor) {}

    /** The tool name the model sees (snake_case). */
    abstract public static function toolName(): string;

    /**
     * Modules whose pages/actions this tool wraps; the tool is hidden and
     * refuses to run while one of them is off.
     *
     * @return list<Module>
     */
    public static function requiredModules(): array
    {
        return [];
    }

    public function name(): string
    {
        return static::toolName();
    }

    /** @return array<string, mixed>|string */
    abstract protected function run(Request $request): array|string;

    /** The tool's JSON schema; override for tools with arguments. */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    final public function handle(Request $request): string
    {
        try {
            $this->assertAllowedToRun();

            return $this->encode($this->run($request));
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            return SafeText::line(implode(' ', array_map(
                static fn (int|string $field, array $messages): string => $field.': '.implode(' ', $messages),
                array_keys($errors),
                array_values($errors),
            )), 500) ?? 'Invalid arguments.';
        } catch (AuthorizationException) {
            return 'Forbidden: this account may not do that.';
        } catch (ModelNotFoundException) {
            return 'Not found.';
        } catch (DomainException $exception) {
            return SafeText::line($this->domainMessage($exception), 400) ?? 'The request was rejected.';
        } catch (Throwable $exception) {
            $ref = (string) Str::uuid();
            Log::error('Assistant tool failed', ['tool' => static::toolName(), 'ref' => $ref, 'exception' => $exception]);

            return "LANoMAT could not complete the request (ref {$ref}).";
        }
    }

    /**
     * @throws AuthorizationException
     * @throws DomainException
     */
    protected function assertAllowedToRun(): void
    {
        if (! $this->actor->isOrga()) {
            throw new AuthorizationException;
        }

        foreach (static::requiredModules() as $module) {
            if (! app(Modules::class)->enabled($module)) {
                throw new DomainException("The {$module->key()} module is not enabled on this server.");
            }
        }
    }

    /** @param  array<string, mixed>|string  $result */
    private function encode(array|string $result): string
    {
        $text = is_string($result) ? $result : (json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}');

        return mb_strlen($text) > self::MAX_RESULT_CHARS
            ? mb_substr($text, 0, self::MAX_RESULT_CHARS).' ... [truncated]'
            : $text;
    }

    /** Domain exceptions of the modules carry a translation key; prefer the German text. */
    private function domainMessage(DomainException $exception): string
    {
        $key = property_exists($exception, 'translationKey') ? $exception->translationKey : null;

        if (is_string($key) && $key !== '') {
            $params = property_exists($exception, 'translationParams') && is_array($exception->translationParams) ? $exception->translationParams : [];
            $text = __($key, $params);

            if (is_string($text) && $text !== $key) {
                return $text;
            }
        }

        return $exception->getMessage();
    }

    protected function can(string $ability, mixed $arguments = []): bool
    {
        return Gate::forUser($this->actor)->allows($ability, $arguments);
    }

    /** @throws AuthorizationException */
    protected function authorize(string $ability, mixed $arguments = []): void
    {
        Gate::forUser($this->actor)->authorize($ability, $arguments);
    }

    /** One bounded line of untrusted text. */
    protected function text(?string $value, int $max = 200): ?string
    {
        return SafeText::line($value, $max);
    }

    protected function berlin(?DateTimeInterface $instant): ?string
    {
        return $instant === null ? null : DisplayTimezone::local($instant)->toIso8601String();
    }
}
