<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameRecipes\Exceptions\RecipeException;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
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
            return Response::error(implode(' ', array_map(
                static fn (array $messages): string => implode(' ', $messages),
                $exception->errors(),
            )));
        } catch (RecipeException $exception) {
            return Response::error($exception->summary());
        } catch (AuthorizationException) {
            return Response::error('Forbidden: your LANoMAT account may not do that.');
        } catch (ModelNotFoundException|NotFoundHttpException) {
            return Response::error('Not found.');
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
