<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('socialreins_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 10);
            $table->unsignedBigInteger('subject_id');
            $table->string('language', 20);
            // 1, 2, ... per subject and language. A new sequence is a new
            // Idempotency-Key; the same sequence always replays the first answer.
            $table->unsignedSmallInteger('sequence');
            $table->string('idempotency_key')->unique();
            // pending: request not answered yet (frozen, resend verbatim);
            // created: brain answered 201 and gave a group_id;
            // rejected: brain refused it with a 4xx (nothing was created).
            $table->string('state', 20)->default('pending');
            // The exact JSON body, kept as text so a retry sends the same bytes.
            $table->text('request');
            $table->uuid('group_id')->nullable()->unique();
            // SocialReins post id => {platform, status, targets: {target id => {...}}}
            $table->json('results')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'language', 'sequence'], 'socialreins_groups_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('socialreins_groups');
    }
};
