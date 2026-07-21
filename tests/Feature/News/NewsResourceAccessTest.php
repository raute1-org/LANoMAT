<?php

use App\Models\User;
use App\Modules\News\Filament\Resources\NewsPosts\Pages\CreateNewsPost;
use App\Modules\News\Models\NewsPost;
use App\Modules\News\Policies\NewsPostPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('forbids participants from the news-posts resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/news-posts')
        ->assertForbidden();
});

it('enforces viewAny=isOrga on NewsPostPolicy directly, independent of the panel gate', function () {
    $policy = new NewsPostPolicy;

    expect($policy->viewAny(User::factory()->orga()->create()))->toBeTrue()
        ->and($policy->viewAny(User::factory()->create()))->toBeFalse()
        ->and($policy->viewAny(User::factory()->helper()->create()))->toBeFalse();
});

it('allows orga into the news-posts resource and lists a post', function () {
    NewsPost::factory()->create(['title' => 'Wichtige Ankuendigung']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/news-posts')
        ->assertOk()
        ->assertSee('Wichtige Ankuendigung');
});

it('lets orga create a news post and auto-sets author_id to the acting user, never a client-supplied value', function () {
    $orga = User::factory()->orga()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($orga);

    Livewire::test(CreateNewsPost::class)
        ->fillForm([
            'title' => 'Neue Ankuendigung',
            'body' => 'Inhalt der Ankuendigung.',
            'author_id' => $otherUser->id, // not a real form field; must be ignored even if present
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $post = NewsPost::query()->where('title', 'Neue Ankuendigung')->firstOrFail();

    expect($post->author_id)->toBe($orga->id)
        ->and($post->author_id)->not->toBe($otherUser->id);
});

it('does not list author_id or published_at as fillable', function () {
    $post = new NewsPost;

    expect($post->getFillable())->toBe(['title', 'body']);
});
