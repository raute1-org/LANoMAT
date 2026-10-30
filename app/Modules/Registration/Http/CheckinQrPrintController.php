<?php

namespace App\Modules\Registration\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventCheckinToken;
use App\Modules\Registration\Support\QrCode;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/** Printable sheet with the event's self check-in QR (orga only). */
class CheckinQrPrintController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    public function __invoke(Event $event, QrCode $qr): View
    {
        $this->authorize('update', $event);

        $url = route('checkin.show', ['token' => EventCheckinToken::forEvent($event)->token]);

        return view('registration.checkin-print', [
            'event' => $event,
            'url' => $url,
            'svg' => $qr->svg($url),
        ]);
    }
}
