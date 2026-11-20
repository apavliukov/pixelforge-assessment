<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks the users who may open the operator report. Only the seeder sets it.
     */
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->boolean('is_operator')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn('is_operator');
        });
    }
};
