<?php

namespace Vju\PaymentClient\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Vju\PaymentClient\Tests\TestCase;

class ScheduleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('payment-gateway.pull.schedule', true);
    }

    public function test_pull_is_scheduled_every_five_minutes_when_enabled(): void
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($e) => str_contains((string) $e->command, 'payment:pull'),
        ));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events[0]->expression);
    }
}
