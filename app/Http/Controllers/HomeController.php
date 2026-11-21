<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /`: the event-free landing for guests, the "Jetzt" page for signed-in users. It lives
 * outside the module graph (like NowController) so the core Events module does not reach up into
 * the app layer.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Guests get an event-free landing: no event data may leave the server
        // for anyone who is not logged in.
        if ($request->user() === null) {
            return Inertia::render('Welcome', [
                'labels' => trans('welcome'),
            ]);
        }

        return app()->call(app(NowController::class));
    }
}
