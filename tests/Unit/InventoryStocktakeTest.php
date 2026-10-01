<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A stocktake states what is on the shelf; it does not price it.
 *
 * The existing toner methods all move quantity between two known states —
 * install takes a full one and makes it empty, refill does the reverse. A
 * stocktake is the one entry point where the operator's count overrides both
 * numbers at once, which is why it writes the difference rather than applying
 * one.
 */
class InventoryStocktakeTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new InventoryService;
        $this->user = User::factory()->create(['role' => 'admin']);
    }

    private function toner(float $full, float $empty, float $avgCost = 900.00): InventoryItem
    {
        return InventoryItem::factory()->create([
            'name'             => 'Тонер тестовий',
            'current_quantity' => $full,
            'empty_quantity'   => $empty,
            'avg_cost'         => $avgCost,
        ]);
    }

    public function test_a_count_above_the_books_is_written_as_a_positive_delta(): void
    {
        $item = $this->toner(full: 2, empty: 0);

        $movement = $this->service->stocktakeToner($item, 4, 1, $this->user);

        $this->assertNotNull($movement);
        $this->assertSame('stocktake', $movement->type);
        $this->assertEqualsWithDelta(2, $movement->quantity, 0.0001);
        $this->assertEqualsWithDelta(1, $movement->empty_quantity, 0.0001);

        $item->refresh();
        $this->assertEqualsWithDelta(4, $item->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(1, $item->empty_quantity, 0.0001);
    }

    public function test_a_count_below_the_books_is_written_as_a_negative_delta(): void
    {
        $item = $this->toner(full: 5, empty: 3);

        $movement = $this->service->stocktakeToner($item, 1, 1, $this->user);

        $this->assertEqualsWithDelta(-4, $movement->quantity, 0.0001);
        $this->assertEqualsWithDelta(-2, $movement->empty_quantity, 0.0001);

        $item->refresh();
        $this->assertEqualsWithDelta(1, $item->current_quantity, 0.0001);
        $this->assertEqualsWithDelta(1, $item->empty_quantity, 0.0001);
    }

    /**
     * The movement carries a cost so the journal stays readable, but the
     * average is what the item was bought at — a recount is not a purchase.
     */
    public function test_the_average_cost_is_left_alone(): void
    {
        $item = $this->toner(full: 2, empty: 0, avgCost: 900.00);

        $movement = $this->service->stocktakeToner($item, 5, 0, $this->user);

        $this->assertEqualsWithDelta(900.00, $item->fresh()->avg_cost, 0.0001);
        $this->assertEqualsWithDelta(900.00, $movement->unit_cost, 0.0001);
        $this->assertEqualsWithDelta(
            2700.00,
            $movement->total_cost,
            0.0001,
            'total_cost carries the sign instead of the magnitude.',
        );
    }

    /**
     * A count that agrees with the books is the common case on a shelf nobody
     * touched. Filing «nothing changed» in an append-only journal buries the
     * entries that mean something.
     */
    public function test_a_count_that_matches_the_books_files_nothing(): void
    {
        $item = $this->toner(full: 3, empty: 2);

        $this->assertNull($this->service->stocktakeToner($item, 3, 2, $this->user));
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_a_negative_count_is_refused(): void
    {
        $item = $this->toner(full: 3, empty: 2);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->service->stocktakeToner($item, -1, 2, $this->user);
        } finally {
            $item->refresh();
            $this->assertEqualsWithDelta(3, $item->current_quantity, 0.0001);
            $this->assertSame(0, InventoryMovement::count());
        }
    }
}
