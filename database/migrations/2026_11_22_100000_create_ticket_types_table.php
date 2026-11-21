<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['event_id', 'key']);
        });

        Schema::table('event_registrations', function (Blueprint $table): void {
            $table->unsignedInteger('ticket_price_cents')->nullable();
            $table->string('paid_by', 120)->nullable();
        });

        self::backfill();
    }

    /**
     * Maps the legacy `events.settings['tickets']` key list (and any key a
     * registration already uses) to `ticket_types` rows with price 0, and
     * snapshots price 0 onto existing registrations. Public + idempotent so
     * tests can call it directly.
     */
    public static function backfill(): void
    {
        DB::table('events')->orderBy('id')->each(function (object $event): void {
            $settings = is_string($event->settings) ? json_decode($event->settings, true) : [];
            $keys = [];

            foreach (is_array($settings) ? ($settings['tickets'] ?? []) : [] as $key) {
                if (is_string($key) && $key !== '') {
                    $keys[] = $key;
                }
            }

            $used = DB::table('event_registrations')->where('event_id', $event->id)->distinct()->pluck('ticket_type');

            foreach ($used as $key) {
                $keys[] = (string) $key;
            }

            $keys = array_values(array_unique($keys));

            if ($keys === []) {
                $keys = ['standard'];
            }

            $existing = DB::table('ticket_types')->where('event_id', $event->id)->pluck('key')->all();
            $sort = count($existing);

            foreach ($keys as $key) {
                if (in_array($key, $existing, true)) {
                    continue;
                }

                DB::table('ticket_types')->insert([
                    'event_id' => $event->id,
                    'key' => Str::limit($key, 64, ''),
                    'name' => Str::limit(Str::headline($key), 120, ''),
                    'price_cents' => 0,
                    'sort' => $sort++,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        DB::table('event_registrations')->whereNull('ticket_price_cents')->update(['ticket_price_cents' => 0]);
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table): void {
            $table->dropColumn(['ticket_price_cents', 'paid_by']);
        });

        Schema::dropIfExists('ticket_types');
    }
};
