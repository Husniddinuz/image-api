<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per user-owned image. Public ids are ULIDs so they are unguessable
 * and non-enumerable, and monotonic enough to stay index friendly at volume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('image_blob_id')->constrained('image_blobs')->restrictOnDelete();

            $table->string('name');

            $table->timestamps();

            // Drives GET /api/images: newest first, scoped to the owner.
            $table->index(['user_id', 'created_at', 'id']);

            // The same user uploading the same bytes twice reuses one record.
            $table->unique(['user_id', 'image_blob_id']);

            $table->index('image_blob_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
