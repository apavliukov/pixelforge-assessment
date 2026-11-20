<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        $this->defineGates();
        $this->registerDevCommands();
    }

    /**
     * Processes started by `sail artisan dev`. Sail already serves the app, so the
     * built-in server is dropped; one listener takes all three queues that production
     * splits between two workers; the tunnel is whatever the developer puts in
     * DEV_TUNNEL_COMMAND to receive Pixelforge webhooks locally.
     */
    private function registerDevCommands(): void
    {
        if (! $this->app->environment('local')) {
            return;
        }

        DevCommands::except('server');
        DevCommands::artisan('queue:listen --queue=webhooks,submissions,default --tries=1 --timeout=0', 'queue');
        DevCommands::artisan('schedule:work', 'scheduler');

        if ($tunnelCommand = config('app.dev_tunnel_command')) {
            DevCommands::register($tunnelCommand, 'tunnel');
        }
    }

    /**
     * Operators are the users marked as such in the database. This gate is the only place that
     * knows it.
     */
    private function defineGates(): void
    {
        Gate::define('viewOperatorReport', static fn (User $user): bool => $user->is_operator);
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
