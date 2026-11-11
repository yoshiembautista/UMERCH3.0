<?php

namespace App\Providers;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;

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
        /*
         * Laravel has no built-in Brevo driver. The Symfony bridge is
         * installed (symfony/brevo-mailer) and exposes the "brevo+api"
         * scheme, which sends over HTTPS instead of SMTP. This is required
         * on hosts such as Render's free tier, where outbound SMTP is blocked.
         */
        Mail::extend('brevo', function (array $config): TransportInterface {
            $key = (string) ($config['key'] ?? '');

            if ($key === '') {
                throw new \RuntimeException(
                    'BREVO_API_KEY is not configured, so OTP email cannot be sent.'
                );
            }

            return Transport::fromDsn(
                'brevo+api://'.rawurlencode($key).'@default'
            );
        });
    }
}
