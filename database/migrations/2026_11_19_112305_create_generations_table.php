<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable()->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->jsonb('reference_images');

            $table->string('status')->default(GenerationStatus::Waiting->value)->index();
            $table->unsignedInteger('price_multiplier_basis_points');
            $table->unsignedBigInteger('estimate_min');
            $table->unsignedBigInteger('estimate_max');

            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->jsonb('rates')->nullable();
            $table->unsignedBigInteger('provider_cost')->nullable();
            $table->unsignedBigInteger('user_charge')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->timestamp('provider_synced_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
