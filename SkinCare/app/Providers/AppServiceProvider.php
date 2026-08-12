<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Contracts\SmsGateway;
use App\Infrastructure\Payments\NullPaymentGateway;
use App\Infrastructure\Sms\NullSmsGateway;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsGateway::class, function () {
            return match (config('sms.driver')) {
                'null' => new NullSmsGateway,
                default => throw new LogicException('Unsupported SMS driver configured.'),
            };
        });

        $this->app->singleton(PaymentGateway::class, function () {
            return match (config('payment.driver')) {
                'null' => new NullPaymentGateway,
                default => throw new LogicException('Unsupported payment driver configured.'),
            };
        });
    }

    public function boot(): void
    {
        // Domain-specific bootstrapping belongs here, not in controllers.
    }
}
