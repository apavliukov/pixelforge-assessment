<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Names the demo account a user is, if any. Only the seeder sets it, so holding a demo
     * account's email does not make a user one.
     */
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->string('demo_account')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn('demo_account');
        });
    }
};
