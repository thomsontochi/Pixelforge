<?php

namespace App\Providers;

use App\Services\Billing\PriceCalculator;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\WebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            config('services.pixelforge.base_url'),
            config('services.pixelforge.api_key'),
        ));

        $this->app->singleton(PriceCalculator::class, fn () => new PriceCalculator(
            (int) config('services.pixelforge.markup_percent'),
        ));

        $this->app->singleton(WebhookSignature::class, fn () => new WebhookSignature(
            (string) config('services.pixelforge.webhook_secret'),
            (int) config('services.pixelforge.webhook_tolerance_seconds'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Operators who can see the admin report.
        Gate::define('admin', fn (User $user) => $user->is_admin);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
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
