<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
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
        Gate::define('manage-cv-site', fn (User $user): bool => $user->isAdmin());
        Gate::define('generate-candidature', fn (User $user): bool => $user->canGenerate());
        RateLimiter::for('candidature', fn (Request $request): array => [
            Limit::perMinute(3)->by((string) $request->user()?->id),
            Limit::perDay(20)->by((string) $request->user()?->id),
        ]);
        RateLimiter::for('candidature-documents', function (Request $request): Limit {
            $embeddedPreview = $request->route('format') === 'pdf'
                && $request->boolean('preview') && $request->boolean('embedded');
            $operation = $embeddedPreview ? 'preview' : 'export';

            return Limit::perMinute(20)->by($operation.':'.$request->user()?->id);
        });
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
