<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('renders a german 404 page in the app shell look for guests', function () {
    $response = $this->get('/does-not-exist');

    $response->assertNotFound()
        ->assertSee('Seite nicht gefunden')
        ->assertSee('Zur Startseite')
        ->assertSee('lang="de"', false)
        ->assertDontSee('Not Found');
});

it('renders the 404 page for a non-numeric id', function () {
    $this->get('/users/abc')->assertNotFound()->assertSee('Seite nicht gefunden');
});

it('renders a german 403 page', function () {
    $user = User::factory()->create(['role' => 'participant']);

    $event = Event::factory()->live()->create();

    $this->actingAs($user)->get("/orga/events/{$event->slug}/checkin")->assertForbidden()
        ->assertSee('Kein Zugriff')
        ->assertSee('Zur Startseite');
});

it('renders german pages for every other error status', function (int $status, string $headline) {
    Route::get("/_test/error/{$status}", fn () => abort($status));

    $this->get("/_test/error/{$status}")->assertStatus($status)->assertSee($headline);
})->with([
    [419, 'Sitzung abgelaufen'],
    [429, 'Zu viele Anfragen'],
    [500, 'Etwas ist schiefgelaufen'],
    [503, 'Wartungsarbeiten'],
]);

it('does not leak exception details on a 500 in production', function () {
    config(['app.debug' => false, 'app.env' => 'production']);
    Route::get('/_test/boom', fn () => throw new RuntimeException('secret-db-password-xyz'));

    $response = $this->get('/_test/boom');

    $response->assertStatus(500)
        ->assertSee('Etwas ist schiefgelaufen')
        ->assertDontSee('secret-db-password-xyz')
        ->assertDontSee('RuntimeException')
        ->assertDontSee('Stack trace')
        ->assertDontSee('ErrorPagesTest');
});

it('does not echo a custom abort message into the page', function () {
    Route::get('/_test/forbidden', fn () => abort(403, 'internal-reason-123'));

    $this->get('/_test/forbidden')->assertForbidden()->assertDontSee('internal-reason-123');
});

it('keeps the admin panel reachable and 404s inside it sanely', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get('/admin/nonexistent')->assertNotFound()->assertSee('Seite nicht gefunden');
});

it('offers the admin link on an admin 403 only to staff, never to participants', function () {
    $participant = User::factory()->create(['role' => 'participant']);

    $this->actingAs($participant)->get('/admin/events')->assertForbidden()
        ->assertDontSee('Zum Admin-Bereich');
});
