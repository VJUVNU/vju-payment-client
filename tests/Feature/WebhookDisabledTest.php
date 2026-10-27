<?php

namespace Vju\PaymentClient\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Vju\PaymentClient\Tests\TestCase;

class WebhookDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('payment-gateway.webhook.enabled', false);
    }

    public function test_route_is_not_registered(): void
    {
        $this->assertFalse(Route::has('payment-gateway.webhook'));
        $this->deliverWebhook($this->paidEnvelope($this->fixture('payment.json')))->assertNotFound();
    }
}
