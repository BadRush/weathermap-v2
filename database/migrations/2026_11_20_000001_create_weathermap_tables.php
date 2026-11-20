<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('weathermap_maps')) {
            Schema::create('weathermap_maps', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 160);
                $table->string('slug', 180)->unique();
                $table->longText('document');
                $table->unsignedSmallInteger('schema_version')->default(1);
                $table->unsignedInteger('revision')->default(1);
                $table->boolean('png_enabled')->default(true);
                $table->timestamp('last_rendered_at')->nullable();
                $table->text('last_render_error')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index('png_enabled');
            });
        }

        if (! Schema::hasTable('weathermap_map_references')) {
            Schema::create('weathermap_map_references', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('map_id')->constrained('weathermap_maps')->cascadeOnDelete();
                $table->string('ref_type', 16); // port | device | rrd
                $table->unsignedBigInteger('port_id')->nullable()->index();
                $table->unsignedBigInteger('device_id')->nullable()->index();
                $table->string('element_id', 128);
                $table->string('rrd_path', 512)->nullable();
                $table->timestamps();

                $table->index(['map_id', 'ref_type']);
            });
        }

        if (! Schema::hasTable('weathermap_map_access')) {
            Schema::create('weathermap_map_access', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('map_id')->constrained('weathermap_maps')->cascadeOnDelete();
                $table->string('subject_type', 16); // role | user
                $table->string('subject_id', 128);
                $table->timestamps();

                $table->unique(['map_id', 'subject_type', 'subject_id'], 'wm_access_unique');
                $table->index(['subject_type', 'subject_id'], 'wm_access_subject_idx');
            });
        }

        if (! Schema::hasTable('weathermap_assets')) {
            Schema::create('weathermap_assets', function (Blueprint $table): void {
                $table->id();
                $table->string('kind', 24); // background | icon
                $table->string('original_name', 255);
                $table->string('path', 512);
                $table->string('mime', 64);
                $table->unsignedInteger('size');
                $table->string('sha256', 64)->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index('kind');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('weathermap_assets');
        Schema::dropIfExists('weathermap_map_access');
        Schema::dropIfExists('weathermap_map_references');
        Schema::dropIfExists('weathermap_maps');
    }
};
