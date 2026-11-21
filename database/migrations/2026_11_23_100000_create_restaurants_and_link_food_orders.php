<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Moves the menu from the food round to an event-spanning restaurant.
 *
 * `food_orders.menu` stays as the LEGACY column (never dropped here): it is the fallback for
 * rounds without a restaurant and what `down()` restores, so a rollback loses nothing. Dish
 * keys, prices and the ordered items are never touched. The backfill uses the query builder and
 * copies the raw jsonb text, so the menu is carried over 1:1 and the backfill is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurants', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->string('description', 600)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('order_url', 255)->nullable();
            $table->string('logo', 255)->nullable();
            $table->string('default_payment_method', 16)->nullable();
            $table->string('default_payment_link', 255)->nullable();
            $table->jsonb('menu')->default('[]');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('food_orders', function (Blueprint $table): void {
            // restrictOnDelete: a restaurant that rounds still point at can never be deleted.
            $table->foreignId('restaurant_id')->nullable()->constrained('restaurants')->restrictOnDelete();
            $table->index('restaurant_id');
        });

        $this->backfill();
    }

    /**
     * One restaurant per distinct (title, menu) of the rounds that have none yet; identical
     * rounds share one. Safe to call repeatedly: only rounds without a restaurant are touched.
     */
    public function backfill(): void
    {
        $rounds = DB::table('food_orders')->whereNull('restaurant_id')->orderBy('id')->get(['id', 'title', 'menu', 'payment_method', 'payment_link']);

        /** @var array<string, int> $made */
        $made = [];

        foreach ($rounds as $round) {
            $menu = is_string($round->menu) ? $round->menu : json_encode($round->menu, JSON_THROW_ON_ERROR);
            $hash = (string) DB::selectOne('select md5(?::jsonb::text) as h', [$menu])->h;
            $group = mb_strtolower((string) $round->title)."\0".$hash;

            if (! isset($made[$group])) {
                $existing = DB::table('restaurants')
                    ->where('name', $round->title)
                    ->whereRaw('menu::text = ?::jsonb::text', [$menu])
                    ->value('id');

                $made[$group] = $existing !== null ? (int) $existing : (int) DB::table('restaurants')->insertGetId([
                    'name' => $round->title,
                    'slug' => $this->uniqueSlug((string) $round->title),
                    'menu' => $menu,
                    'default_payment_method' => $round->payment_method,
                    'default_payment_link' => $round->payment_method === 'paypal' ? $round->payment_link : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('food_orders')->where('id', $round->id)->update(['restaurant_id' => $made[$group]]);
        }
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'restaurant';
        $base = Str::limit($base, 240, '');
        $slug = $base;
        $n = 2;

        while (DB::table('restaurants')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    public function down(): void
    {
        // Restore the legacy column from the restaurant (it may have been edited since), so a
        // rollback serves the current menu instead of a stale copy.
        DB::statement('update food_orders set menu = restaurants.menu from restaurants where restaurants.id = food_orders.restaurant_id');

        Schema::table('food_orders', function (Blueprint $table): void {
            $table->dropIndex(['restaurant_id']);
            $table->dropConstrainedForeignId('restaurant_id');
        });

        Schema::dropIfExists('restaurants');
    }
};
