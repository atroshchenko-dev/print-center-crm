<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Setting — key-value system configuration.
 *
 * Provides a simple API for reading/writing settings:
 *   Setting::getValue('cache_enabled')       → bool/int/float/string
 *   Setting::setValue('cache_enabled', true) → void (auto-caches)
 *
 * Named getValue/setValue, not get/set, which is what this header promised
 * until the round-6 audit — the same class whose `.env` switch turned out to
 * be wired to nothing.
 *
 * All values are cached in Redis with 1-hour TTL for zero-overhead reads.
 * setValue() invalidates that cache; writing a row any other way does not.
 *
 * No SoftDeletes — this is a config store, not an entity.
 */
class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['key', 'value', 'updated_at'];

    private const CACHE_KEY = 'app:settings';
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Get a setting value by key with optional default.
     *
     * Returns typed value: 'true'/'false' → bool, numeric → number, else string.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $all = static::getAllCached();

        if (! array_key_exists($key, $all)) {
            return $default;
        }

        return static::cast($all[$key]);
    }

    /**
     * Set a setting value (upsert + cache invalidation).
     */
    public static function setValue(string $key, mixed $value): void
    {
        $stringValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        static::updateOrCreate(
            ['key' => $key],
            ['value' => $stringValue, 'updated_at' => now()],
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Get all settings as key-value array (cached).
     *
     * @return array<string, string>
     */
    public static function getAllCached(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return static::pluck('value', 'key')->toArray();
        });
    }

    /**
     * Cast string value to appropriate PHP type.
     */
    private static function cast(string $value): mixed
    {
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if (is_numeric($value)) return str_contains($value, '.') ? (float) $value : (int) $value;
        return $value;
    }
}
