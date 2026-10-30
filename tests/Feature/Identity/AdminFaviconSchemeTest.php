<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

it('emits an https favicon in the admin head when https is forced', function () {
    // The panel is built before AppServiceProvider::boot() forces https in
    // production; the favicon URL must therefore be resolved lazily.
    URL::forceHttps(true);

    $html = $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertOk()
        ->getContent();

    expect($html)->toMatch('#<link[^>]+rel="icon"[^>]+href="https://[^"]+/favicon\.svg"#')
        ->and($html)->not->toContain('http://localhost/favicon.svg');
});
