<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameRecipes\Exceptions\RecipeException;
use App\Modules\Mcp\Support\SafeText;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Shared plumbing for every LANoMAT tool.
 *
 * Tools never trust the HTTP request: the actor comes from the bearer token
 * middleware, and every decision goes through the normal Policies. Failures
 * become a readable tool error (the model can react) instead of a stack trace;
 * unexpected exceptions are reported as usual.
 */
abstract class LanomatTool extends Tool
{
    final public function handle(McpRequest $request): Response|ResponseFactory
    {
        try {
            return $this->run($request);
        } catch (ValidationException $exception) {
            // Prefix each message with the machine field name: the locale
            // translates the text (and may capitalise the attribute), but the
            // model must know exactly which argument it has to fix. Clamped,
            // because the field text is caller-controlled.
            $errors = $exception->errors();

            return Response::error(SafeText::line(implode(' ', array_map(
                static fn (int|string $field, array $messages): string => (string) $field.': '.implode(' ', $messages),
                array_keys($errors),
                array_values($errors),
            )), 500) ?? 'Invalid arguments.');
        } catch (AuthorizationException) {
            return Response::error('Forbidden: your LANoMAT account may not do that.');
        } catch (ModelNotFoundException|NotFoundHttpException) {
            return Response::error('Not found.');
        } catch (RecipeException $exception) {
            // A contribution's slug/labels are caller-authored; flatten the
            // problem list to one bounded line before it reaches the model.
            return Response::error('Recipe rejected: '.(SafeText::line($exception->summary(), 500) ?? 'invalid recipe'));
        } catch (AccessDeniedHttpException) {
            return Response::error('Forbidden: your LANoMAT account may not do that.');
        } catch (DomainException $exception) {
            // Domain rules (registration window, full event, closed poll,
            // stale lock_version, ...) are expected answers, not bugs: hand the
            // model the message so it can react instead of reporting a 500.
            return Response::error(SafeText::line($exception->getMessage(), 300) ?? 'The request was rejected.');
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() >= 500) {
                report($exception);

                return Response::error('LANoMAT could not complete the request.');
            }

            return Response::error(
                $exception->getMessage() !== '' ? $exception->getMessage() : 'The request could not be completed.',
            );
        } catch (Throwable $exception) {
            // Never echo framework/PDO/domain messages into the model
            // context: they leak paths and SQL and turn attacker-controlled
            // recipe titles into prompt text. The full detail stays in the log.
            $ref = (string) Str::uuid();
            Log::error('MCP tool failed', ['ref' => $ref, 'exception' => $exception]);

            return Response::error("LANoMAT could not complete the request (ref {$ref}).");
        }
    }

    abstract protected function run(McpRequest $request): Response|ResponseFactory;

    protected function user(): User
    {
        // The bearer middleware installs a request user resolver. `Auth::user()`
        // is the fallback that makes the same tools usable from a session
        // (tests, artisan tinker) without weakening the HTTP endpoint: there the
        // token middleware has already run and a session is never accepted.
        $user = request()->user() ?? Auth::user();

        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        return $user;
    }

    protected function modules(): Modules
    {
        return app(Modules::class);
    }

    protected function on(Module $module): bool
    {
        return $this->modules()->enabled($module);
    }

    protected function eventFromSlug(string $slug): Event
    {
        $event = Event::query()
            ->publiclyVisible()
            ->where('slug', $slug)
            ->first();

        abort_unless($event instanceof Event, 404, 'Event not found.');

        return $event;
    }

    /** @param array<string, mixed> $data */
    protected function structured(array $data): Response|ResponseFactory
    {
        return Response::structured($data);
    }
}
