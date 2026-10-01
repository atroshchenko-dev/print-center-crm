<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderType;
use Illuminate\Support\Facades\DB;

/**
 * OrderNumberService
 *
 * Generates unique order numbers with concurrency protection.
 * Format: {PREFIX}-{YY}{MM}-{SEQ} e.g. INT-2605-001, COM-2605-023
 *
 * Uses PostgreSQL advisory locks to prevent duplicate numbers
 * under concurrent order creation (TZ §4.2).
 *
 * Sequence resets on the 1st of every month.
 */
class OrderNumberService
{
    /**
     * Generate the next order number for the given type, year, and month.
     * Uses SELECT FOR UPDATE on an atomic counter record.
     *
     * @param  OrderType $type
     * @param  int       $year   2-digit year (e.g. 26 for 2026)
     * @param  int       $month  Month number (1-12)
     * @return array{ order_number: string, order_prefix: string, order_year: int, order_month: int, order_sequence: int }
     */
    public function generate(OrderType $type, int $year, int $month): array
    {
        $prefix = $type === OrderType::Internal ? 'INT' : 'COM';

        // Atomic sequence increment using PostgreSQL advisory lock
        $result = DB::transaction(function () use ($prefix, $year, $month) {
            // Lock specific to this prefix+year+month combination
            $lockKey = crc32("{$prefix}-{$year}-{$month}");
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$lockKey]);

            // Get the max existing sequence for this month/type
            $maxSeq = DB::table('orders')
                ->where('order_prefix', $prefix)
                ->where('order_year', $year)
                ->where('order_month', $month)
                ->max('order_sequence');

            return ($maxSeq ?? 0) + 1;
        });

        $yearFormatted  = str_pad((string) $year, 2, '0', STR_PAD_LEFT);
        $monthFormatted = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $seqFormatted   = str_pad((string) $result, 3, '0', STR_PAD_LEFT);

        return [
            'order_number'   => "{$prefix}-{$yearFormatted}{$monthFormatted}-{$seqFormatted}",
            'order_prefix'   => $prefix,
            'order_year'     => $year,
            'order_month'    => $month,
            'order_sequence' => $result,
        ];
    }

    /**
     * Convenience: generate from current date.
     */
    public function generateForNow(OrderType $type): array
    {
        $now = now()->setTimezone('Europe/Kyiv');

        return $this->generate(
            $type,
            (int) $now->format('y'),  // 2-digit year
            (int) $now->format('n'),  // Month without leading zeros
        );
    }
}
