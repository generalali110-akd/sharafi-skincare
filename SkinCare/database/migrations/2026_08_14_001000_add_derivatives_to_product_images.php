<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->string('avif_path', 500)->nullable()->after('path');
            $table->string('source_mime', 64)->nullable()->after('avif_path');
            $table->unsignedSmallInteger('width')->nullable()->after('source_mime');
            $table->unsignedSmallInteger('height')->nullable()->after('width');
            $table->unsignedBigInteger('bytes')->nullable()->after('height');

            $table->unique(['disk', 'avif_path']);
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropUnique(['disk', 'avif_path']);
            $table->dropColumn(['avif_path', 'source_mime', 'width', 'height', 'bytes']);
        });
    }
};
