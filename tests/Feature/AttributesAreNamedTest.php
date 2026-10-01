<?php

declare(strict_types=1);

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every validated field has a Ukrainian name, because the banner shows nothing else.
 *
 * The owner's decision of 2026-08-02 (CLOSEOUT §1.9) made `AppLayout` and
 * `AuthLayout` render every refusal in a banner, so no rule is invisible any
 * more. What nobody measured is what the banner then *says*: Laravel's sentence
 * and nothing else, with `:attribute` filled from `lang/uk/validation.php`. A
 * field missing from that list falls back to its own key with the underscores
 * turned into spaces.
 *
 * Round 24 measured it: **132 of 145 validated fields had no entry**, so what
 * reached the operator was «Поле min quantity є обов'язковим.» — a Ukrainian
 * sentence ending in a database column. Confirmed by running the validator
 * rather than by reading the framework.
 *
 * This matters most exactly where the debt was worst. `Admin/Inventory/Index.vue`
 * carries seven forms and shows no message under any field, so the banner is the
 * whole of what its operator gets — and `min_quantity`, `units_per_pack`,
 * `refill_cost`, `avg_cost` are all its fields.
 *
 * One entry per key, spelled in full: Laravel looks the exact key up, so
 * `items.*.quantity` is a different lookup from `quantity`.
 *
 * **Why a test and not a habit.** The list drifts the moment a rule is added,
 * and the drift is invisible — the form still refuses, the banner still appears,
 * only the sentence quietly names a column. Nothing about the page looks broken.
 * That is the same shape as R20-4, one level down.
 */
class AttributesAreNamedTest extends TestCase
{
    public function test_every_validated_field_has_a_ukrainian_name(): void
    {
        $named = array_keys($this->attributes());
        $missing = array_values(array_diff($this->validatedFields(), $named));

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "These fields would reach the operator as database columns.\n".
            "Add each to the `attributes` array of lang/uk/validation.php, in the words the\n".
            "form itself uses — the banner in AppLayout shows the message and nothing else, so\n".
            'the name inside it is the only clue which field was refused.',
        );
    }

    /**
     * A name for a field nobody validates is a name nobody reads.
     *
     * Kept strict on purpose: this is how `cancel_reason` was found, translated
     * and unused, while the rule it was written for validates `reason`.
     */
    public function test_no_name_is_kept_for_a_field_that_is_never_validated(): void
    {
        $unused = array_values(array_diff(array_keys($this->attributes()), $this->validatedFields()));

        sort($unused);

        $this->assertSame(
            [],
            $unused,
            'These names are never used: no rules() validates a key by that name.',
        );
    }

    /**
     * The end of the chain, run rather than reasoned about.
     *
     * The two assertions above compare lists; this one asks the validator what a
     * refusal actually reads like, which is the only thing the operator sees.
     */
    public function test_a_refusal_reads_as_ukrainian_all_the_way_through(): void
    {
        $this->app->setLocale('uk');

        $messages = validator(
            ['min_quantity' => null, 'units_per_pack' => 'x'],
            ['min_quantity' => 'required', 'units_per_pack' => 'integer'],
        )->errors()->all();

        $this->assertSame(
            [
                'Поле мінімальний залишок є обов\'язковим.',
                'Поле одиниць в упаковці має бути цілим числом.',
            ],
            $messages,
        );
    }

    /**
     * Keys any rules() validates, read out of the sources.
     *
     * By regex and not by resolving the classes: a FormRequest wants a request
     * to resolve, and several of these build their rules from the route.
     *
     * @return array<int, string>
     */
    private function validatedFields(): array
    {
        $fields = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path('Http/Requests'))
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! preg_match('/function rules\(\).*?\n    \}/s', $source, $body)) {
                continue;
            }

            preg_match_all("/^\s*'([a-zA-Z0-9_.*]+)'\s*=>/m", $body[0], $keys);

            foreach ($keys[1] as $key) {
                $fields[$key] = true;
            }
        }

        $this->assertNotEmpty($fields, 'No rules() were found — the reader stopped matching.');

        return array_keys($fields);
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        $lang = require lang_path('uk/validation.php');

        return $lang['attributes'] ?? [];
    }
}
