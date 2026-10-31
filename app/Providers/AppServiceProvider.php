<?php

namespace App\Providers;

use App\Domain\Access\Totp;
use App\Domain\Files\Contracts\VirusScanner;
use App\Domain\Files\Support\NullVirusScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bound as a singleton so the digits, period and drift window come
        // from the configuration once per request rather than being rebuilt
        // with the hard-coded defaults every time a one-time code is checked.
        $this->app->singleton(Totp::class, fn (): Totp => Totp::fromConfig());

        // The antivirus hook. This build installs no scanner, so the binding
        // is the placeholder that honestly inspects nothing: see
        // NullVirusScanner. A deployment that installs a real scanner rebinds
        // this one line, and the validator — which already calls the interface
        // on every upload — starts using it without any other change.
        $this->app->bind(VirusScanner::class, NullVirusScanner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configurePasswordPolicy();
    }

    /**
     * How hard the two unauthenticated-adjacent entry points on the surface may
     * be hammered.
     *
     * The login limiter counts by the address that was typed together with the
     * address it came from. Counting by email alone would let anybody lock a
     * known account out from anywhere; counting by IP alone would let one
     * noisy office behind a single address exhaust the allowance for
     * everybody. The pair is what makes a guess against one account cost
     * something while leaving a shared connection usable.
     *
     * The two-factor limiter counts per authenticated member where there is
     * one, because a six-digit code is worth guessing and the member is the
     * thing being protected.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $attempts = max(1, (int) config('nexas.auth.login_max_attempts', 5));
            $minutes = max(1, (int) ceil((int) config('nexas.auth.login_decay_seconds', 60) / 60));

            return Limit::perMinutes($minutes, $attempts)
                ->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('two-factor', function (Request $request): Limit {
            $attempts = max(1, (int) config('nexas.auth.two_factor.max_attempts', 10));
            $minutes = max(1, (int) ceil((int) config('nexas.auth.login_decay_seconds', 60) / 60));

            return Limit::perMinutes($minutes, $attempts)
                ->by('two-factor|'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }

    /**
     * What a password has to look like.
     *
     * Length first, then variety: a long single-word password is weaker than
     * this reads, but the rules here are the floor a deployment gets without
     * thinking about it, and `password_min_length` is configuration so a
     * stricter shop can raise it. The minimum is deliberately well above the
     * eight characters the framework's own minimum would allow.
     *
     * The breached-password check asks an external service over the network,
     * which is worth requiring of a real deployment and unreasonable to make
     * the test suite depend on, so it is switched on only where the
     * application is actually running for real.
     */
    private function configurePasswordPolicy(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(max(8, (int) config('nexas.auth.password_min_length', 12)))
                ->letters()
                ->numbers()
                ->symbols();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }
}
