<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Material;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The scheduled price change, and the clock it is entered on.
 *
 * The field is labelled "Активація (Kyiv)" and the value is stored in UTC. The
 * conversion between the two sat in exactly one of the two write paths, and in
 * neither of the read ones — so the same instant came out differently depending
 * on which door it went through.
 *
 * click_cost feeds cost_markup on every linked constructor option, and that
 * feeds order prices. When a price change lands is a money question.
 */
class MaterialScheduledPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role'        => 'admin',
            'permissions' => User::permissionKeys(),
        ]);

        // 12:00 Kyiv — far from any boundary, so only the offset is under test.
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00', 'Europe/Kyiv'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The instant the admin means when they type 2026-07-30 00:30 into a Kyiv field. */
    private const MEANT = '2026-07-29 21:30:00';   // UTC

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'                 => 'Ч/Б друк',
            'counter_type'         => 'bw',
            'click_cost'           => 0.37,
            'pending_click_cost'   => 0.42,
            'pending_activated_at' => '2026-07-30T00:30',
            'is_active'            => true,
        ], $overrides);
    }

    public function test_creating_a_material_reads_the_activation_time_as_kyiv(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $material = Material::sole();

        $this->assertSame(
            self::MEANT,
            $material->pending_activated_at->utc()->format('Y-m-d H:i:s'),
            'Created through the form, the Kyiv time was stored as if it were UTC — three hours late.',
        );
    }

    public function test_updating_a_material_reads_the_activation_time_as_kyiv(): void
    {
        $material = Material::factory()->create(['counter_type' => 'bw']);

        $this->actingAs($this->admin)
            ->patch(route('admin.materials.update', $material), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(
            self::MEANT,
            $material->fresh()->pending_activated_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    /**
     * The two doors have to agree. They did not: update() converted, store()
     * did not, and the same form posts to both.
     */
    public function test_both_doors_store_the_same_instant(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload())
            ->assertSessionHasNoErrors();
        $created = Material::sole();

        $edited = Material::factory()->create(['counter_type' => 'color']);
        $this->actingAs($this->admin)
            ->patch(route('admin.materials.update', $edited), $this->payload(['counter_type' => 'color']))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $created->pending_activated_at->utc()->format('Y-m-d H:i:s'),
            $edited->fresh()->pending_activated_at->utc()->format('Y-m-d H:i:s'),
            'Creating and editing put the same typed time in two different places.',
        );
    }

    /**
     * "after:now" compared a Kyiv wall clock against a UTC one, so anything up
     * to the offset in the past passed it. A price change "scheduled" for
     * 10:00 when it is already 12:00 in Kyiv is not scheduled — the next
     * scheduler run activates it, in the middle of the working day, which is
     * the one thing the feature exists to avoid.
     */
    public function test_an_activation_time_already_past_in_kyiv_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload([
                'pending_activated_at' => '2026-07-29T10:00',   // two hours ago in Kyiv
            ]))
            ->assertSessionHasErrors('pending_activated_at');

        $this->assertSame(0, Material::count());
    }

    public function test_a_future_kyiv_time_is_still_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload([
                'pending_activated_at' => '2026-07-29T14:00',   // two hours from now in Kyiv
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            '2026-07-29 11:00:00',
            Material::sole()->pending_activated_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Both doors have to leave the same trail, too.
     *
     * The conversion was levelled between store() and update(); the audit entry
     * was not. A price change scheduled on the material's first save — which is
     * where an opening price is set — went into the database and into the
     * scheduler's queue without a line saying who scheduled it or for when.
     */
    public function test_creating_a_material_records_the_scheduled_price(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            AuditLog::where('event_type', 'price_scheduled')->count(),
            'A price change was scheduled through store() and left no audit entry.',
        );
    }

    public function test_editing_a_material_still_records_the_scheduled_price(): void
    {
        $material = Material::factory()->create(['counter_type' => 'bw']);

        $this->actingAs($this->admin)
            ->patch(route('admin.materials.update', $material), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AuditLog::where('event_type', 'price_scheduled')->count());
    }

    /**
     * A pending price the scheduler will never look at.
     *
     * activatePendingPrices() takes only rows where both columns are set, so
     * half a schedule is a price change that silently never happens — and the
     * audit line, which names both halves, reached for a key that was not there.
     */
    public function test_a_pending_price_without_an_activation_time_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload([
                'pending_activated_at' => null,
            ]))
            ->assertSessionHasErrors('pending_activated_at');

        $this->assertSame(0, Material::count());
    }

    public function test_an_activation_time_without_a_pending_price_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload([
                'pending_click_cost' => null,
            ]))
            ->assertSessionHasErrors('pending_click_cost');

        $this->assertSame(0, Material::count());
    }

    public function test_a_material_with_no_schedule_at_all_is_still_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload([
                'pending_click_cost'   => null,
                'pending_activated_at' => null,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Material::count());
        $this->assertSame(0, AuditLog::where('event_type', 'price_scheduled')->count());
    }

    /**
     * Editing without touching the field must leave it where it was. The form
     * loaded the raw UTC string into a field labelled Kyiv, and saving
     * converted that displayed value again — so every trip through the edit
     * form walked the activation three hours earlier.
     */
    public function test_a_round_trip_through_the_edit_form_does_not_move_the_time(): void
    {
        $material = Material::factory()->create([
            'counter_type'         => 'bw',
            'pending_click_cost'   => 0.42,
            'pending_activated_at' => Carbon::parse(self::MEANT, 'UTC'),
        ]);

        // What the page hands to the form field for this material.
        $shown = $this->actingAs($this->admin)
            ->get(route('admin.materials.index'))
            ->viewData('page')['props']['materials'][0]['pending_activated_at_local'] ?? null;

        $this->assertNotNull(
            $shown,
            'The page gives the form no Kyiv-shaped value, so the field can only show the UTC one.',
        );
        $this->assertSame('2026-07-30T00:30', $shown);

        // Submit it back unchanged, as the form does.
        $this->actingAs($this->admin)
            ->patch(route('admin.materials.update', $material), $this->payload([
                'pending_activated_at' => $shown,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            self::MEANT,
            $material->fresh()->pending_activated_at->utc()->format('Y-m-d H:i:s'),
            'Saving the form unchanged moved the activation time.',
        );
    }

    /**
     * The page shows the same moment twice, and they have to agree — item 9.
     *
     * The item read «час у "Активація (Kyiv)" збігається з таблицею поруч» and
     * waited on «наступне планування ціни» — a trigger nobody schedules on
     * purpose, so it had been visited three times and left as it was.
     *
     * There is nothing to wait for. The page prints the instant twice from two
     * different fields: the **table** formats `pending_activated_at` with an
     * explicit `timeZone: 'Europe/Kyiv'`, and the **edit form** pre-fills
     * `pending_activated_at_local`, which the controller builds with
     * `KyivClock::toLocalInput()`. Both come off one request, so one request
     * answers the question.
     *
     * This is the half a test can hold: the two fields name the same moment.
     * The other half — that `toLocaleString` is given the zone rather than the
     * viewer's — is one option in one line, and it is there (`Materials/Index.vue`).
     */
    public function test_the_table_and_the_edit_field_name_the_same_moment(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->get(route('admin.materials.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $material = $page->toArray()['props']['materials'][0];

                // What the edit form pre-fills — Kyiv wall clock, the shape an
                // `<input type="datetime-local">` speaks.
                $this->assertSame('2026-07-30T00:30', $material['pending_activated_at_local']);

                // What the table formats — the instant itself. Read back in
                // Kyiv it must be the same wall clock, or the two halves of
                // one page disagree by three hours (the R21-6 class).
                $this->assertSame(
                    '2026-07-30 00:30',
                    Carbon::parse($material['pending_activated_at'])
                        ->setTimezone('Europe/Kyiv')
                        ->format('Y-m-d H:i'),
                );
            });
    }
}
