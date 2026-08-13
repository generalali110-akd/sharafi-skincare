<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Contracts\SmsGateway;
use App\Infrastructure\Payments\NullPaymentGateway;
use App\Infrastructure\Payments\ZarinpalPaymentGateway;
use App\Infrastructure\Sms\NullSmsGateway;
use App\Infrastructure\Sms\SmsIrGateway;
use App\Infrastructure\Testing\E2ePaymentGateway;
use App\Infrastructure\Testing\E2eSmsGateway;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsGateway::class, function () {
            return match (config('sms.driver')) {
                'null' => new NullSmsGateway,
                'smsir' => new SmsIrGateway((array) config('sms.smsir', [])),
                'e2e' => $this->app->environment('testing')
                    ? new E2eSmsGateway
                    : throw new LogicException('The E2E SMS driver is restricted to the testing environment.'),
                default => throw new LogicException('Unsupported SMS driver configured.'),
            };
        });

        $this->app->singleton(PaymentGateway::class, function () {
            return match (config('payment.driver')) {
                'null' => new NullPaymentGateway,
                'zarinpal' => new ZarinpalPaymentGateway((array) config('payment.zarinpal', [])),
                'e2e' => $this->app->environment('testing')
                    ? new E2ePaymentGateway
                    : throw new LogicException('The E2E payment driver is restricted to the testing environment.'),
                default => throw new LogicException('Unsupported payment driver configured.'),
            };
        });
    }

    public function boot(): void
    {
        // Domain-specific bootstrapping belongs here, not in controllers.
    }
}
