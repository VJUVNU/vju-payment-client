<?php

namespace Vju\PaymentClient\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Vju\PaymentClient\Events\PaymentReceived;
use Vju\PaymentClient\Support\CursorStore;
use Vju\PaymentClient\Tests\TestCase;

class PullPaymentsTest extends TestCase
{
    /** @var list<int> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(PaymentReceived::class, function (PaymentReceived $e) {
            $this->fired[] = $e->transactionId;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(int $id, string $ref = 'HP1'): array
    {
        return array_merge($this->fixture('payment.json'), ['id' => $id, 'ref' => $ref, 'pay_code' => $ref]);
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function page(array $ids, bool $hasMore, ?int $next = null): array
    {
        return [
            'data' => array_map(fn (int $id) => $this->payment($id), $ids),
            'next_cursor' => $next ?? ($ids === [] ? 0 : end($ids)),
            'has_more' => $hasMore,
        ];
    }

    private function cursor(): int
    {
        return $this->app->make(CursorStore::class)->get();
    }

    public function test_pull_fires_and_advances_cursor(): void
    {
        Http::fake([self::URL.'/api/v1/payments*' => Http::response($this->page([11, 12], false))]);

        $this->artisan('payment:pull')->assertSuccessful();

        $this->assertSame([11, 12], $this->fired);
        $this->assertSame(12, $this->cursor());
        Http::assertSent(function (Request $request) {
            $this->assertSignedRequest($request, 'GET', '/api/v1/payments?cursor=0&limit=100');

            return true;
        });
    }

    public function test_pull_starts_from_stored_cursor(): void
    {
        $this->app->make(CursorStore::class)->put(40);
        Http::fake([self::URL.'/*' => Http::response($this->page([], false, 40))]);

        $this->artisan('payment:pull', ['--limit' => 20])->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/payments?cursor=40&limit=20'));
        $this->assertSame(40, $this->cursor());
        $this->assertSame([], $this->fired);
    }

    public function test_has_more_pages_are_followed(): void
    {
        Http::fake([self::URL.'/*' => Http::sequence()
            ->push($this->page([1, 2], true))
            ->push($this->page([3, 4], true))
            ->push($this->page([5], false)),
        ]);

        $this->artisan('payment:pull', ['--limit' => 2])->assertSuccessful();

        $this->assertSame([1, 2, 3, 4, 5], $this->fired);
        $this->assertSame(5, $this->cursor());

        $urls = [];
        Http::assertSent(function (Request $r) use (&$urls) {
            $urls[] = substr($r->url(), strlen(self::URL));

            return true;
        });
        $this->assertSame([
            '/api/v1/payments?cursor=0&limit=2',
            '/api/v1/payments?cursor=2&limit=2',
            '/api/v1/payments?cursor=4&limit=2',
        ], $urls);
    }

    public function test_max_pages_caps_one_run(): void
    {
        Http::fake([self::URL.'/*' => Http::sequence()
            ->push($this->page([1], true))
            ->push($this->page([2], true))
            ->push($this->page([3], false)),
        ]);

        $this->artisan('payment:pull', ['--limit' => 1, '--max-pages' => 2])->assertSuccessful();

        $this->assertSame([1, 2], $this->fired);
        $this->assertSame(2, $this->cursor());
        Http::assertSentCount(2);
    }

    public function test_empty_page_with_has_more_does_not_loop(): void
    {
        Http::fake([self::URL.'/*' => Http::response($this->page([], true, 7))]);

        $this->artisan('payment:pull')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(7, $this->cursor());
    }

    public function test_pull_does_not_refire_what_webhook_delivered(): void
    {
        $this->deliverWebhook($this->paidEnvelope($this->payment(21)))->assertOk();
        $this->assertSame([21], $this->fired);

        Http::fake([self::URL.'/*' => Http::response($this->page([20, 21, 22], false))]);

        $this->artisan('payment:pull')->assertSuccessful();

        $this->assertSame([21, 20, 22], $this->fired);
        $this->assertSame(22, $this->cursor());
        $this->assertSame(3, DB::table('payment_client_events')->count());
        $this->assertSame('webhook', DB::table('payment_client_events')->where('gateway_payment_id', 21)->value('source'));
    }

    public function test_webhook_after_pull_is_duplicate(): void
    {
        Http::fake([self::URL.'/*' => Http::response($this->page([30], false))]);
        $this->artisan('payment:pull')->assertSuccessful();

        $this->deliverWebhook($this->paidEnvelope($this->payment(30)))->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame([30], $this->fired);
    }

    public function test_second_pull_of_same_page_fires_nothing(): void
    {
        Http::fake([self::URL.'/*' => Http::response($this->page([1, 2], false))]);

        $this->artisan('payment:pull')->assertSuccessful();
        $this->app->make(CursorStore::class)->put(0);
        $this->artisan('payment:pull')->assertSuccessful();

        $this->assertSame([1, 2], $this->fired);
    }

    public function test_listener_failure_stops_cursor_before_failing_payment(): void
    {
        $failOn = 3;
        Event::listen(PaymentReceived::class, function (PaymentReceived $e) use (&$failOn) {
            if ($e->transactionId === $failOn) {
                throw new RuntimeException('listener hỏng');
            }
        });

        Http::fake([self::URL.'/*' => Http::response($this->page([1, 2, 3, 4], false))]);

        $this->artisan('payment:pull')->assertFailed();

        $this->assertSame(2, $this->cursor());
        $this->assertSame([1, 2], DB::table('payment_client_events')->orderBy('id')->pluck('gateway_payment_id')->map(fn ($v) => (int) $v)->all());

        $failOn = null;
        Http::fake([self::URL.'/*' => Http::response($this->page([3, 4], false))]);

        $this->artisan('payment:pull')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'cursor=2&limit=100'));
        $this->assertSame(4, $this->cursor());
        $this->assertSame(4, DB::table('payment_client_events')->count());
    }

    public function test_gateway_error_fails_command_and_keeps_cursor(): void
    {
        $this->app->make(CursorStore::class)->put(9);
        Http::fake([self::URL.'/*' => Http::response(['error' => ['code' => 'signature_invalid', 'message' => 'x']], 401)]);

        $this->artisan('payment:pull')->assertFailed();

        $this->assertSame(9, $this->cursor());
    }

    public function test_cursor_option_resets_cursor(): void
    {
        $this->app->make(CursorStore::class)->put(500);
        Http::fake([self::URL.'/*' => Http::response($this->page([], false, 0))]);

        $this->artisan('payment:pull', ['--cursor' => 0])->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'cursor=0&'));
    }

    public function test_cursor_never_moves_backwards_on_advance(): void
    {
        $store = $this->app->make(CursorStore::class);
        $store->advance(10);
        $store->advance(5);

        $this->assertSame(10, $store->get());
    }

    public function test_schedule_is_opt_in(): void
    {
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

        $this->assertEmpty(array_filter(
            $schedule->events(),
            fn ($e) => str_contains((string) $e->command, 'payment:pull'),
        ));
    }
}
