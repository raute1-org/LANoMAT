<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameRecipes\Exceptions\RecipeException;
use App\Modules\GameRecipes\Support\SafeText;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
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
            return Response::error(SafeText::line(implode(' ', array_map(
                static fn (array $messages): string => implode(' ', $messages),
                $exception->errors(),
            )), 500) ?? 'Invalid arguments.');
        } catch (AuthorizationException) {
            return Response::error('Forbidden: your LANoMAT account may not do that.');
        } catch (ModelNotFoundException|NotFoundHttpException) {
            return Response::error('Not found.');
        } catch (RecipeException $exception) {
            // A contribution's slug/labels are caller-authored; flatten the
            // problem list to one bounded line before it reaches the model.
            return Response::error('Recipe rejected: '.(SafeText::line($exception->summary(), 500) ?? 'invalid recipe'));
        } catch (DomainException $exception) {
            return Response::error(SafeText::line($exception->getMessage(), 300) ?? 'The request was rejected.');
        } catch (Throwable $exception) {
            // Never echo $exception->getMessage() here: it carries SQL, file
            // paths and other internals straight into the model's context, and
            // an LLM reads tool output as instructions.
            report($exception);

            return Response::error('LANoMAT could not complete the request. Check the server log and retry.');
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
