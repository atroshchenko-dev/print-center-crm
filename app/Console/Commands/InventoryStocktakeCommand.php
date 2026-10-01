<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Apply a physical stocktake of the refillable consumables from a JSON file.
 *
 * The file mirrors the paper table it is typed from — [name, in printer, full,
 * empty] — because a file that has to be mentally re-derived from the sheet is
 * a file whose errors nobody sees. The first two columns are summed here: a
 * tube standing in a machine is a filled tube.
 *
 * inventory:bulk-receipt is the other file-driven entry point and deliberately
 * behaves differently: it books a priced receipt on top of what is there and
 * steps over rows it cannot resolve. This one overwrites both balances, so an
 * unresolvable row means a position nobody counted, and the file waits.
 */
class InventoryStocktakeCommand extends Command
{
    protected $signature = 'inventory:stocktake {file : Path to JSON data file} {--dry-run : Порахувати й показати, нічого не записуючи}';

    protected $description = 'Apply a toner stocktake from a JSON file';

    /** decimal(12,4): anything smaller is the same number written twice. */
    private const EPSILON = 0.00005;

    public function handle(InventoryService $service, TelegramService $telegram): int
    {
        $filePath = base_path($this->argument('file'));

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return 1;
        }

        $data = json_decode(file_get_contents($filePath), true);

        if (! $data || ! isset($data['items']) || ! is_array($data['items'])) {
            $this->error('Invalid JSON format. Expected {"notes": "...", "items": [[name, inPrinter, full, empty], ...]}');

            return 1;
        }

        [$rows, $errors] = $this->readRows($data['items']);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }
            $this->error('Нічого не змінено.');

            return 1;
        }

        $admin = User::where('role', UserRole::Admin->value)->first();
        if (! $admin) {
            $this->error('Admin user not found!');

            return 1;
        }

        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[dry-run] ' : '';
        $notes = $data['notes'] ?? 'Інвентаризація';

        // Split before writing: what changes and what does not. The lines are
        // printed after the transaction commits — a «✅» for a row rolled back
        // a moment later is worse than no line at all.
        $changes = [];
        $unchanged = [];

        foreach ($rows as $row) {
            $item = $row['item'];
            $fullBefore = (float) $item->current_quantity;
            $emptyBefore = (float) $item->empty_quantity;

            if (abs($row['full'] - $fullBefore) < self::EPSILON
                && abs($row['empty'] - $emptyBefore) < self::EPSILON) {
                $unchanged[] = sprintf(
                    '=  %s: без змін (повні %s, порожні %s)',
                    $item->name,
                    $this->qty($fullBefore),
                    $this->qty($emptyBefore),
                );

                continue;
            }

            $changes[] = [
                'item'         => $item,
                'name'         => $item->name,
                'full_before'  => $fullBefore,
                'full_after'   => $row['full'],
                'empty_before' => $emptyBefore,
                'empty_after'  => $row['empty'],
            ];
        }

        if (! $dryRun && $changes !== []) {
            DB::transaction(function () use ($service, $changes, $admin, $notes) {
                foreach ($changes as $change) {
                    $service->stocktakeToner(
                        item: $change['item'],
                        fullQuantity: $change['full_after'],
                        emptyQuantity: $change['empty_after'],
                        user: $admin,
                        notes: $notes,
                    );
                }
            });
        }

        foreach ($changes as $change) {
            $this->info(sprintf(
                '%s✅ %s: повні %s → %s (%s), порожні %s → %s (%s)',
                $prefix,
                $change['name'],
                $this->qty($change['full_before']),
                $this->qty($change['full_after']),
                $this->signed($change['full_after'] - $change['full_before']),
                $this->qty($change['empty_before']),
                $this->qty($change['empty_after']),
                $this->signed($change['empty_after'] - $change['empty_before']),
            ));
        }

        foreach ($unchanged as $line) {
            $this->line($prefix.$line);
        }

        foreach ($this->missingFromFile($rows) as $name) {
            $this->warn(sprintf('%s⚠  %s є в категорії, але немає у файлі — залишок не змінено.', $prefix, $name));
        }

        $this->newLine();
        $this->info(sprintf(
            '%s🏁 Інвентаризація тонерів: оновлено %d позицій, без змін %d.',
            $prefix,
            count($changes),
            count($unchanged),
        ));

        if (! $dryRun && $changes !== []) {
            try {
                $telegram->stocktakeCompleted(array_map(
                    fn (array $c) => [
                        'name'         => $c['name'],
                        'full_before'  => $c['full_before'],
                        'full_after'   => $c['full_after'],
                        'empty_before' => $c['empty_before'],
                        'empty_after'  => $c['empty_after'],
                    ],
                    $changes,
                ));
            } catch (\Throwable) {
                // Non-critical — the stocktake is already filed.
            }
        }

        return 0;
    }

    /**
     * Every row resolved, and everything wrong with the file, found before
     * anything is written.
     *
     * @param  array<mixed>  $rows
     * @return array{0: array<int, array{item: InventoryItem, full: float, empty: float}>, 1: string[]}
     */
    private function readRows(array $rows): array
    {
        $resolved = [];
        $errors = [];
        $seen = [];

        foreach ($rows as $i => $row) {
            $at = 'Рядок #'.($i + 1);

            if (! is_array($row) || ! array_is_list($row) || count($row) !== 4) {
                $errors[] = "{$at}: очікується [назва, в принтері, повні, порожні].";

                continue;
            }

            [$name, $inPrinter, $full, $empty] = $row;

            if (! is_string($name) || trim($name) === '') {
                $errors[] = "{$at}: порожня назва позиції.";

                continue;
            }

            $at .= " ('{$name}')";

            $numbersValid = true;
            foreach (['в принтері' => $inPrinter, 'повні' => $full, 'порожні' => $empty] as $label => $value) {
                if (! is_numeric($value) || (float) $value < 0) {
                    $errors[] = "{$at}: «{$label}» має бути числом не менше нуля, отримано ".var_export($value, true).'.';
                    $numbersValid = false;
                }
            }

            if (isset($seen[$name])) {
                $errors[] = "{$at}: позиція вже є у рядку #{$seen[$name]}.";

                continue;
            }
            $seen[$name] = $i + 1;

            $item = InventoryItem::with('category')->where('name', $name)->first();

            if (! $item) {
                $errors[] = "{$at}: немає такої позиції на складі.";

                continue;
            }

            if ($item->category?->name !== InventoryCategory::CONSUMABLES_SLUG) {
                $errors[] = "{$at}: позиція не належить до «".InventoryCategory::CONSUMABLES_SLUG.'».';

                continue;
            }

            if (! $numbersValid) {
                continue;
            }

            $resolved[] = [
                'item'  => $item,
                'full'  => (float) $inPrinter + (float) $full,
                'empty' => (float) $empty,
            ];
        }

        return [$resolved, $errors];
    }

    /**
     * Names of consumables the file never mentioned.
     *
     * @param  array<int, array{item: InventoryItem, full: float, empty: float}>  $rows
     * @return string[]
     */
    private function missingFromFile(array $rows): array
    {
        $counted = array_map(fn (array $row) => $row['item']->id, $rows);

        return InventoryItem::query()
            ->whereRelation('category', 'name', InventoryCategory::CONSUMABLES_SLUG)
            ->whereNotIn('id', $counted)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /** Quantities as a person counts them: «4», not «4.0000». */
    private function qty(float $quantity): string
    {
        return (string) round($quantity, 4);
    }

    private function signed(float $delta): string
    {
        return ($delta > 0 ? '+' : '').$this->qty($delta);
    }
}
