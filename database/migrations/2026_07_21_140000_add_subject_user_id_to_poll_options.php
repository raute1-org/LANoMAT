<?php

use App\Modules\Voting\Actions\SeedMvpPoll;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Nullable — only populated for MVP-poll options (via
     * {@see SeedMvpPoll}'s `forceFill`); standard
     * poll options leave this null. This is the clean linkage the
     * `mvp_of_the_night` badge needs to map a poll's winning option back to
     * the user it represents, without resolving by name-matching.
     */
    public function up(): void
    {
        Schema::table('poll_options', function (Blueprint $table) {
            $table->foreignId('subject_user_id')->nullable()->after('poll_id')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('poll_options', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subject_user_id');
        });
    }
};
