<?php

declare(strict_types=1);

namespace App\Modules\Catering\Http;

use App\Http\Controllers\Controller;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Support\DishImageStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Legacy per-round dish photo URL, kept as an alias of {@see RestaurantImageController} (bookmark and cache
 * compatibility): it resolves the round's restaurant and serves the same file.
 *
 * Streams a dish photo from the private disk. Like the public gallery photos the gate is the
 * data itself, not a login: the round must be published (never a draft), its event publicly
 * visible, and the dish must carry an image. Anything else is a plain 404. The file name is a
 * content hash and the URL carries it as `v`, so a day of public caching is safe.
 */
class DishImageController extends Controller
{
    public function __invoke(FoodOrder $foodOrder, string $key): StreamedResponse
    {
        $event = $foodOrder->event()->first();

        abort_if($event === null || ! $event->isPubliclyVisible(), 404);
        abort_if($foodOrder->status === FoodOrderStatus::Draft, 404);

        $option = collect($foodOrder->menuOptions())->first(static fn (MenuOption $o): bool => $o->key === $key);
        $path = $option?->image;

        abort_if($path === null, 404);

        $disk = Storage::disk(DishImageStore::DISK);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
