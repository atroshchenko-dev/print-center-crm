<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\InvalidatesReferenceCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CostCenterInitiator — хто замовляє від конкретного підрозділу.
 *
 * `orders.initiator` — вільний рядок без жодного довідника за ним: оператор
 * щоразу набирає ім'я руками, і та сама людина потрапляє в історію як
 * «Іванова», «Іванова О.В.» та «іванова о.в.». Пари накопичуються з фактичних
 * замовлень, як і `SignatoryCostCenter`, тільки ключем тут є підрозділ, а не
 * підписант: ініціатор належить підрозділу.
 *
 * Окремого довідника людей немає навмисно — ініціатор не є сутністю в цій
 * системі: ні email, ні ролі, ні прав, лише ім'я в рядку замовлення.
 *
 * `orders_count = 0` означає рядок, доданий адміністратором наперед.
 *
 * @property int $department_id
 * @property string $name
 * @property string $name_key
 * @property int $orders_count
 * @property Carbon|null $last_used_at
 * @property-read Department|null $department
 */
class CostCenterInitiator extends Model
{
    use HasFactory;
    use InvalidatesReferenceCache;

    protected static string $referenceCacheKey = 'ref:cost_center_initiators';

    protected $fillable = [
        'department_id',
        'name',
        'name_key',
        'orders_count',
        'last_used_at',
    ];

    protected $casts = [
        'orders_count' => 'integer',
        'last_used_at' => 'datetime',
    ];

    // ─── Relationships ───────────────────────────────────

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    // ─── Accumulation ────────────────────────────────────

    /** Ключ, за яким два написання одного імені вважаються одним. */
    public static function normalizeKey(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }

    /**
     * Записати пару, названу замовленням.
     *
     * Підрозділ шукається нормалізовано, як у `Department::remember()`.
     * Немає його в довіднику (не заведений, деактивований) — пара не пишеться
     * і **нічого не кидається**: метод викликається з-під збереження
     * замовлення й не має права його завалити.
     */
    public static function remember(?string $costCenterName, ?string $initiatorName, ?\DateTimeInterface $usedAt = null): void
    {
        $costCenterName = trim((string) $costCenterName);
        $initiatorName = trim((string) $initiatorName);

        if ($costCenterName === '' || $initiatorName === '') {
            return;
        }

        $department = Department::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($costCenterName)])->first();

        if (! $department) {
            return;
        }

        $usedAt = $usedAt ?? now();

        // `name` лише у другому аргументі: `firstOrNew` застосовує його тільки
        // до нового рядка, тож перше написання переживає всі пізніші.
        $pair = static::firstOrNew(
            [
                'department_id' => $department->id,
                'name_key'      => static::normalizeKey($initiatorName),
            ],
            ['name' => $initiatorName],
        );

        self::countOneUse($pair, $usedAt);

        // Той самий захист, що в `SignatoryCostCenter::remember()`: read-then-write
        // проти унікального індексу, два оператори одночасно — і той, хто програв,
        // отримує порушення обмеження **зсередини транзакції замовлення**.
        // Вкладена транзакція — це SAVEPOINT, і саме він робить перехоплення
        // можливим на PostgreSQL: без нього невдалий запит лишає зовнішню
        // транзакцію в стані abort, і ковтання винятку лише перенесло б падіння
        // на наступний запит.
        try {
            DB::transaction(static fn () => $pair->save());
        } catch (UniqueConstraintViolationException) {
            $winner = static::where('department_id', $department->id)
                ->where('name_key', static::normalizeKey($initiatorName))
                ->first();

            if ($winner) {
                self::countOneUse($winner, $usedAt);
                $winner->save();
            }
        }
    }

    /**
     * Ще одне замовлення на цю пару.
     *
     * `last_used_at` рухається лише вперед: бекфіл ходить по історії не
     * в хронологічному порядку, а редагування старого замовлення приносить
     * стару дату.
     */
    private static function countOneUse(self $pair, \DateTimeInterface $usedAt): void
    {
        // Властивість кастована в `Carbon|null`, а параметр навмисно
        // приймає будь-який `DateTimeInterface`.
        $usedAt = $usedAt instanceof Carbon ? $usedAt : Carbon::instance($usedAt);

        $pair->orders_count = ($pair->orders_count ?? 0) + 1;

        if ($pair->last_used_at === null || $pair->last_used_at->lt($usedAt)) {
            $pair->last_used_at = $usedAt;
        }
    }
}
