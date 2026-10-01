<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ServiceParameterGroup Model
 *
 * Represents a logical step of parameter selection in the Constructor.
 * E.g., "Format", "Color", "Paper Type", "Post-press"
 *
 * @property int $id
 * @property int $service_id
 * @property string $name
 * @property string $ui_type  'radio' | 'checkbox'
 * @property bool $is_required
 * @property int $sort_order
 */
class ServiceParameterGroup extends Model
{
    use HasFactory;
    use \App\Models\Concerns\InvalidatesReferenceCache;
    use SoftDeletes;

    /** Groups are cached inside the services list, not on their own. */
    protected static string $referenceCacheKey = 'ref:services';

    /**
     * The group whose options are zeroed out when the customer brings their own
     * paper. The rule is keyed on this exact name — there is no flag column —
     * so renaming the group in the admin UI silently disables it.
     *
     * That rename only became possible in round 10: the group update endpoint
     * was routed from the start and called by no page, so a name could be
     * changed only by deleting the group. The edit form now warns when this
     * particular group is the one being renamed.
     *
     * Kept here so every reader is the same string, and mirrored in
     * resources/js/constants.js for the constructor UI.
     */
    public const PAPER = 'Тип паперу';

    protected $fillable = [
        'service_id',
        'name',
        'ui_type',
        'ui_style',
        'is_required',
        'sort_order',
        'depends_on',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'sort_order'  => 'integer',
        'depends_on'  => 'array',
    ];

    // ─── Relationships ───────────────────────────────────

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ServiceParameterOption::class, 'group_id')->orderBy('sort_order');
    }

    // ─── Helpers ─────────────────────────────────────────

    public function isRadio(): bool
    {
        return $this->ui_type === 'radio';
    }

    public function isCheckbox(): bool
    {
        return $this->ui_type === 'checkbox';
    }

    public function isDependentOn(): bool
    {
        return !empty($this->depends_on);
    }
}
