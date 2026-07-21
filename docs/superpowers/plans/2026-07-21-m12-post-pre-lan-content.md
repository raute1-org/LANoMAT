# M12 — Post-/Pre-LAN-Content Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax: write the failing test first, run it and see it fail, implement, run it green, `composer check`, commit.

**Goal:** Give people reasons to visit the site *between* and *before* LANs. Ship roadmap items **#15 (per-event photo gallery + auto-generated recap + light orga news)**, **#16 (pre-event countdown/hype mode)**, and **#17 (player-of-the-night vote)** — all built on modules that already exist (M1 events, M2 registration, M3 tournaments, M4 voting, M5 infoscreen, M6 stats/badges, M11 jukebox). Item #18 (LAN-bingo) is deferred.

**Architecture:** Two new modules — `app/Modules/Gallery/` (private-disk photo gallery reusing the M7.3 Files moderation gate exactly: `Pending`/`Approved`/`Rejected`, orga/helper approval, authorized serving route, Filament approval queue) and `app/Modules/Recap/` (a pure `RecapProjection` read-model aggregating already-public data from other modules' read-models). A new `app/Modules/News/` (global orga posts on the homepage). Plus extensions to existing modules: `Events` gains a countdown/hype section and an `arrival_info` field; `Voting` gains an MVP-poll `kind` flavour + auto-seed helper; `Stats` gains a computed `mvp_of_the_night` badge; `Infoscreen` gains `Gallery` + `Recap` beamer scenes. Realtime rides the **existing public `event.{id}` Reverb channel** (no new channels). Image processing is local (`intervention/image` v4, GD driver); the zip download uses native `ZipArchive`.

**Tech Stack:** PHP 8.4 · Laravel 13 · Filament v5 · Inertia v2 + Vue 3 `<script setup lang="ts">` + Tailwind v4 + shadcn-vue · Reverb · Pest 4 · PostgreSQL 16 · Redis · `intervention/image` v4 + `intervention/image-laravel` v4 (GD driver) · native `ZipArchive` (ext-zip already in the image).

## Global Constraints

- Code/comments/commits/docs in **English**; ALL UI copy in German via `lang/de/`.
- **Every authorization via a Policy**; acting user always `auth()->user()`, never a client-supplied id.
- **Privilege/state fields never `$fillable`** (`visibility`, `is_highlight`, poll `status`) — set via Actions/`forceFill`.
- **Modular monolith**; modules talk via events/read-models, never another module's tables. Uploads → Laravel Storage, never Base64.
- **Design system BINDING** ("Signalpult", `docs/design.md`): calm app / loud beamer, rationed signal-amber, `font-mono` machine-data only, all four states (empty/loading/error/normal), `LiveIndicator` for live state, quality floor (responsive/focus/reduced-motion/lazy+sized images/AA). **Invoke `frontend-design` before any new UI or beamer scene.**
- **Reverb `event.{id}` is PUBLIC** — broadcasts carry only public data; the invariant is `broadcastWith()` returns `[]` and an authorized reload delivers the payload (the reveal-override exception below carries only already-public winner data, mirroring `SceneOverride`/`DrawTombola`). **Beamer scene payloads carry no PII — assert absence of PII keys in tests.**
- **TDD**; Conventional Commits (`feat(gallery):`, `feat(recap):`, `feat(news):`, `feat(events):`, `feat(voting):`, `feat(stats):`, `docs(m12):`); `composer check` (pint --test, phpstan level 8, pest **SEQUENTIAL** — never `--parallel`; on `SQLSTATE[40P01]` check `ps aux | grep vendor/bin/pest` for strays) green after every task; frontend gates (eslint/prettier/vue-tsc/build) green for any Vue change. New test files use `uses(RefreshDatabase::class)`.

## Decided technical choices (already verified — bake in)

- **Image lib:** `intervention/image` v4 + `intervention/image-laravel` v4 (v4 requires PHP ^8.3 — fine on 8.4). GD driver. The v4 API (verified against official docs): `ImageManager::usingDriver(GdDriver::class)`, `->decodePath($path)` (or `->read($uploadedFile->getRealPath())`), `->orient()` (bakes EXIF orientation into pixels), `->scaleDown(width: N)`, `->encodeUsingFormat(Format::JPEG, quality: N)`, `->save($path)`, `->size()->width()`/`->height()`. **GD drops all EXIF metadata (incl. GPS) on re-encode** — that is the strip.
- **`gd` extension** added to `docker/Dockerfile` `install-php-extensions` in BOTH the builder and app stages (currently deliberately absent — see the header comment about the QR path). Task 2 adds it and updates that comment.
- **Zip:** native `ZipArchive` (ext-zip already present — do NOT add a composer zip dep) writing a temp file under `storage/app/private/tmp`, returned via `response()->download(...)->deleteFileAfterSend()`.
- **Photos** table `event_photos`; **news** table `news_posts`; **new Event field** `arrival_info` (nullable text).

## File Structure

New: `app/Modules/Gallery/{Enums,Models,Actions,Policies,Http,Support,Filament}`, `app/Modules/Recap/{Support,Http}`, `app/Modules/News/{Models,Filament,Support}`. Extensions to `app/Modules/{Events,Voting,Stats,Infoscreen}`. Frontend: `resources/js/pages/{Gallery,Recap}/`, `resources/js/components/{gallery,scenes}/`, edits to `resources/js/pages/Event/Show.vue` and `resources/js/pages/Screen/Show.vue`. Lang: `lang/de/{gallery,recap,news}.php` + edits to `lang/de/{events,polls,infoscreen}.php`. Tests mirrored under `tests/{Feature,Unit}/{Gallery,Recap,News,Events,Voting,Stats}/`.

---

### Task 1: Gallery schema + `PhotoVisibility` enum + `EventPhoto` model + factory + policy

**Files:**
- Create: `app/Modules/Gallery/Enums/PhotoVisibility.php`
- Create: `app/Modules/Gallery/Models/EventPhoto.php`
- Create: `database/migrations/2026_07_21_100000_create_event_photos_table.php`
- Create: `database/factories/EventPhotoFactory.php`
- Create: `app/Modules/Gallery/Policies/EventPhotoPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php` (register the policy + wire `Unit/Gallery`/`Feature/Gallery` RefreshDatabase groups in `tests/Pest.php`)
- Modify: `tests/Pest.php`
- Create: `lang/de/gallery.php` (start it; extended in later tasks)
- Test: `tests/Feature/Gallery/EventPhotoSchemaTest.php`, `tests/Unit/Gallery/EventPhotoModelTest.php`

**Interfaces:**
- Produces `PhotoVisibility` (string enum, mirrors `FileVisibility`): `Pending = 'pending'`, `Approved = 'approved'`, `Rejected = 'rejected'`; `label(): string` → `__('gallery.visibility.'.$this->value)`.
- Produces `EventPhoto` model: `$fillable = ['event_id', 'uploaded_by', 'caption']` ONLY — `path`/`thumb_path`/`width`/`height`/`visibility`/`is_highlight`/`reviewed_by`/`reviewed_at` are set via `forceFill()` in actions (privilege/state fields). Casts: `visibility => PhotoVisibility::class`, `is_highlight => 'boolean'`, `width`/`height` `integer`, `reviewed_at => 'datetime'`. Relations `event()` (BelongsTo Event), `uploader()` (BelongsTo User, fk `uploaded_by`).
- Produces `EventPhotoPolicy` (mirrors `SharedFilePolicy`): `viewAny(User) = isOrga()`; `create(User) = true` (route-level gate to registered participants happens in the controller — see Task 4); `view(User, EventPhoto) = visibility===Approved || uploaded_by===user->id || isOrga()`; `download(User, EventPhoto) = view(...)`; `delete(User, EventPhoto) = uploaded_by===user->id || isOrga()`; `approve(User, EventPhoto) = isHelper()`; `reject(User, EventPhoto) = isHelper()`; `highlight(User, EventPhoto) = isOrga()`.

- [ ] **Step 1: Failing schema test:**

```php
// tests/Feature/Gallery/EventPhotoSchemaTest.php
<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists an event photo defaulting to pending, not-highlighted', function () {
    $photo = EventPhoto::factory()->create();

    expect($photo->fresh())
        ->visibility->toBe(PhotoVisibility::Pending)
        ->is_highlight->toBeFalse();
});

it('does not mass-assign privilege or state fields', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();

    $photo = new EventPhoto([
        'event_id' => $event->id,
        'uploaded_by' => $user->id,
        'caption' => 'hi',
        'visibility' => PhotoVisibility::Approved,
        'is_highlight' => true,
        'path' => 'hacked',
    ]);

    expect($photo->visibility)->toBeNull()
        ->and($photo->is_highlight)->toBeNull()
        ->and($photo->path)->toBeNull();
});
```

- [ ] **Step 2: Run → FAIL** (`./vendor/bin/pest --filter=EventPhotoSchema`).
- [ ] **Step 3: Implement.** Migration:

```php
// database/migrations/2026_07_21_100000_create_event_photos_table.php
Schema::create('event_photos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('event_id')->constrained()->cascadeOnDelete();
    $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
    $table->string('path');
    $table->string('thumb_path');
    $table->unsignedInteger('width');
    $table->unsignedInteger('height');
    $table->string('caption')->nullable();
    $table->boolean('is_highlight')->default(false);
    $table->string('visibility')->default('pending');
    $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('reviewed_at')->nullable();
    $table->timestamps();

    $table->index(['event_id', 'visibility']);
});
```

Enum:

```php
// app/Modules/Gallery/Enums/PhotoVisibility.php
<?php

namespace App\Modules\Gallery\Enums;

enum PhotoVisibility: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('gallery.visibility.'.$this->value);
    }
}
```

Model:

```php
// app/Modules/Gallery/Models/EventPhoto.php
<?php

namespace App\Modules\Gallery\Models;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Enums\PhotoVisibility;
use Database\Factories\EventPhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $event_id
 * @property int $uploaded_by
 * @property string $path
 * @property string $thumb_path
 * @property int $width
 * @property int $height
 * @property string|null $caption
 * @property bool $is_highlight
 * @property PhotoVisibility $visibility
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
class EventPhoto extends Model
{
    /** @use HasFactory<EventPhotoFactory> */
    use HasFactory;

    /**
     * `path`, `thumb_path`, `width`, `height`, `visibility`, `is_highlight`,
     * `reviewed_by`, `reviewed_at` and `uploaded_by` (ownership) are
     * deliberately excluded — set only by the Gallery actions via forceFill()
     * from the trusted authenticated actor, never mass-assigned.
     */
    protected $fillable = [
        'event_id',
        'uploaded_by',
        'caption',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => PhotoVisibility::class,
            'is_highlight' => 'boolean',
            'width' => 'integer',
            'height' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected static function newFactory(): EventPhotoFactory
    {
        return EventPhotoFactory::new();
    }
}
```

Factory:

```php
// database/factories/EventPhotoFactory.php
<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventPhoto>
 */
class EventPhotoFactory extends Factory
{
    protected $model = EventPhoto::class;

    public function definition(): array
    {
        $uuid = $this->faker->uuid();

        return [
            'event_id' => Event::factory(),
            'uploaded_by' => User::factory(),
            'path' => 'event-1/photos/'.$uuid.'.jpg',
            'thumb_path' => 'event-1/photos/'.$uuid.'-thumb.jpg',
            'width' => 1920,
            'height' => 1080,
            'caption' => null,
            'is_highlight' => false,
            'visibility' => PhotoVisibility::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(['visibility' => PhotoVisibility::Approved, 'reviewed_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(['visibility' => PhotoVisibility::Rejected, 'reviewed_at' => now()]);
    }

    public function highlight(): static
    {
        return $this->state(['is_highlight' => true, 'visibility' => PhotoVisibility::Approved]);
    }
}
```

Policy `EventPhotoPolicy` as specified above. Register in `AppServiceProvider` next to the `SharedFile` policy: `Gate::policy(EventPhoto::class, EventPhotoPolicy::class);`. Add `'Unit/Gallery'` (and `Feature/Gallery` if the file lives outside the default feature dir) to the RefreshDatabase `->in(...)` list in `tests/Pest.php`. Start `lang/de/gallery.php` with the `visibility` array:

```php
// lang/de/gallery.php
<?php

return [
    'visibility' => [
        'pending' => 'Wartet auf Freigabe',
        'approved' => 'Freigegeben',
        'rejected' => 'Abgelehnt',
    ],
];
```

- [ ] **Step 4:** model test (`EventPhotoModelTest`: relations resolve, casts, `is_highlight` default) green + `composer check`.
- [ ] **Step 5: Commit** — `feat(gallery): event_photos schema, PhotoVisibility enum, model and policy`.

---

### Task 2: `UploadPhoto` action (Intervention v4 EXIF-strip + thumbnail) + `gd` in Dockerfile + image config

**Files:**
- Modify: `composer.json` (`intervention/image` ^4, `intervention/image-laravel` ^4) — run `composer require intervention/image-laravel:^4`
- Create: `config/image.php` (published from the package; set driver to GD) — or set via env, see below
- Modify: `docker/Dockerfile` (add `gd` to BOTH `install-php-extensions` blocks + update the "gd deliberately NOT installed" comment)
- Create: `config/gallery.php` (upload/thumbnail limits)
- Create: `app/Modules/Gallery/Exceptions/GalleryException.php`
- Create: `app/Modules/Gallery/Actions/UploadPhoto.php`
- Modify: `.env.example` (note the GD driver default), `phpstan.neon`/`.env.testing` unaffected
- Test: `tests/Feature/Gallery/UploadPhotoTest.php`

**Interfaces:**
- Consumes `EventPhoto`, `PhotoVisibility` (Task 1).
- Produces `UploadPhoto::handle(Event $event, User $actor, UploadedFile $file): EventPhoto` — authorizes nothing itself (the route/controller gates upload eligibility in Task 4; the action is the mechanism). Pipeline: read via Intervention → `->orient()` → cap longest edge at `config('gallery.max_edge')` (default 2560) via `->scaleDown(width: max, height: max)` → `->encodeUsingFormat(Format::JPEG, quality: 82)` → store sanitized original on the private `local` disk under `event-{id}/photos/{uuid}.jpg`; build a thumbnail `->scaleDown(width: config('gallery.thumb_width', 400))` → encode → store `…-thumb.jpg`. Record `width`/`height` from the sanitized original's `->size()`. Set `visibility = Pending`, `uploaded_by = $actor->id`, `caption` (trimmed, nullable) via `forceFill`. `GalleryException::unreadable()` if Intervention fails to decode (translation key `gallery.errors.unreadable`), `GalleryException::tooLarge()` on size overflow (guard uses `config('gallery.max_upload_mb')`).
- Produces `GalleryException extends DomainException` with a public readonly `$translationKey` (mirrors `FileException`), static factories `unreadable()`, `tooLarge()`, `invalidType()`.

- [ ] **Step 1: Failing test** (uses `Storage::fake('local')` + a real small test JPEG fixture, or `UploadedFile::fake()->image(...)` which GD can process):

```php
// tests/Feature/Gallery/UploadPhotoTest.php
<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Actions\UploadPhoto;
use App\Modules\Gallery\Enums\PhotoVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

it('stores a pending photo plus a thumbnail on the private disk and records dimensions', function () {
    $event = Event::factory()->create();
    $actor = User::factory()->create();
    $file = UploadedFile::fake()->image('shot.jpg', 3000, 2000);

    $photo = app(UploadPhoto::class)->handle($event, $actor, $file);

    expect($photo->visibility)->toBe(PhotoVisibility::Pending)
        ->and($photo->uploaded_by)->toBe($actor->id)
        ->and($photo->width)->toBeLessThanOrEqual(2560)
        ->and($photo->width)->toBeGreaterThan(0);

    Storage::disk('local')->assertExists($photo->path);
    Storage::disk('local')->assertExists($photo->thumb_path);
});

it('sets the caption from the trusted argument, trimmed', function () {
    $event = Event::factory()->create();
    $actor = User::factory()->create();

    $photo = app(UploadPhoto::class)->handle(
        $event,
        $actor,
        UploadedFile::fake()->image('a.jpg', 800, 600),
        '  Nice shot  ',
    );

    expect($photo->caption)->toBe('Nice shot');
});
```

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement.** `composer require intervention/image-laravel:^4`; publish/config the GD driver. In `config/image.php` set `'driver' => Intervention\Image\Drivers\Gd\Driver::class`. `config/gallery.php`:

```php
// config/gallery.php
<?php

return [
    'max_upload_mb' => (int) env('GALLERY_MAX_UPLOAD_MB', 25),
    'max_edge' => (int) env('GALLERY_MAX_EDGE', 2560),
    'thumb_width' => (int) env('GALLERY_THUMB_WIDTH', 400),
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
];
```

`docker/Dockerfile`: add `gd` to both `install-php-extensions` invocations and rewrite the comment so it reads that `gd` IS now installed (for M12 gallery EXIF-strip + thumbnailing via intervention/image v4) while `imagick` stays out. `UploadPhoto`:

```php
// app/Modules/Gallery/Actions/UploadPhoto.php
<?php

namespace App\Modules\Gallery\Actions;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Exceptions\GalleryException;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Loads the upload via Intervention (GD driver), bakes EXIF orientation into
 * pixels with orient(), caps the longest edge, and re-encodes as JPEG — GD
 * drops ALL EXIF metadata (including GPS) on re-encode, so the stored
 * original carries no location/camera data. A 400px-wide thumbnail is stored
 * alongside. Both land on the private `local` disk; the row starts Pending.
 * Ownership + visibility are set from the trusted actor via forceFill(),
 * never from client input.
 */
class UploadPhoto
{
    public function __construct(private readonly ImageManager $manager) {}

    public function handle(Event $event, User $actor, UploadedFile $file, ?string $caption = null): EventPhoto
    {
        $this->guardSize($file);

        try {
            $image = $this->manager->read($file->getRealPath());
        } catch (Throwable) {
            throw GalleryException::unreadable();
        }

        $maxEdge = (int) config('gallery.max_edge');
        $image->orient()->scaleDown(width: $maxEdge, height: $maxEdge);
        $original = $image->encodeUsingFormat(Format::JPEG, quality: 82);

        $thumb = $this->manager->read((string) $original)
            ->scaleDown(width: (int) config('gallery.thumb_width'))
            ->encodeUsingFormat(Format::JPEG, quality: 80);

        $uuid = (string) Str::uuid();
        $dir = 'event-'.$event->id.'/photos';
        $path = $dir.'/'.$uuid.'.jpg';
        $thumbPath = $dir.'/'.$uuid.'-thumb.jpg';

        Storage::disk('local')->put($path, (string) $original);
        Storage::disk('local')->put($thumbPath, (string) $thumb);

        $trimmed = $caption === null ? null : trim($caption);

        $photo = new EventPhoto([
            'event_id' => $event->id,
            'caption' => $trimmed === '' ? null : $trimmed,
        ]);
        $photo->forceFill([
            'uploaded_by' => $actor->id,
            'path' => $path,
            'thumb_path' => $thumbPath,
            'width' => $image->size()->width(),
            'height' => $image->size()->height(),
            'visibility' => PhotoVisibility::Pending,
            'is_highlight' => false,
        ]);
        $photo->save();

        return $photo;
    }

    private function guardSize(UploadedFile $file): void
    {
        $maxBytes = (int) config('gallery.max_upload_mb') * 1024 * 1024;
        $size = $file->getSize();

        if ($size === false || $size > $maxBytes) {
            throw GalleryException::tooLarge();
        }

        $mime = $file->getMimeType();
        $allowed = config('gallery.allowed_mimes');

        if (! is_array($allowed) || ! in_array($mime, $allowed, true)) {
            throw GalleryException::invalidType();
        }
    }
}
```

> Note: add `use Illuminate\Support\Facades\Storage;` to the action. `ImageManager` is resolved from the container — bind it (or `Intervention\Image\Interfaces\ImageManagerInterface`) to `ImageManager::withDriver(config('image.driver'))` in a small binding inside `AppServiceProvider` if the package's Laravel service provider does not already provide a container binding for `ImageManager`. Verify against `intervention/image-laravel` v4 (it registers `ImageManager` in the container keyed on the configured driver — prefer that binding).

`GalleryException` mirrors `FileException` (private constructor, `public readonly string $translationKey`, static factories returning translation keys `gallery.errors.unreadable`/`gallery.errors.too_large`/`gallery.errors.invalid_type`). Add those keys to `lang/de/gallery.php`.

- [ ] **Step 4:** green + `composer check`. (If `UploadedFile::fake()->image()` output confuses GD orientation reads, use a committed fixture JPEG under `tests/Fixtures/` — document which.)
- [ ] **Step 5: Commit** — `feat(gallery): UploadPhoto action with EXIF-strip + thumbnail (intervention/image v4, gd)`.

---

### Task 3: Approve/reject/delete/highlight actions + authorized photo/thumb serving route

**Files:**
- Create: `app/Modules/Gallery/Actions/{ApprovePhoto,RejectPhoto,DeletePhoto,ToggleHighlight}.php`
- Create: `app/Modules/Gallery/Http/PhotoController.php` (serve full + thumb)
- Modify: `routes/web.php` (public gallery index placeholder added Task 4; here add the authenticated serving routes)
- Modify: `lang/de/gallery.php` (`actions` + `errors`)
- Test: `tests/Feature/Gallery/PhotoModerationTest.php`, `tests/Feature/Gallery/PhotoServingTest.php`

**Interfaces:**
- `ApprovePhoto::handle(EventPhoto, User $actor): EventPhoto` — `Gate::forUser($actor)->authorize('approve', $photo)`; `forceFill(['visibility' => Approved, 'reviewed_by' => actor->id, 'reviewed_at' => now()])`; broadcasts `GalleryUpdated` (created Task 7 — if ordering forces it, gate the dispatch behind class_exists or create a tiny stub event now and enrich in Task 7). **Decision:** create `GalleryUpdated` here (Task 3) as an empty-payload `ShouldBroadcast` on `event.{id}` (`.gallery.updated`) so approval already drives the beamer/gallery refresh; Task 7's scene consumes it.
- `RejectPhoto::handle(EventPhoto, User $actor): EventPhoto` — authorize `reject`; `forceFill(['visibility' => Rejected, …])`.
- `DeletePhoto::handle(EventPhoto): void` — deletes both `path` + `thumb_path` from `local`, then the row. (Authorization via the caller's `authorize('delete', …)`.)
- `ToggleHighlight::handle(EventPhoto, User $actor): EventPhoto` — authorize `highlight`; flips `is_highlight` via `forceFill`; broadcasts `GalleryUpdated`.
- `PhotoController::show(Request, EventPhoto)` → `authorize('view', $photo)` → `Storage::disk('local')->response($photo->path)`; `PhotoController::thumb(Request, EventPhoto)` → same authorize → serves `thumb_path`. Both under the `auth` group. (Approved photos are viewable by anyone via the policy's `view`; the pending ones only by owner/orga.)

- [ ] **Step 1: Failing tests:**

```php
// tests/Feature/Gallery/PhotoModerationTest.php
<?php

use App\Models\User;
use App\Modules\Gallery\Actions\ApprovePhoto;
use App\Modules\Gallery\Actions\RejectPhoto;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets a helper approve a pending photo and stamps the reviewer', function () {
    $helper = User::factory()->helper()->create();
    $photo = EventPhoto::factory()->create();

    app(ApprovePhoto::class)->handle($photo, $helper);

    expect($photo->fresh())
        ->visibility->toBe(PhotoVisibility::Approved)
        ->reviewed_by->toBe($helper->id);
});

it('forbids a plain participant from approving', function () {
    $photo = EventPhoto::factory()->create();

    app(ApprovePhoto::class)->handle($photo, User::factory()->create());
})->throws(AuthorizationException::class);
```

```php
// tests/Feature/Gallery/PhotoServingTest.php — pending photo is invisible to a stranger
it('serves an approved photo to any authenticated viewer but 403s a stranger on a pending one', function () {
    Storage::fake('local');
    $approved = EventPhoto::factory()->approved()->create();
    Storage::disk('local')->put($approved->path, 'x');
    $pending = EventPhoto::factory()->create();
    Storage::disk('local')->put($pending->path, 'y');

    $viewer = User::factory()->create();
    $this->actingAs($viewer)->get("/gallery/photos/{$approved->id}")->assertOk();
    $this->actingAs($viewer)->get("/gallery/photos/{$pending->id}")->assertForbidden();
})->uses(RefreshDatabase::class);
```

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** the four actions (each mirroring `ApproveSharedFile`/`DeleteSharedFile` exactly), `GalleryUpdated` (empty `broadcastWith()`, `broadcastAs('gallery.updated')`, `broadcastOn(new Channel('event.'.$this->eventId))`), and `PhotoController`. Routes (inside the `auth` group in `routes/web.php`):

```php
Route::get('/gallery/photos/{eventPhoto}', [PhotoController::class, 'show'])->name('gallery.photos.show');
Route::get('/gallery/photos/{eventPhoto}/thumb', [PhotoController::class, 'thumb'])->name('gallery.photos.thumb');
```

Add `actions` (`approve`/`reject`/`delete`/`highlight`/`unhighlight`) + remaining `errors` to `lang/de/gallery.php`.

- [ ] **Step 4:** green + `composer check`.
- [ ] **Step 5: Commit** — `feat(gallery): approve/reject/delete/highlight actions + authorized photo serving`.

---

### Task 4: Participant gallery page (Inertia + Vue) + upload endpoint (registered-participant gate)

> **Invoke the `frontend-design` skill first**; design against `docs/design.md`.

**Files:**
- Create: `app/Modules/Gallery/Http/GalleryPageController.php` (`index` + `store` + `destroy`)
- Create: `app/Modules/Gallery/Support/GalleryQuery.php` (ordered read-model — reused by the beamer scene + recap)
- Modify: `routes/web.php` (public `gallery.index`; auth `gallery.store`, `gallery.destroy`)
- Create: `resources/js/pages/Gallery/Index.vue`, `resources/js/components/gallery/{PhotoGrid,PhotoLightbox,PhotoUpload}.vue`, `resources/js/types/gallery.ts`
- Modify: `lang/de/gallery.php` (`page` block), add a nav entry alongside files/jukebox
- Test: `tests/Feature/Gallery/GalleryPageTest.php`, `tests/Feature/Gallery/GalleryUploadEndpointTest.php`, `tests/Unit/Gallery/GalleryQueryTest.php`

**Interfaces:**
- `GalleryQuery` (module-boundary-clean read-model, mirrors `JukeboxQueue`): `approvedFor(Event): Collection<EventPhoto>` — `visibility===Approved`, ordered `is_highlight desc, created_at desc, id desc`, eager-loads `uploader`; `highlightsFor(Event, int $limit = 6): Collection<EventPhoto>` — highlights first, else most-recent approved, capped. Pure, no IO beyond its own model.
- `GalleryPageController::index(Request, Event)` → `abort_unless($event->isPubliclyVisible(), 404)` → Inertia `Gallery/Index` with props: `event` (`{name, slug}`), `photos` (approved via `GalleryQuery` + the viewer's own pending, each a DTO `{id, thumbUrl: route('gallery.photos.thumb', id), fullUrl: route('gallery.photos.show', id), caption, uploaderName, visibility, mine, isHighlight}`), `canUpload` (bool — computed from the request user's registration, see below), `labels`, `canDownloadZip` (bool, Task 6 uses it; default false here).
- **Upload gate decision (spec open detail → resolved): registered participant.** `canUpload = $user !== null && EventRegistration::where('event_id', $event->id)->where('user_id', $user->id)->whereNotIn('status', [Cancelled])->exists()`. This is a controller-level check (documented in the controller); the `EventPhotoPolicy::create` stays `true` (mechanism), the *eligibility* gate is the registration existence, so participants can add photos during/after the LAN even without an active check-in (lower risk than the jukebox check-in gate). `store` re-checks it and 403s otherwise.
- `store(Request, Event, UploadPhoto)` → `abort_unless(isPubliclyVisible, 404)`; re-assert the registered-participant gate; validate `photos.*` are images; loop `UploadPhoto::handle($event, $this->authUser($request), $file, $caption)` catching `GalleryException` → German flash; `back()`.
- `destroy(Request, EventPhoto, DeletePhoto)` → `authorize('delete', $photo)` → delete → `back()`.

- [ ] **Step 1: Failing tests** — page renders the component with approved-only for a stranger + own-pending for the uploader; `canUpload` true only for a registered participant; upload endpoint 403s a non-registered user; `GalleryQuery` orders highlights first.

```php
// tests/Feature/Gallery/GalleryPageTest.php
it('shows approved photos to everyone and the uploader their own pending one', function () {
    $event = Event::factory()->announced()->create();
    $approved = EventPhoto::factory()->approved()->create(['event_id' => $event->id]);
    $uploader = User::factory()->create();
    $mine = EventPhoto::factory()->create(['event_id' => $event->id, 'uploaded_by' => $uploader->id]);
    EventPhoto::factory()->create(['event_id' => $event->id]); // someone else's pending → hidden

    $this->actingAs($uploader)
        ->get("/events/{$event->slug}/gallery")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Gallery/Index')
            ->has('photos', 2)
            ->where('canUpload', false));
})->uses(RefreshDatabase::class);
```

- [ ] **Step 2: Run → FAIL.** **Step 3:** implement controller/read-model/routes, then the Vue (frontend-design): thumbnail grid (lazy + width/height set), lightbox fetching `fullUrl` on click, `PhotoUpload` multi-file control visible only when `canUpload`, a "wird geprüft" marker on `mine && visibility==='pending'`, all four states, `font-mono` for counts only, German copy. Route:

```php
Route::get('/events/{event:slug}/gallery', [GalleryPageController::class, 'index'])->name('gallery.index'); // public
// inside auth group:
Route::post('/events/{event:slug}/gallery', [GalleryPageController::class, 'store'])->name('gallery.store');
Route::delete('/gallery/photos/{eventPhoto}', [GalleryPageController::class, 'destroy'])->name('gallery.destroy');
```

- [ ] **Step 4:** backend green + 4 frontend gates green → **preview-verify** (or documented fallback).
- [ ] **Step 5: Commit** — `feat(gallery): participant gallery page with registered-participant upload gate`.

---

### Task 5: Filament `EventPhotoResource` approval queue + is_highlight toggle

**Files:**
- Create: `app/Modules/Gallery/Filament/Resources/EventPhotos/EventPhotoResource.php`, `…/Pages/ListEventPhotos.php`, `…/Tables/EventPhotosTable.php`
- Modify: `lang/de/gallery.php` (`resource` + `fields`)
- Test: `tests/Feature/Gallery/EventPhotoResourceAccessTest.php`

**Interfaces:** mirrors `SharedFileResource` exactly. `EventPhotoResource`: `$model = EventPhoto::class`, navigation icon `Heroicon::OutlinedPhoto`, `navigationGroup = AdminNavigationGroup::TurniereUndTeams`, model labels from `gallery.resource.*`, `getPages()` → `['index' => ListEventPhotos::route('/')]`. `EventPhotosTable`: columns thumbnail (`ImageColumn` from a signed/authorized thumb URL, or a plain thumbnail preview — since the disk is private, render via `route('gallery.photos.thumb', $record)` in a `TextColumn`/`ImageColumn` `->url`), event.name, uploader.name, caption, visibility badge (Pending=warning/Approved=success/Rejected=danger via `label()`), `is_highlight` `IconColumn`. Row actions: `approve` (`->authorize('approve')`, visible when not Approved, calls `app(ApprovePhoto::class)->handle($record, self::actor())`), `reject` (`->authorize('reject')`, visible when not Rejected), `toggleHighlight` (`->authorize('highlight')`, calls `ToggleHighlight`). `self::actor()` helper identical to `SharedFilesTable::actor()`. `ListEventPhotos` has no CreateAction (rows only from uploads).

- [ ] **Step 1: Failing test** — participant 403 on `/admin/event-photos`; orga 200; approve row action flips visibility + stamps reviewer (Livewire `callTableAction`). **Step 2:** FAIL. **Step 3:** implement. **Step 4:** green + `composer check`. **Step 5: Commit** — `feat(gallery): Filament approval queue with is_highlight toggle`.

---

### Task 6: Zip download of approved photos (ZipArchive, Finished/Archived gate)

**Files:**
- Create: `app/Modules/Gallery/Actions/BuildEventPhotoZip.php`
- Modify: `app/Modules/Gallery/Http/GalleryPageController.php` (`downloadZip`) or a dedicated method; add `download(User, Event)` ability to `EventPhotoPolicy`
- Modify: `routes/web.php` (auth `gallery.zip`), `lang/de/gallery.php`
- Test: `tests/Feature/Gallery/GalleryZipTest.php`

**Interfaces:**
- Add `EventPhotoPolicy::downloadZip(User $user, Event $event): bool = true` (any authenticated viewer of a public event; the state gate is on event status, enforced in the controller — keeping the policy about "who", the controller about "when").
- `BuildEventPhotoZip::handle(Event $event): string` — collects the event's **Approved** photos via `GalleryQuery::approvedFor`, writes a temp zip under `storage/app/private/tmp/event-{id}-photos-{uuid}.zip` using native `ZipArchive`, adding each `path`'s bytes as `photo-{n}.jpg` (deriving from `Storage::disk('local')->path($photo->path)` when the local driver is filesystem-backed, else `->get()` bytes into the archive via `addFromString`). Returns the temp path. Uses `addFromString` so it works with any Storage driver.
- `downloadZip(Request, Event)` → `abort_unless($event->isPubliclyVisible(), 404)`; `abort_unless(in_array($event->status, [EventStatus::Finished, EventStatus::Archived], true), 403)`; `$this->authorize('downloadZip', [EventPhoto::class, $event])`; `$path = BuildEventPhotoZip::handle($event)`; `return response()->download($path, "…-fotos.zip")->deleteFileAfterSend();`.

- [ ] **Step 1: Failing tests:**

```php
// tests/Feature/Gallery/GalleryZipTest.php
it('streams a zip of approved photos once the event is finished', function () {
    Storage::fake('local');
    $event = Event::factory()->create(['status' => EventStatus::Finished]);
    $photo = EventPhoto::factory()->approved()->create(['event_id' => $event->id]);
    Storage::disk('local')->put($photo->path, 'JPEGBYTES');

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/gallery/zip")
        ->assertOk()
        ->assertHeader('content-type', 'application/zip');
})->uses(RefreshDatabase::class);

it('403s the zip while the event is still live', function () {
    $event = Event::factory()->live()->create();
    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/gallery/zip")
        ->assertForbidden();
})->uses(RefreshDatabase::class);
```

- [ ] **Step 2: FAIL.** **Step 3:** implement (`ZipArchive`; ensure the temp dir exists via `Storage::disk('local')->makeDirectory('tmp')` or `mkdir`). Route in the auth group: `Route::get('/events/{event:slug}/gallery/zip', [GalleryPageController::class, 'downloadZip'])->name('gallery.zip');`. Wire `canDownloadZip` in the Task-4 `index` prop (`status ∈ {Finished, Archived}`). **Step 4:** green + `composer check`. **Step 5: Commit** — `feat(gallery): approved-photo zip download gated to finished/archived events`.

---

### Task 7: `SceneType::Gallery` slideshow beamer scene (no-PII payload)

> **Invoke the `frontend-design` skill first**; design against `docs/design.md`.

**Files:**
- Modify: `app/Modules/Infoscreen/Enums/SceneType.php` (+`Gallery = 'gallery'`)
- Modify: `app/Modules/Infoscreen/Support/ScenePayload.php` (+`galleryData` match arm + method)
- Create: `resources/js/components/scenes/SceneGallery.vue`
- Modify: `resources/js/pages/Screen/Show.vue` (register `gallery` in `sceneComponents`; add a `.gallery.updated` reload listener on `event.{id}` next to the `.jukebox.updated` one), `resources/js/types/infoscreen.ts` if a scene type union lists cases
- Modify: `lang/de/infoscreen.php` (`type.gallery` label)
- Test: `tests/Feature/Gallery/GallerySceneTest.php`

**Interfaces:**
- `ScenePayload::galleryData(InfoscreenScene): array{photos: list<array{url: string, caption: string|null}>}` — resolves the scene's event, pulls approved photos via `GalleryQuery::approvedFor` (module read-model — never a raw `event_photos` query from Infoscreen), maps to **public-only** `{url: route('gallery.photos.show', $photo), caption: $photo->caption}`. **No uploader name, no ids, no visibility, no `uploaded_by`.** Empty event → `['photos' => []]`.
- `SceneGallery.vue` = loud beamer register: full-bleed rotating slideshow of `data.photos` (CSS/JS timed crossfade, `prefers-reduced-motion` → no crossfade), optional caption overlay, `LiveIndicator` optional; lazy + sized images. The `.gallery.updated` listener triggers `router.reload({ only: ['scenes'] })` (same mechanism the jukebox uses) so newly approved photos appear.

- [ ] **Step 1: Failing no-PII payload test:**

```php
// tests/Feature/Gallery/GallerySceneTest.php
it('builds a gallery scene payload with only public photo url + caption (no PII)', function () {
    $event = Event::factory()->create();
    $scene = InfoscreenScene::factory()->for($event)->create(['type' => SceneType::Gallery]);
    $photo = EventPhoto::factory()->approved()->create(['event_id' => $event->id, 'caption' => 'Finale']);

    $payload = ScenePayload::for($scene);

    expect($payload['data']['photos'][0])
        ->toHaveKey('url')
        ->toHaveKey('caption')
        ->not->toHaveKey('uploaderName')
        ->not->toHaveKey('uploaded_by')
        ->not->toHaveKey('id')
        ->and($payload['data']['photos'][0]['caption'])->toBe('Finale');
})->uses(RefreshDatabase::class);
```

- [ ] **Step 2: FAIL.** **Step 3:** implement (frontend-design). **Step 4:** backend + 4 frontend gates green. **Step 5: Commit** — `feat(gallery): gallery slideshow beamer scene (no-PII payload)`.

---

### Task 8: `RecapProjection` read-model (exhaustively unit-tested)

**Files:**
- Create: `app/Modules/Recap/Support/RecapProjection.php` + the `RecapBoard` DTO (+ sub-DTOs) in the same namespace
- Create (if the "songs played" count is cheap): `app/Modules/Jukebox/Support/JukeboxStats.php` — `playedCount(Event): int` (a Jukebox read-model, keeps the module boundary — Recap never queries `jukebox_items` directly)
- Test: `tests/Unit/Recap/RecapProjectionTest.php`

**Interfaces:**
- `RecapProjection::forEvent(Event $event): RecapBoard` — pure, IO-free-shaped (same discipline as `PresenceProjection`), aggregating already-public data **via other modules' read-models**, never raw cross-module tables:
  - **Counts:** participants (`event->registrations()->count()`), tournaments (`event->tournaments()->count()`), matches played (count of `Completed` matches in the event's tournaments), and — if cheap — `songsPlayed` via `JukeboxStats::playedCount`.
  - **Podiums/winners:** the event's finished tournaments' champions + a small leaderboard. Reuse `Stats\LeaderboardQuery` for badge-annotated names where it fits; for per-event tournament winners, read each tournament's `winner_entry_id` and resolve the entry's display name (mirroring how `presenceData`/existing projections resolve entry names). Shape as `list<{tournamentName, winnerName}>`.
  - **Top photos:** `GalleryQuery::highlightsFor($event, 6)` → `list<{url: route('gallery.photos.show', …), caption}>` (public only).
  - **MVP:** the closed MVP poll's winner (Task 12/13) → `?{name}` (null when no MVP poll or not closed).
- `RecapBoard` (readonly): `participantCount: int`, `tournamentCount: int`, `matchesPlayed: int`, `songsPlayed: ?int`, `podiums: list<...>`, `topPhotos: list<...>`, `mvp: ?array{name: string}`; `toArray(): array` (camelCase). Sub-DTOs `PodiumEntry`, `RecapPhoto` readonly with `toArray()`.

- [ ] **Step 1: Failing exhaustive unit test** — cover: empty event (all zeros/empty/null), counts correct, podium lists finished tournaments' winners, `topPhotos` prefers highlights then recent approved and excludes pending/rejected, `mvp` null without a closed MVP poll, `toArray()` camelCase keys, no N+1 (assert bounded query count like `PresenceProjectionTest`).

```php
// tests/Unit/Recap/RecapProjectionTest.php (representative)
it('returns an empty board for an event with no activity', function () {
    $board = RecapProjection::forEvent(Event::factory()->create())->toArray();
    expect($board)
        ->participantCount->toBe(0)
        ->and($board['topPhotos'])->toBe([])
        ->and($board['mvp'])->toBeNull();
})->uses(RefreshDatabase::class);

it('prefers highlighted photos, excluding pending ones', function () {
    $event = Event::factory()->create();
    EventPhoto::factory()->create(['event_id' => $event->id]); // pending → excluded
    $hi = EventPhoto::factory()->highlight()->create(['event_id' => $event->id, 'caption' => 'Star']);

    $board = RecapProjection::forEvent($event)->toArray();
    expect($board['topPhotos'])->toHaveCount(1)
        ->and($board['topPhotos'][0]['caption'])->toBe('Star');
})->uses(RefreshDatabase::class);
```

- [ ] **Step 2: FAIL.** **Step 3:** implement (keep MVP resolution behind a small helper reading the closed `PollKind::Mvp` poll — this couples to Task 12; if executing Task 8 before 12, return `null` for `mvp` and enrich in Task 13, noting the TODO). **Step 4:** green + `composer check`. **Step 5: Commit** — `feat(recap): pure RecapProjection read-model over public module data`.

---

### Task 9: Public recap page `/events/{event}/recap` + `SceneType::Recap` beamer scene

> **Invoke the `frontend-design` skill first**; design against `docs/design.md`.

**Files:**
- Create: `app/Modules/Recap/Http/RecapPageController.php`
- Modify: `routes/web.php` (public `recap.show`)
- Create: `resources/js/pages/Recap/Show.vue`, `resources/js/types/recap.ts`
- Modify: `app/Modules/Infoscreen/Enums/SceneType.php` (+`Recap = 'recap'`), `app/Modules/Infoscreen/Support/ScenePayload.php` (+`recapData`), `resources/js/pages/Screen/Show.vue` (register `recap`), `resources/js/components/scenes/SceneRecap.vue`, `lang/de/infoscreen.php` (`type.recap`), `lang/de/recap.php`
- Test: `tests/Feature/Recap/RecapPageTest.php`, `tests/Feature/Recap/RecapSceneTest.php`

**Interfaces:**
- `RecapPageController::show(Event)` → `abort_unless($event->isPubliclyVisible(), 404)`; `abort_unless(in_array($event->status, [EventStatus::Finished, EventStatus::Archived], true), 404)` (recap exists only once the event has wrapped); Inertia `Recap/Show` with `event` (`{name, slug}`), `recap` = `RecapProjection::forEvent($event)->toArray()`, `labels` = `trans('recap.page')`.
- `ScenePayload::recapData(InfoscreenScene): array` = `RecapProjection::forEvent($event)->toArray()` — already public/no-PII (display names + public photo urls only). Empty event → the projection's own empty board.
- `SceneRecap.vue` loud register: the night's headline numbers + podium + top photos, celebratory; `SceneRecap` reads `data` shaped like `RecapBoard::toArray()`.

- [ ] **Step 1: Failing tests** — recap page 404 for a Live event, 200 for Finished with the projection props; recap scene payload carries no PII keys (`not->toHaveKey('uploaded_by')` on top photos, only display names on podium).
- [ ] **Step 2: FAIL.** **Step 3:** implement (frontend-design). **Step 4:** backend + 4 frontend gates green. **Step 5: Commit** — `feat(recap): public recap page + recap beamer scene`.

---

### Task 10: News module — model + Filament resource + homepage block

> **Invoke the `frontend-design` skill first** for the homepage block; design against `docs/design.md`.

**Files:**
- Create: `app/Modules/News/Models/NewsPost.php`, `database/migrations/2026_07_21_110000_create_news_posts_table.php`, `database/factories/NewsPostFactory.php`, `app/Modules/News/Policies/NewsPostPolicy.php`, `app/Modules/News/Support/NewsQuery.php`
- Create: `app/Modules/News/Filament/Resources/NewsPosts/{NewsPostResource.php, Pages/{ListNewsPosts,CreateNewsPost,EditNewsPost}.php, Schemas/NewsPostForm.php, Tables/NewsPostsTable.php}`
- Modify: `app/Modules/Events/Http/EventPageController.php` (`renderShow` passes published news), `resources/js/pages/Event/Show.vue` (news block), `app/Providers/AppServiceProvider.php` (register policy)
- Create: `lang/de/news.php`
- Test: `tests/Feature/News/NewsResourceAccessTest.php`, `tests/Feature/News/HomepageNewsTest.php`, `tests/Unit/News/NewsQueryTest.php`

**Interfaces:**
- `NewsPost`: `$fillable = ['title', 'body']`; `published_at` (nullable datetime) NOT fillable — set via a Filament action / `forceFill` (state field); `author_id` NOT fillable — set from the authenticated user in the Create page's `mutateFormDataBeforeCreate`/`->beforeCreate`. Cast `published_at => 'datetime'`. Relation `author()` (BelongsTo User). Migration: `title` string, `body` text, `published_at` timestamp nullable, `author_id` FK users nullOnDelete, timestamps; index `published_at`.
- `NewsPolicy`: `viewAny = isOrga`, `create/update/delete = isOrga`, `publish = isOrga`.
- `NewsQuery::published(int $limit = 3): Collection<NewsPost>` — `whereNotNull('published_at')->where('published_at', '<=', now())->orderByDesc('published_at')->limit($limit)`. Read-model consumed by the homepage.
- `renderShow` adds `news` prop = `NewsQuery::published(3)` mapped to `{id, title, body, publishedAt}`. `Event/Show.vue` renders a small "Neuigkeiten" block above/below the event DL (calm app register). Because `home()` renders `Event/Show`, the news block shows on the homepage.
- Filament resource: full orga CRUD (this one HAS a form + create/edit pages, unlike the moderation-only resources). Author set from `Auth::user()` in the create page (never a form field). A `publish`/`unpublish` toggle sets `published_at` via `forceFill` in an action (or a form field guarded so only orga reaches it).

- [ ] **Step 1: Failing tests** — homepage shows only published posts (a future-dated / draft one is hidden); participant 403 on `/admin/news-posts`; orga can create (author auto-set). **Step 2: FAIL.** **Step 3:** implement (frontend-design for the block). **Step 4:** backend + 4 frontend gates green. **Step 5: Commit** — `feat(news): global news posts with Filament CRUD and homepage block`.

---

### Task 11: Countdown/hype mode on the event page + `Event.arrival_info`

> **Invoke the `frontend-design` skill first**; design against `docs/design.md`.

**Files:**
- Create: `database/migrations/2026_07_21_120000_add_arrival_info_to_events_table.php`
- Modify: `app/Modules/Events/Models/Event.php` (`$fillable` += `arrival_info`; it is content, not privilege — fillable is fine), `database/factories/EventFactory.php` (optional state)
- Modify: `app/Modules/Events/Http/EventPageController.php` (`summary` += `arrivalInfo`; add hype props), `resources/js/pages/Event/Show.vue` (countdown section), `resources/js/types/events.ts` (`arrivalInfo`, hype fields), the Filament `EventResource` form (add an `arrival_info` textarea field)
- Modify: `lang/de/events.php` (countdown/hype/arrival labels)
- Test: `tests/Feature/Events/CountdownModeTest.php`

**Interfaces:**
- Migration: `$table->text('arrival_info')->nullable();` after `location`.
- `EventPageController::renderShow`/`summary` adds `arrivalInfo => $event->arrival_info`, and hype props computed only when active: `hype => $this->hypeFor($event)` returning `null` unless `in_array($event->status, [Announced, Registration], true) && $event->starts_at !== null && $event->starts_at->isFuture()`; when active: `{ startsAt: iso, registrationCount: $event->registrations()->whereNot('status', Cancelled)->count(), activePoll: <the event's open M4 poll summary or null> }`. The countdown itself ticks client-side from `startsAt` (no server write path — pure display).
- `Event/Show.vue`: when `hype` is present, render a countdown (client-side `setInterval`, cleared on unmount, `prefers-reduced-motion` respected — no flashy animation), a "Wer kommt" count (`font-mono` for the number), the active game-vote teaser (link to the poll), and `arrivalInfo`. Calm app register; amber rationed to at most the countdown accent.

- [ ] **Step 1: Failing test:**

```php
// tests/Feature/Events/CountdownModeTest.php
it('exposes hype props for an announced future event', function () {
    $event = Event::factory()->create(['status' => EventStatus::Announced, 'starts_at' => now()->addDays(14)]);
    EventRegistration::factory()->count(3)->create(['event_id' => $event->id]);

    $this->get("/events/{$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('event.hype.registrationCount', 3)
            ->where('event.hype.startsAt', $event->starts_at->toIso8601String()));
})->uses(RefreshDatabase::class);

it('omits hype for a finished event', function () {
    $event = Event::factory()->create(['status' => EventStatus::Finished, 'starts_at' => now()->subDay()]);
    $this->get("/events/{$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('event.hype', null));
})->uses(RefreshDatabase::class);
```

> Confirm the exact prop nesting (`event.hype.*` vs a sibling `hype` prop) against `EventPageController::summary`'s current shape when implementing; the test above assumes it nests under `event`. Adjust the test to whichever the controller produces, keeping the assertion.

- [ ] **Step 2: FAIL.** **Step 3:** implement (frontend-design). **Step 4:** backend + 4 frontend gates green. **Step 5: Commit** — `feat(events): pre-LAN countdown/hype mode + arrival_info field`.

---

### Task 12: MVP-poll flavour in Voting + auto-seed options helper

**Files:**
- Create: `app/Modules/Voting/Enums/PollKind.php`
- Create: `database/migrations/2026_07_21_130000_add_kind_to_polls_table.php`
- Modify: `app/Modules/Voting/Models/Poll.php` (cast `kind`; NOT `$fillable` if orga picks it only via the seed action — decision: keep `kind` out of `$fillable`, set via the seed action's `forceFill`), `database/factories/PollFactory.php` (an `->mvp()` state)
- Create: `app/Modules/Voting/Actions/SeedMvpPoll.php`
- Modify: `app/Modules/Voting/Support/` (if a poll-lookup read-model exists) or add `app/Modules/Voting/Support/MvpPollQuery.php` — `closedFor(Event): ?Poll` and `winner(Poll): ?PollOption`
- Modify: `lang/de/polls.php` (MVP kind label + seed copy)
- Test: `tests/Feature/Voting/SeedMvpPollTest.php`, `tests/Unit/Voting/MvpPollQueryTest.php`

**Interfaces:**
- `PollKind` (string enum): `Standard = 'standard'`, `Mvp = 'mvp'`; `label()` → `__('polls.kind.'.$this->value)`.
- Migration: `$table->string('kind')->default('standard');` on `polls`. Model casts `kind => PollKind::class`; `kind` NOT `$fillable` (set via `forceFill` in the seed action, mirroring `status`).
- `SeedMvpPoll::handle(Event $event, User $actor): Poll` — `Gate::forUser($actor)->authorize('create', Poll::class)` (orga); guards against a second MVP poll for the event (throw `VotingException::mvpPollExists()` if one already exists); creates a `Draft` poll with `question = trans('polls.mvp.question')`, `forceFill(['kind' => PollKind::Mvp])`, and **auto-seeds one `PollOption` per event participant** (registered, non-cancelled) labelled by user display name (orga can then edit/remove options before opening — the existing option management stays). Uses the existing `OpenPoll`/`ClosePoll` for the lifecycle (unchanged).
- `MvpPollQuery::closedFor(Event): ?Poll` — the event's `PollKind::Mvp` poll with `status===Closed`; `winner(Poll): ?PollOption` — the option with the most votes (tie → the earliest `sort`/`id`, deterministic).

- [ ] **Step 1: Failing tests** — seeding creates a Draft MVP poll with one option per participant; a second seed throws; `winner` returns the top-tallied option deterministically.

```php
it('seeds one option per registered participant for the MVP poll', function () {
    $event = Event::factory()->create();
    $orga = User::factory()->orga()->create();
    EventRegistration::factory()->count(4)->create(['event_id' => $event->id]);

    $poll = app(SeedMvpPoll::class)->handle($event, $orga);

    expect($poll->kind)->toBe(PollKind::Mvp)
        ->and($poll->status)->toBe(PollStatus::Draft)
        ->and($poll->options()->count())->toBe(4);
})->uses(RefreshDatabase::class);
```

- [ ] **Step 2: FAIL.** **Step 3:** implement; add an orga entry point (a Filament header action on the event's poll resource, or a simple button — reuse the existing poll open/close UI). **Step 4:** green + `composer check`. **Step 5: Commit** — `feat(voting): MVP-of-the-night poll kind with auto-seeded participant options`.

---

### Task 13: `mvp_of_the_night` computed badge + beamer reveal + wire into RecapProjection

**Files:**
- Create: `app/Modules/Stats/Support/EventBadgeCalculator.php` (event-scoped badges — see the note below on why NOT `BadgeCalculator::for`)
- Modify: `app/Modules/Recap/Support/RecapProjection.php` (populate `mvp` via `MvpPollQuery`)
- Create: `app/Modules/Voting/Actions/RevealMvp.php` (or extend `ClosePoll` for the MVP kind) — broadcasts a `SceneOverride` reveal reusing the tombola/winner pattern
- Modify: `resources/js/components/scenes/` — reuse `SceneWinner.vue`/`SceneTombola.vue` for the reveal (a synthetic `winner`/tombola-style override), or add a thin `mvp` override; register if new
- Modify: `lang/de/stats.php` (badge label `mvp_of_the_night`), `lang/de/polls.php` (reveal copy)
- Test: `tests/Unit/Stats/EventBadgeCalculatorTest.php`, `tests/Feature/Voting/RevealMvpTest.php`

**Interfaces:**
- **Decision (spec left open how the badge fits):** `BadgeCalculator::for(int, CompetitorKind)` is **cross-event** (aggregates all tournaments, no event scope) and returns cross-event badges — the MVP is inherently per-event (per closed MVP poll). So add `EventBadgeCalculator::forEvent(Event $event): array<int, list<string>>` mapping `userId → ['mvp_of_the_night']` for the event's closed-MVP-poll winner (computed, never stored — consistent with the badge philosophy). RecapProjection uses it for the `mvp` block; the recap/badge surfaces display `stats.badges.mvp_of_the_night`. (Do NOT bolt an event argument onto the existing `BadgeCalculator::for` — keep the cross-event method pure.)
- `RecapProjection` `mvp` = `MvpPollQuery::closedFor($event)` → `winner()` → the winning option's participant name (null when no closed MVP poll).
- `RevealMvp::handle(Poll $poll, User $actor): void` — authorize `close` (orga); ensures the poll is the event's closed MVP poll; dispatches a `SceneOverride($event->id, ['type' => SceneType::Winner->value, 'durationSec' => 20, 'config' => [], 'data' => ['title' => trans('polls.mvp.reveal_title'), 'name' => <winner name>]])` — reusing the existing `SceneWinner`/`SceneOverride` reveal path (already-public winner name only; mirrors `DrawTombola::winnerData`). If `SceneWinner`'s data shape differs, map to it exactly (read `SceneWinner.vue` when implementing).

- [ ] **Step 1: Failing tests** — `EventBadgeCalculator::forEvent` returns `mvp_of_the_night` for the closed poll's winner and empty otherwise; `RevealMvp` dispatches a `SceneOverride` carrying only the public winner name (assert `not->toHaveKey('user_id')`).

```php
it('awards mvp_of_the_night to the closed MVP poll winner', function () {
    // build event + closed MVP poll where option for $winnerUser has the most votes
    $badges = EventBadgeCalculator::forEvent($event);
    expect($badges[$winnerUser->id])->toContain('mvp_of_the_night');
})->uses(RefreshDatabase::class);
```

- [ ] **Step 2: FAIL.** **Step 3:** implement; wire the reveal into the orga close flow (a Filament action or the poll page). **Step 4:** green + `composer check` (+ frontend gates if a scene component changes). **Step 5: Commit** — `feat(stats): computed mvp_of_the_night badge + beamer reveal wired into recap`.

---

### Task 14: Docs — architecture, roadmap Erkenntnisse, compose/env notes

**Files:**
- Modify: `docs/architecture.md` (add M12 sections: Gallery module — private-disk + moderation gate + EXIF-strip pipeline + zip; Recap module — pure projection over public read-models; News module; the countdown/hype extension; the MVP-poll flavour + computed event badge; the two new beamer scenes; the `gd` addition + intervention/image v4 + native ZipArchive decisions)
- Modify: `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` (Erkenntnisse M12: the registered-vs-checked-in upload decision, the GD-drops-EXIF strip approach, the event-scoped-badge-not-`BadgeCalculator::for` decision, the reuse of `SceneWinner`/`SceneOverride` for the MVP reveal, songs-played inclusion outcome)
- Modify: `.env.example` (`GALLERY_*` vars), and a note in `README`/compose docs that the app image now needs `gd` (rebuild required)
- Test: none (docs/config) — `composer check` stays green.

- [ ] **Step 1:** write docs + env notes. **Step 2:** `composer check` untouched-green. **Step 3: Commit** — `docs(m12): architecture, roadmap Erkenntnisse, and env/compose notes`.

---

## Self-Review (done at plan-writing time)

- **Spec coverage:** #15a Gallery — schema/model/policy (1), upload+EXIF-strip+gd (2), moderation actions + serving route (3), participant UI + registered-upload gate (4), Filament approval queue + highlight (5), zip download (6), gallery beamer scene (7). #15b Recap — projection (8), public page + recap scene (9). #15c News — model/Filament/homepage (10). #16 Countdown/hype + `arrival_info` (11). #17 MVP poll flavour + auto-seed (12), computed badge + reveal + recap wiring (13). Docs (14). ✓ All three roadmap items covered; #18 correctly out of scope.
- **Open details resolved (flagged for the controller):** upload gate = **registered participant** (not checked-in); MVP options = **auto-seeded, orga-editable**; songs-played = **included only if cheap** (via `JukeboxStats`, else omitted — Task 8 decides at implementation).
- **Design-decision flagged for adjudication:** the `mvp_of_the_night` badge is delivered via a NEW event-scoped `EventBadgeCalculator::forEvent`, deliberately NOT by adding an event parameter to the cross-event `BadgeCalculator::for` — because that method has no event scope and returns cross-event badges. If the controller prefers a unified badge surface, that's the one thing to reconsider.
- **Privilege/state fields:** `visibility`, `is_highlight`, `reviewed_by`/`reviewed_at`, `uploaded_by`, poll `status` + `kind`, news `published_at`/`author_id` — all excluded from `$fillable`, set via Actions/`forceFill`. ✓
- **No-PII beamer payloads:** gallery scene (url+caption only), recap scene (display names + public photo urls), MVP reveal (winner name only) — each has a `not->toHaveKey(...)` assertion. ✓
- **Reverb invariant:** `GalleryUpdated` is empty-payload on public `event.{id}`; the reveal `SceneOverride` carries only already-public winner data (established exception, same as `DrawTombola`). ✓
- **Type consistency:** `PhotoVisibility`/`EventPhoto`/`GalleryQuery` flow 1→7; `RecapProjection`/`RecapBoard` 8→9,13; `PollKind`/`MvpPollQuery` 12→13,8; `EventBadgeCalculator` 13→(recap). `frontend-design` invoked before every Vue/scene task (4,7,9,10,11).
- **Placeholder scan:** none — concrete migrations/enums/signatures/tests throughout; Task 14 explicitly docs-only.

## Execution Handoff

Execute via **subagent-driven-development**: a fresh implementer per task 1→14, `scripts/review-package` + task-reviewer between tasks. Run **opus** per-task review on:
- **Task 3** — the moderation-gate authorization + private-disk serving (the no-leak: pending photos invisible to strangers, `Gate::forUser` in-action).
- **Task 4** — the registered-participant upload gate (client-supplied-id-proof; eligibility computed from `auth()->user()`'s registration only).
- **Task 7 & Task 9** — the no-PII beamer payloads (assert absence of PII keys; module-boundary read-models).
- **Task 13** — the computed event-scoped badge + the reveal `SceneOverride` (public-only winner data; the `BadgeCalculator` design decision).

Whole-branch review on **opus** with base = current `main` tip, a consolidated fix wave, merge ff to `main`, tag **`m12`**, close GitHub milestone **#13**, update roadmap Erkenntnisse + memory.
