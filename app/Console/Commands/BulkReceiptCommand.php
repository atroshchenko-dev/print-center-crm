<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BulkReceiptCommand extends Command
{
    protected $signature = 'inventory:bulk-receipt {file : Path to JSON data file} {--force : Allow receipt on items that already have stock}';
    protected $description = 'Bulk receipt inventory items from a JSON file';

    public function handle(InventoryService $service): int
    {
        $filePath = base_path($this->argument('file'));

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $data = json_decode(file_get_contents($filePath), true);

        if (! $data || ! isset($data['items']) || ! is_array($data['items'])) {
            $this->error('Invalid JSON format. Expected {"notes": "...", "items": [[name, qty, unitCost], ...]}');
            return 1;
        }

        $errors = $this->rowErrors($data['items']);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }
            $this->error('Нічого не оприбутковано.');

            return 1;
        }

        $admin = User::where('role', UserRole::Admin->value)->first();
        if (! $admin) {
            $this->error('Admin user not found!');
            return 1;
        }

        $notes = $data['notes'] ?? 'Масове оприбуткування';
        $total = 0.0;
        $lines = [];

        // The file lands whole or not at all. Each receiveStock() was already
        // atomic on its own, which said nothing about the run: a failure on the
        // fifth row left the first four received, and the operator's only way
        // back was to work out which ones those were.
        DB::transaction(function () use ($service, $data, $admin, $notes, &$total, &$lines) {
            foreach ($data['items'] as [$name, $qty, $unitCost]) {
                $item = InventoryItem::where('name', $name)->first();

                if (! $item) {
                    $lines[] = ['warn', "⚠ '{$name}' не знайдено в БД, пропускаю."];
                    continue;
                }

                if ($item->current_quantity > 0 && ! $this->option('force')) {
                    $lines[] = ['warn', "⚠ '{$name}' вже має залишок {$item->current_quantity} — пропускаю (використайте --force для додаткового приходу)."];
                    continue;
                }

                $totalCost = $qty * $unitCost;

                $service->receiveStock(
                    item:      $item,
                    quantity:  $qty,
                    totalCost: $totalCost,
                    user:      $admin,
                    notes:     $notes
                );

                $item->refresh();
                $lines[] = ['info', "✅ {$name}: +{$qty} {$item->unit} × {$unitCost} ₴ = " . number_format($totalCost, 2) . " ₴ | Avg: {$item->avg_cost} ₴"];
                $total += $totalCost;
            }
        });

        // Printed only once the transaction has committed — a "✅" for a row
        // that was rolled back a moment later is worse than no line at all.
        $received = 0;
        foreach ($lines as [$level, $text]) {
            if ($level === 'info') {
                $this->info($text);
                $received++;
            } else {
                $this->warn($text);
            }
        }

        $this->newLine();
        $this->info("🏁 Оприбутковано {$received} позицій на суму " . number_format($total, 2) . " ₴");

        return 0;
    }

    /**
     * Everything wrong with the file, found before anything is written.
     *
     * The loop used to destructure straight into the receipt call, so a row
     * missing a field raised its error halfway down the file — after the rows
     * above it had been received. And nothing looked at the numbers: a negative
     * quantity goes through receiveStock() as a movement of type `in` that
     * subtracts stock, at a unit cost the method reports as 0, dragging the
     * running average down with it.
     *
     * @param  array<mixed>  $rows
     * @return string[]
     */
    private function rowErrors(array $rows): array
    {
        $errors = [];

        foreach ($rows as $i => $row) {
            $at = 'Рядок #' . ($i + 1);

            if (! is_array($row) || ! array_is_list($row) || count($row) !== 3) {
                $errors[] = "{$at}: очікується [назва, кількість, ціна за одиницю].";
                continue;
            }

            [$name, $qty, $unitCost] = $row;

            if (! is_string($name) || trim($name) === '') {
                $errors[] = "{$at}: порожня назва позиції.";
            } else {
                $at .= " ('{$name}')";
            }

            if (! is_numeric($qty) || (float) $qty <= 0) {
                $errors[] = "{$at}: кількість має бути більшою за нуль, отримано " . var_export($qty, true) . '.';
            }

            if (! is_numeric($unitCost) || (float) $unitCost < 0) {
                $errors[] = "{$at}: ціна за одиницю не може бути від'ємною, отримано " . var_export($unitCost, true) . '.';
            }
        }

        return $errors;
    }
}
