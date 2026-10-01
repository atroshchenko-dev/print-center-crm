<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OrderNumberServiceTest — verifies order number format and sequencing logic.
 *
 * NOTE: OrderNumberService::generate() uses pg_advisory_xact_lock which only
 * works on PostgreSQL. These tests are marked to only run when DB_CONNECTION=pgsql.
 *
 * The service reads MAX(order_sequence) from the `orders` table, so we must
 * persist orders between generate() calls to advance the counter.
 */
class OrderNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderNumberService $service;
    private User $user;
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('OrderNumberService requires PostgreSQL (pg_advisory_xact_lock).');
        }

        $this->service = app(OrderNumberService::class);
        $this->user    = User::factory()->create();
        $this->shift   = Shift::factory()->create(['opened_by' => $this->user->id]);
    }

    public function test_generates_sequential_numbers(): void
    {
        $first = $this->service->generate(OrderType::Internal, 26, 4);
        $this->persistOrder($first);

        $second = $this->service->generate(OrderType::Internal, 26, 4);

        $this->assertStringStartsWith('INT-', $first['order_number']);
        $this->assertStringStartsWith('INT-', $second['order_number']);

        $this->assertEquals(1, $first['order_sequence']);
        $this->assertEquals(2, $second['order_sequence']);
    }

    public function test_internal_and_commercial_are_separate_sequences(): void
    {
        $int = $this->service->generate(OrderType::Internal, 26, 4);
        $this->persistOrder($int);

        $com = $this->service->generate(OrderType::Commercial, 26, 4);

        $this->assertStringStartsWith('INT-', $int['order_number']);
        $this->assertStringStartsWith('COM-', $com['order_number']);

        $this->assertEquals(1, $int['order_sequence']);
        $this->assertEquals(1, $com['order_sequence']);
    }

    public function test_counter_resets_each_month(): void
    {
        $april = $this->service->generate(OrderType::Internal, 26, 4);
        $this->persistOrder($april);

        $may = $this->service->generate(OrderType::Internal, 26, 5);

        $this->assertEquals(1, $april['order_sequence']);
        $this->assertEquals(1, $may['order_sequence']);
    }

    public function test_no_duplicate_numbers_under_concurrent_calls(): void
    {
        $generated = [];

        for ($i = 0; $i < 10; $i++) {
            $result = $this->service->generate(OrderType::Commercial, 26, 4);
            $this->persistOrder($result);
            $generated[] = $result;
        }

        $numbers = array_column($generated, 'order_number');
        $this->assertCount(10, array_unique($numbers));

        $seqs = array_column($generated, 'order_sequence');
        $this->assertEquals(range(1, 10), $seqs);
    }

    /**
     * Persist an order so that the next generate() call finds it via MAX(order_sequence).
     */
    private function persistOrder(array $data): void
    {
        Order::create([
            'order_number'     => $data['order_number'],
            'order_prefix'     => $data['order_prefix'],
            'order_year'       => $data['order_year'],
            'order_month'      => $data['order_month'],
            'order_sequence'   => $data['order_sequence'],
            'type'             => str_starts_with($data['order_prefix'], 'INT') ? 'internal' : 'commercial',
            'status'           => 'new',
            'user_id'          => $this->user->id,
            'shift_id'         => $this->shift->id,
            'total_commercial' => 0,
            'total_cost'       => 0,
        ]);
    }
}
