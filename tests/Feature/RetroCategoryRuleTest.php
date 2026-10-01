<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SignatoryGroup;
use App\Models\UniversityRef;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The category rule in the retro module — owner's decision, 2026-08-01.
 *
 * `LimitService::recordForOrder()` asks three questions of a saved order: the
 * cost-centre quota, the signatory group's pool, and **which categories that
 * signatory may order from** (ТЗ §3). The retro module calls none of them.
 *
 * For the two quotas that is a deliberate decision, written down and explained:
 * retro records work from a period whose quota has already been spent and reset,
 * so counting it again is meaningless. Nobody had ever decided that about the
 * categories — it came along for the ride because one method holds all three.
 *
 * The owner's answer (2026-08-01): **retro asks the category question too.** It
 * warns and records; it refuses nothing, exactly as the live module does since
 * round 15. An admin correcting a badly imported month keeps working, and the
 * month carries a note that somebody ordered outside what they were allowed.
 */
class RetroCategoryRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Service $allowed;

    private Service $forbidden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $allowedCategory = ServiceCategory::factory()->create(['name' => 'Ч/Б друк']);
        $otherCategory = ServiceCategory::factory()->create(['name' => 'Візитівки']);

        $this->allowed = Service::factory()->create([
            'name'                  => 'Чорно-білий друк',
            'type'                  => 'static',
            'service_category_id'   => $allowedCategory->id,
            'base_price_cost'       => 1.00,
            'base_price_commercial' => 2.00,
            'counter_type'          => 'bw',
            'clicks_per_unit'       => 1,
            'is_active'             => true,
        ]);

        $this->forbidden = Service::factory()->create([
            'name'                  => 'Візитівки',
            'type'                  => 'static',
            'service_category_id'   => $otherCategory->id,
            'base_price_cost'       => 3.00,
            'base_price_commercial' => 6.00,
            'counter_type'          => 'color',
            'clicks_per_unit'       => 1,
            'is_active'             => true,
        ]);

        $group = SignatoryGroup::factory()->create(['name' => 'Деканат', 'daily_limit' => null]);
        $group->categories()->sync([$allowedCategory->id]);

        UniversityRef::factory()->create([
            'full_name'          => 'Іваненко Іван Іванович',
            'signatory_group_id' => $group->id,
            'is_active'          => true,
        ]);
    }

    private function store(Service $service)
    {
        return $this->actingAs($this->admin)->post(route('admin.backdated-orders.store'), [
            'order_date'        => '2025-08-15',
            'authorized_person' => 'Іваненко Іван Іванович',
            'cost_center'       => 'БШК',
            'items'             => [
                ['service_id' => $service->id, 'quantity' => 5],
            ],
        ]);
    }

    // ─── Store ───────────────────────────────────────────

    public function test_a_retro_order_outside_the_categories_warns_and_is_recorded(): void
    {
        $response = $this->store($this->forbidden);

        $response->assertRedirect();

        $order = Order::where('is_backdated', true)->firstOrFail();

        $this->assertStringContainsString('Візитівки', session('warning') ?? '');

        $entry = AuditLog::where('event_type', 'category_not_allowed')->latest('id')->first();

        $this->assertNotNull($entry, 'the month carries a note of what was ordered outside the rules');
        $this->assertSame('backdated', $entry->meta['source'] ?? null);
        $this->assertSame($order->id, $entry->meta['order_id'] ?? null);
    }

    /** It warns; it does not refuse. The admin is correcting a month, not placing an order. */
    public function test_the_retro_order_is_still_saved(): void
    {
        $this->store($this->forbidden);

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, Order::where('is_backdated', true)->count());
    }

    public function test_a_retro_order_inside_the_categories_passes_quietly(): void
    {
        $this->store($this->allowed);

        $this->assertNull(session('warning'));
        $this->assertDatabaseMissing('audit_logs', ['event_type' => 'category_not_allowed']);
    }

    /**
     * The case multi-category orders make routine: one retro order holding an
     * allowed and a forbidden service. The rule is per item, so it must name the
     * forbidden one and leave the allowed one out of the warning entirely.
     */
    public function test_a_mixed_retro_order_names_only_the_forbidden_half(): void
    {
        $this->actingAs($this->admin)->post(route('admin.backdated-orders.store'), [
            'order_date'        => '2025-08-15',
            'authorized_person' => 'Іваненко Іван Іванович',
            'cost_center'       => 'БШК',
            'items'             => [
                ['service_id' => $this->allowed->id, 'quantity' => 5],
                ['service_id' => $this->forbidden->id, 'quantity' => 2],
            ],
        ])->assertRedirect();

        $warning = session('warning') ?? '';

        $this->assertStringContainsString('Візитівки', $warning);
        $this->assertStringNotContainsString('Чорно-білий друк', $warning);

        $entry = AuditLog::where('event_type', 'category_not_allowed')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame('backdated', $entry->meta['source'] ?? null);
        $this->assertCount(1, $entry->meta['services'] ?? [], 'only the forbidden line is recorded');
    }

    /**
     * The two quotas stay out, and that is the decision this round did **not**
     * overturn: retro records a period whose quota has already been spent and
     * reset, so `limit_exceeded` remains the admin's checkbox alone.
     */
    public function test_the_quotas_are_still_not_asked_on_a_retro_order(): void
    {
        $group = SignatoryGroup::where('name', 'Деканат')->firstOrFail();
        $group->update(['daily_limit' => 1]);

        $this->store($this->allowed);

        $this->assertDatabaseMissing('audit_logs', ['event_type' => 'limit_exceeded']);
        $this->assertFalse(Order::where('is_backdated', true)->firstOrFail()->limit_exceeded);
    }

    // ─── Update ──────────────────────────────────────────

    public function test_editing_a_retro_order_onto_a_forbidden_service_is_recorded(): void
    {
        $this->store($this->allowed);
        $order = Order::where('is_backdated', true)->firstOrFail();

        $this->assertDatabaseMissing('audit_logs', ['event_type' => 'category_not_allowed']);

        $this->actingAs($this->admin)->put(route('admin.backdated-orders.update', $order), [
            'order_date'        => '2025-08-15',
            'authorized_person' => 'Іваненко Іван Іванович',
            'cost_center'       => 'БШК',
            'items'             => [
                ['service_id' => $this->forbidden->id, 'quantity' => 5],
            ],
        ])->assertRedirect();

        $this->assertStringContainsString('Візитівки', session('warning') ?? '');

        $entry = AuditLog::where('event_type', 'category_not_allowed')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame('backdated', $entry->meta['source'] ?? null);
    }

    /*
     * The import half of the same rule lives in `ImportRetroOrdersTest`, where
     * the constructor fixtures the command needs (format, paper, sidedness) are
     * already built: `test_a_service_outside_the_signatory_categories_is_recorded`.
     */
}
