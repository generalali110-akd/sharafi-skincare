<?php

namespace App\Providers;

use App\Contracts\SmsGateway;
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
    }

    public function boot(): void
    {
        // Domain-specific bootstrapping belongs here, not in controllers.
    }
}
