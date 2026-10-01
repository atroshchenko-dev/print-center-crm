<?php

/**
 * How long the signatory-group pool takes to compute, on data the size this
 * department actually produces. Round 18, §2.1 of the brief: the pool is summed
 * from the orders every time it is asked for, and since round 17 that happens on
 * every edit as well as every creation. Nobody had measured it.
 *
 * Run (PowerShell, testing database):
 *   docker run --rm --network crm-t -v "…:/app" -w /app crm-php:8.3 \
 *     php -d memory_limit=1G scripts/bench-group-pool.php
 */

use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SignatoryGroup;
use App\Models\UniversityRef;
use App\Models\User;
use App\Services\LimitService;
use App\Support\KyivClock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

Artisan::call('migrate:fresh', ['--force' => true]);

$user = User::factory()->create(['role' => 'executor']);
$category = ServiceCategory::factory()->create();
$service = Service::factory()->create([
    'service_category_id' => $category->id,
    'type' => 'static',
    'counter_type' => 'bw',
    'clicks_per_unit' => 1,
]);

$group = SignatoryGroup::factory()->create(['name' => 'Деканат', 'daily_limit' => 100000]);

$names = [];
for ($i = 0; $i < 40; $i++) {
    $names[] = UniversityRef::factory()->create([
        'full_name' => "Підписант №{$i}",
        'signatory_group_id' => $group->id,
    ])->full_name;
}

// A month of orders, an order of magnitude above what the department produces
// (the retro import of one real month was 530 orders).
$ORDERS = (int) ($argv[1] ?? 5000);
$now = now();

$rows = [];
$items = [];
for ($i = 1; $i <= $ORDERS; $i++) {
    $rows[] = [
        'order_number' => 'INT-2608-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
        'order_prefix' => 'INT',
        'order_year' => 26,
        'order_month' => 8,
        'order_sequence' => $i,
        'type' => 'internal',
        'status' => 'completed_issued',
        'user_id' => $user->id,
        'authorized_person' => $names[$i % count($names)],
        'cost_center' => 'Кафедра №'.($i % 20),
        'total_commercial' => 0,
        'total_cost' => 10,
        'is_backdated' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    if (count($rows) === 500) {
        DB::table('orders')->insert($rows);
        $rows = [];
    }
}
if ($rows) {
    DB::table('orders')->insert($rows);
}

foreach (Order::query()->pluck('id') as $n => $id) {
    $items[] = [
        'order_id' => $id,
        'service_id' => $service->id,
        'service_name' => $service->name,
        'service_snapshot' => json_encode([]),
        'quantity' => 10,
        'unit_price_commercial' => 0,
        'unit_price_cost' => 1,
        'total_price_commercial' => 0,
        'total_price_cost' => 10,
        'bw_clicks' => 10,
        'color_clicks' => 0,
        'riso_clicks' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    if (count($items) === 500) {
        DB::table('order_items')->insert($items);
        $items = [];
    }
}
if ($items) {
    DB::table('order_items')->insert($items);
}

$orderCount = DB::table('orders')->count();
$itemCount = DB::table('order_items')->count();

$limits = app(LimitService::class);

// Warm the connection, then measure.
$limits->checkSignatoryLimit($names[0]);

$runs = 10;
$start = microtime(true);
for ($i = 0; $i < $runs; $i++) {
    $result = $limits->checkSignatoryLimit($names[$i % count($names)]);
}
$elapsed = (microtime(true) - $start) * 1000 / $runs;

echo "orders: {$orderCount}, items: {$itemCount}, signatories in group: ".count($names)."\n";
echo 'usage returned: '.$result['usage']."\n";
echo 'checkSignatoryLimit: '.number_format($elapsed, 1)." ms per call\n\n";

[$from, $to] = KyivClock::monthWindow();
$plan = DB::select('EXPLAIN ANALYZE
    SELECT SUM(COALESCE(order_items.bw_clicks,0) + COALESCE(order_items.color_clicks,0) + COALESCE(order_items.riso_clicks,0))
    FROM order_items
    JOIN orders ON orders.id = order_items.order_id
    WHERE orders.deleted_at IS NULL
      AND orders.type = ?
      AND orders.is_backdated = false
      AND orders.status != ?
      AND orders.authorized_person = ANY(?)
      AND orders.created_at BETWEEN ? AND ?',
    ['internal', 'cancelled', '{'.implode(',', array_map(fn ($n) => '"'.$n.'"', $names)).'}', $from, $to]
);

foreach ($plan as $line) {
    echo reset($line)."\n";
}
