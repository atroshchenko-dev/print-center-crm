<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EquipmentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Equipment Model
 *
 * @property int $id
 * @property string $name
 * @property string|null $serial_number
 * @property EquipmentType $type
 * @property bool $is_active
 */
class Equipment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'serial_number',
        'type',
        'initial_counter',
        'has_counter',
        'is_active',
    ];

    protected $casts = [
        'type'            => EquipmentType::class,
        'is_active'       => 'boolean',
        'has_counter'     => 'boolean',
        'initial_counter' => 'integer',
    ];

    // ─── Relationships ───────────────────────────────────

    public function counterReadings(): HasMany
    {
        return $this->hasMany(ShiftCounterReading::class);
    }

    // A getCounterColumn() wrapper stood here and nobody called it, while the
    // counter report kept a fourth copy of the same mapping inline. The one
    // definition is EquipmentType::counterColumn(); ask the type directly.
}
