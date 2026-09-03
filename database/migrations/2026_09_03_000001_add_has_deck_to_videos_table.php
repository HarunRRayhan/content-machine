<?php

use App\Models\Video;
use App\Support\Content\PresentationManifest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->boolean('has_deck')->default(false)->after('deck_manifest');
        });

        Video::query()
            ->select(['id', 'deck_manifest'])
            ->chunkById(100, function ($videos): void {
                foreach ($videos as $video) {
                    $video->forceFill([
                        'has_deck' => PresentationManifest::isUsable($video->deck_manifest),
                    ])->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn('has_deck');
        });
    }
};
