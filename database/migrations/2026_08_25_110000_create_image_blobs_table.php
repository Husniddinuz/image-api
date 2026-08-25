<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per unique set of bytes ever uploaded, regardless of how many users
 * uploaded it. This is what makes "the same image uploaded many times" cost a
 * single file on disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_blobs', function (Blueprint $table) {
            $table->id();

            // sha-256 of the ORIGINAL uploaded bytes: the deduplication key.
            $table->char('content_hash', 64)->unique();

            $table->string('disk', 32);
            $table->string('path');

            $table->string('format', 16);
            $table->string('mime_type', 64);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('bytes');

            $table->string('original_format', 16);
            $table->string('original_mime_type', 64);
            $table->unsignedBigInteger('original_bytes');

            $table->string('status', 16)->default('pending');
            $table->text('failure_reason')->nullable();

            // Number of user-owned images pointing here. Hits zero -> prunable.
            $table->unsignedBigInteger('reference_count')->default(0);

            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index('reference_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_blobs');
    }
};
