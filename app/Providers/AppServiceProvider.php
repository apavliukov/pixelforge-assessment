<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerDevCommands();
    }

    /**
     * Processes started by `sail artisan dev`. Sail already serves the app, so the
     * built-in server is dropped; the tunnel is whatever the developer puts in
     * DEV_TUNNEL_COMMAND to receive Pixelforge webhooks locally.
     */
    private function registerDevCommands(): void
    {
        if (! $this->app->environment('local')) {
            return;
        }

        DevCommands::except('server');
        DevCommands::artisan('schedule:work', 'scheduler');

        if ($tunnelCommand = config('app.dev_tunnel_command')) {
            DevCommands::register($tunnelCommand, 'tunnel');
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    private function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
