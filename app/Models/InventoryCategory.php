<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryCategory extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Well-known category slugs for code references */
    public const PAPER_SLUG = 'Папір';

    public const CONSUMABLES_SLUG = 'Витратні матеріали';

    public const HARD_BINDING_PAIRS_SLUG = 'Тверда палітурка (канали + обкладинки)';

    protected $fillable = [
        'name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
}
