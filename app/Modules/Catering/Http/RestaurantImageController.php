<?php

declare(strict_types=1);

namespace App\Modules\Catering\Http;

use App\Http\Controllers\Controller;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Catering\Support\DishImageStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a restaurant dish photo or logo from the private disk. No login (like the public
 * gallery photos); the gate is the data: the restaurant must be used by at least one published
 * round of a publicly visible event. Anything else is a plain 404. File names are content
 * hashes and the URL carries them as `v`, so a day of public caching is safe.
 */
class RestaurantImageController extends Controller
{
    public function dish(Restaurant $restaurant, string $key): StreamedResponse
    {
        abort_unless($restaurant->isShownToParticipants(), 404);

        $option = collect($restaurant->menu)->first(static fn (MenuOption $o): bool => $o->key === $key);

        return $this->stream($option?->image);
    }

    public function logo(Restaurant $restaurant): StreamedResponse
    {
        abort_unless($restaurant->isShownToParticipants(), 404);

        return $this->stream($restaurant->logo);
    }

    /**
     * Orga preview of a dish photo in the menu editor: behind role:orga and the restaurant's `view`
     * policy, independent of whether a published round uses the restaurant yet. Private cache only.
     */
    public function previewDish(Restaurant $restaurant, string $key): StreamedResponse
    {
        Gate::authorize('view', $restaurant);

        $option = collect($restaurant->menu)->first(static fn (MenuOption $o): bool => $o->key === $key);

        return $this->stream($option?->image, 'private, max-age=3600');
    }

    public function previewLogo(Restaurant $restaurant): StreamedResponse
    {
        Gate::authorize('view', $restaurant);

        return $this->stream($restaurant->logo, 'private, max-age=3600');
    }

    private function stream(?string $path, string $cacheControl = 'public, max-age=86400'): StreamedResponse
    {
        abort_if($path === null, 404);

        $disk = Storage::disk(DishImageStore::DISK);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => $cacheControl,
        ]);
    }
}
