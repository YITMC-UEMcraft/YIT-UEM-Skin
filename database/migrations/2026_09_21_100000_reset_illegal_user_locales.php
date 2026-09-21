<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * bs-community/blessing-skin-server#684: an illegal value could be persisted
 * into users.locale, which broke every page render for that account (500).
 * Reset polluted rows so the fallback locale applies again.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('users')
            ->whereNotIn('locale', array_keys(config('locales')))
            ->update(['locale' => null]);
    }

    public function down(): void
    {
    }
};
