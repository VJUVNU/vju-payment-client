<?php

namespace Vju\PaymentClient\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Vju\PaymentClient\Events\PaymentReceived;
use Vju\PaymentClient\Tests\TestCase;

class WebhookRouteConfigTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('payment-gateway.webhook.path', 'hooks/thanh-toan');
    }

    public function test_custom_path_is_used_and_signed(): void
    {
        Event::fake([PaymentReceived::class]);

        $this->assertTrue(Route::has('payment-gateway.webhook'));
        $this->post('/payment-gateway/webhook')->assertNotFound();

        $this->deliverWebhook($this->paidEnvelope($this->fixture('payment.json')), ['path' => '/hooks/thanh-toan'])
            ->assertOk();

        Event::assertDispatchedTimes(PaymentReceived::class, 1);
    }

    public function test_signature_for_other_path_is_rejected(): void
    {
        $body = json_encode($this->paidEnvelope($this->fixture('payment.json')));
        $ts = time();
        $nonce = 'n-other-path';
        $sig = \Vju\SatelliteContracts\Signing\RequestSignature::sign(self::WEBHOOK_SECRET, 'POST', '/payment-gateway/webhook', $ts, $nonce, $body);

        $this->deliverWebhook($body, ['path' => '/hooks/thanh-toan', 'signature' => $sig, 'nonce' => $nonce, 'timestamp' => $ts])
            ->assertStatus(401);
    }
}
