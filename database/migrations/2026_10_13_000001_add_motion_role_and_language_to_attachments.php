<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the `motion` attachment role (MP4 slides previewed as a motion
     * carousel, never published) and a `language` column so a slide set can
     * belong to one caption language (bn/en) on a bilingual post.
     */
    public function up(): void
    {
        $this->widenRoleColumn();

        Schema::table('attachments', function (Blueprint $table): void {
            $table->string('language', 8)->nullable()->after('platform');
        });
    }

    public function down(): void
    {
        DB::table('attachments')->where('role', 'motion')->delete();

        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropColumn('language');
        });
    }

    private function widenRoleColumn(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            // Laravel's enum() on Postgres is a CHECK constraint. Drop it and
            // widen to varchar, as the posts/videos status widening did.
            DB::statement('alter table attachments drop constraint if exists attachments_role_check');
            DB::statement('alter table attachments alter column role type varchar(32) using role::text');

            return;
        }

        Schema::table('attachments', function (Blueprint $table): void {
            $table->string('role', 32)->change();
        });
    }
};
