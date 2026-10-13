<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('erp-login', static function (Request $request): array {
            $ip = (string) $request->ip();
            $email = mb_strtolower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinute(6)->by('erp-login-account:'.hash('sha256', $email.'|'.$ip)),
                Limit::perMinute(40)->by('erp-login-ip:'.hash('sha256', $ip)),
            ];
        });

        RateLimiter::for('platform-mfa-attempt', static function (Request $request): array {
            $identity = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
            $ip = (string) $request->ip();
            return [
                Limit::perMinute(6)->by('platform-mfa-user:'.$identity),
                Limit::perMinute(30)->by('platform-mfa-ip:'.hash('sha256', $ip)),
            ];
        });
    }
}
