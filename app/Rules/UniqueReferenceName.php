<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * One live row per name in a reference book — the application half of the
 * `unique_department_name` / `unique_signatory_full_name` indexes.
 *
 * The index is what makes the rule true; this is what makes it *readable*.
 * Without it the admin typing a name that already exists gets a 500 out of
 * PostgreSQL instead of a sentence under the field, and the two are the same
 * event — the difference is entirely in who it is written for.
 *
 * It matches the index exactly, and every clause is load-bearing:
 *
 *  - **`LOWER(TRIM(…))`** — production holds «Кафедра ІМЗД» and «кафедра ІМЗД»,
 *    one department, one letter of case apart. Comparing raw strings is what
 *    let the second one in;
 *  - **`deleted_at IS NULL`** — a deleted row keeps its name for the journal.
 *    Two deleted «Марунова О.О.» are allowed to stay, and re-using their
 *    name is allowed too, because the reference book is not where that history
 *    is kept;
 *  - **`ignore`** — saving a row without touching its name must not collide
 *    with itself. The reference page posts the whole row on every edit,
 *    including deactivations.
 */
class UniqueReferenceName implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly string $column,
        private readonly ?int $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return; // `required` reports an empty name
        }

        $exists = DB::table($this->table)
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(TRIM({$this->column})) = ?", [mb_strtolower(trim($value))])
            ->when($this->ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($exists) {
            $fail("«{$value}» вже є в довіднику. Назви, що різняться лише регістром або пробілами, вважаються однаковими.");
        }
    }
}
