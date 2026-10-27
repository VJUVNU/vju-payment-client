<?php

namespace Vju\PaymentClient;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Vju\PaymentClient\Client\GatewayClient;
use Vju\PaymentClient\Console\PullPayments;
use Vju\PaymentClient\Contracts\Gateway;
use Vju\PaymentClient\Support\CursorStore;
use Vju\PaymentClient\Support\Inbox;
use Vju\PaymentClient\Support\NonceStore;

class PaymentGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/payment-gateway.php', 'payment-gateway');

        $this->app->singleton(GatewayClient::class, fn (Application $app) => new GatewayClient(
            (array) $app['config']->get('payment-gateway', []),
        ));
        $this->app->singleton(Gateway::class, fn (Application $app) => $app->make(GatewayClient::class));

        $this->app->singleton(Inbox::class, fn (Application $app) => new Inbox(
            $app['db'],
            $app['config']->get('payment-gateway.connection'),
        ));
        $this->app->singleton(CursorStore::class, fn (Application $app) => new CursorStore(
            $app['db'],
            $app['config']->get('payment-gateway.connection'),
        ));
        $this->app->singleton(NonceStore::class, fn (Application $app) => new NonceStore(
            $app['db'],
            $app['config']->get('payment-gateway.connection'),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/payment-gateway.php' => config_path('payment-gateway.php'),
        ], 'payment-gateway-config');

        // Giữ nguyên tên tệp khi publish để migrator không chạy hai lần cùng một migration.
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'payment-gateway-migrations');

        if (config('payment-gateway.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if (config('payment-gateway.webhook.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PullPayments::class]);
        }

        if (config('payment-gateway.pull.schedule', false)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('payment:pull')
                    ->cron((string) config('payment-gateway.pull.cron', '*/5 * * * *'))
                    ->withoutOverlapping()
                    ->runInBackground();
            });
        }
    }
}
