<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A form that refuses has to say so.
 *
 * Found on production 2026-08-01, minutes after round 20 shipped: entering a
 * cost centre whose name already existed did nothing at all. The server was
 * right — 422, with the Ukrainian sentence the rule was written to produce —
 * and the page never rendered it, because the template had no `errors` in it
 * anywhere. Inertia keeps the message in `form.errors`; nothing was reading it.
 *
 * «Нічого не відбувається» is worse than a 500. A 500 is visible.
 *
 * Round 20 created the reachable case (the first uniqueness rules the admin
 * forms had ever carried), so round 20 fixes the two pages it made dangerous.
 * But the sweep found **sixteen** pages with a form and no error display, and
 * `AppLayout` only surfaces `flash`, never `errors` — so this is not two pages,
 * it is a class.
 *
 * The list below is a debt baseline, in the shape `phpstan-baseline.neon` uses:
 * every form currently silent is named here, so the test passes on today's tree
 * and **fails the moment a new one is added**. Forms leave this list by being
 * fixed, never by being appended to it.
 *
 * **The unit is the form, and round 21 had to change it.** The first version of
 * this test asked whether a *page* mentioned `errors.` anywhere, and a page
 * holds as many forms as it likes: `Admin/Inventory/Index.vue` has seven,
 * `Orders/Show.vue` four. `Admin/Equipment/Index.vue` proved what that costs —
 * its counter-adjustment modal renders one field's error, and that single line
 * kept the equipment form itself, silent, off the list entirely. One page
 * hidden, and the sixteen that were listed were never the size of the debt:
 * thirty forms are silent, not sixteen pages.
 *
 * A baseline that can be satisfied by decoration measures decoration. This one
 * names `Page.vue:formVariable`, which is the thing that actually goes quiet.
 *
 * ---
 *
 * **What this list means changed on 2026-08-02, and the change is the owner's
 * decision of that day (CLOSEOUT §1.9).** `AppLayout` and `AuthLayout` now
 * render `page.props.errors` the way they render `flash`, so **no refusal is
 * invisible any more** — every page with a form shows the server's sentence in
 * a banner. What the list measures now is the *second* half: whether the
 * message also appears **under the field it is about**.
 *
 * That is a real debt and a smaller one. A banner tells an operator that the
 * order was refused; only the field tells them which of twenty fields to fix.
 *
 * Two entries left the list for reasons worth writing down, because both were
 * the list being wrong rather than the code being fixed:
 *
 *  - **`Shift/Open.vue:form` was never silent.** It renders the counter-reading
 *    errors — the ones that actually fire there — through
 *    ``form.errors[`readings.${idx}.counter_value`]``, and the detector only
 *    looked for `errors.` with a dot. A page can be pinned to a baseline by the
 *    syntax it happens to use;
 *  - **`Shift/Close.vue:form` cannot be refused at all.** `CloseShiftRequest`
 *    returns `[]` from `rules()` — closing a shift is a confirmation, not a
 *    form. It stays listed anyway, and deliberately: the day somebody adds a
 *    rule there, this list is where they find out nobody is showing it.
 *
 * ---
 *
 * **Round 24 took the first two off the list, and checked reachability first.**
 * The plan was to sort what remains by how many fields a form carries, because
 * a banner saying "something is wrong" helps least on the longest form — and
 * `Admin/Inventory/Index.vue` carries seven forms, more than any other page.
 * Its `crudForm` (nine fields) and `receiptForm` (six) now render a message
 * under each field.
 *
 * Reachability was checked before the work, not assumed: every submit button
 * there is disabled only on `processing`, so `required`, `max:255`, `min:0.01`
 * and the `exists:` rules all reach the server. That is the check round 21 paid
 * for — the rule the round 21 brief called the costliest example turned out to
 * be one the client never lets through (CLOSEOUT §3).
 *
 * The other five forms on that page stay listed: two adjust a single quantity,
 * one picks two items, and the banner names the field now — see
 * `AttributesAreNamedTest`, which is what makes the banner worth reading at all.
 *
 * **Round 25 took `Admin/Services/Form.vue` — all three of it.** `baseForm`
 * (seven fields), `groupForm` (five) and `optionForm` (seven) each render a
 * message under the field now. Reachable, checked the same way: every submit
 * there is disabled on `processing` alone, and the rules are ordinary —
 * `required` on names, `max:255`, `numeric min:0` on the prices and the markup.
 *
 * **Round 29 took the last six — the owner's decision of 2026-08-03 — and the
 * reachability check changed what «fixing» meant on four of them.**
 *
 *  - **`Admin/Settings.vue`** was listed as `:*` because its forms are built in
 *    a loop. Four forms, one field each, and the field is called «значення» on
 *    all four — so the banner naming the field named a field the page has four
 *    of. The taxonomy that calls a one-field form banner-enough assumed one
 *    form per page. The detector now reads `holder[key] = useForm(` as
 *    well, so a page like this can leave the list by being fixed;
 *  - **the three toner forms** — `refillForm`, `installForm`, `adjustEmptyForm`
 *    — had their `required` and `min:` rules blocked by the client, every one.
 *    The refusal an operator actually meets there came from `InventoryService`
 *    as a flash: «Недостатньо порожніх картриджів: є 1, спроба заправити 5»,
 *    a toast attached to no field that left after twelve seconds. Round 29
 *    keyed it on `quantity`. Displaying errors without that would have
 *    been decoration — the list would have shrunk and nothing would have
 *    changed on screen;
 *  - **`Services/Index.vue:catForm`** is the one with an ordinary reachable
 *    rule: clearing «Порядок сортування» sends `null` into an `integer` rule
 *    with no `nullable`. Checked with the real `SaveServiceCategoryRequest`
 *    before the work, which is also what kept it in the six;
 *  - **`RisoPricing/Index.vue:newTier` is honest bookkeeping, not a fix.**
 *    Every rule it carries — `required`, `integer`, `min:1`, `numeric` — is
 *    blocked by the client, so nothing reaches the server today. The display
 *    costs three lines and means the next rule added there is not silent. It
 *    left the list because it renders its errors, not because it was refusing
 *    anything in silence.
 *
 * **`Admin/BackdatedOrders/Create.vue` stays, and this is the reason.** Its
 * submit is disabled until a date, a signatory and a non-empty cart exist —
 * which is most of what `StoreBackdatedOrderRequest` insists on, so the client
 * never lets those rules through (the round 21 lesson, again). What does reach
 * the server is `cost_center` at `max:255` and the per-item rules, and those
 * arrive keyed `items.0.quantity`, for fields that live inside the constructor
 * components rather than on this page. A banner naming the field is the honest
 * answer there, not a per-field binding onto something that is not a field.
 */
class FormsReportValidationErrorsTest extends TestCase
{
    /**
     * Forms that render no validation error, as `Page.vue:variable`.
     *
     * Sorted, because the list is compared as a whole and a stable order is
     * what makes the failure message readable. The costliest entries are
     * `Orders/Create.vue:form` and `Orders/Edit.vue:form` — the screens the
     * department spends its day in, where a refused order looks exactly like a
     * click that did not register.
     *
     * `Admin/Settings.vue:*` is one entry per unnamed form: that page builds a
     * `useForm()` per numeric setting into an object, so no variable can be
     * pointed at. Unnamed counts as silent — nothing there can be shown to
     * render an error.
     *
     * @var array<int, string>
     */
    private const KNOWN_SILENT = [
        'Admin/BackdatedOrders/Create.vue:form',
        'Admin/BackdatedOrders/Edit.vue:form',
        'Admin/BackdatedOrders/Index.vue:filterForm',
        'Admin/Inventory/Index.vue:convertForm',
        'Admin/Inventory/Index.vue:optionsForm',
        'Admin/Reconciliation/Index.vue:filterForm',
        'Admin/RisoPricing/Index.vue:form',
        'Admin/ServiceCategories/Index.vue:form',
        'Ledger/History.vue:form',
        'Orders/Show.vue:approvalForm',
        'Orders/Show.vue:cancelForm',
        'Orders/Show.vue:requestForm',
        'Orders/Show.vue:statusForm',
        'Shift/Close.vue:form',
    ];

    public function test_no_new_form_starts_swallowing_its_validation_errors(): void
    {
        $silent = [];

        foreach ($this->pagesWithAForm() as $relative => $source) {
            preg_match_all('/(?:const|let|var)\s+(\w+)\s*=\s*useForm\(/', $source, $named);

            foreach ($named[1] as $variable) {
                // Both spellings. `Shift/Open.vue` reaches its errors as
                // ``form.errors[`readings.${idx}.counter_value`]`` — a dotted
                // key cannot be written any other way — and a detector that
                // knew only `errors.` called that page silent for two rounds.
                $showsErrors = str_contains($source, $variable.'.errors.')
                    || str_contains($source, $variable.'.errors[');

                if (! $showsErrors) {
                    $silent[] = $relative.':'.$variable;
                }
            }

            // Forms built into a collection: `numericForms[f.key] = useForm(`.
            //
            // Round 29 taught this to the detector. `Admin/Settings.vue` builds
            // one form per numeric setting in a loop, so no `const` names any
            // of them, and the fallback below could only call the page `:*` —
            // «there is a form here and I cannot tell you anything about it».
            // That is not the same as silent, and pinning it as `:*` meant the
            // page could never leave the list by being fixed.
            $collected = $this->collectedForms($source);

            foreach ($collected as $variable) {
                // `numericForms[field.key].errors.value` — the subscript is
                // whatever the template loops over, so it is matched loosely
                // and the `.errors` after it is what counts.
                $showsErrors = (bool) preg_match(
                    '/'.preg_quote($variable, '/').'\[[^\]]+\]\.errors[.\[]/',
                    $source,
                );

                if (! $showsErrors) {
                    $silent[] = $relative.':'.$variable;
                }
            }

            // A form-constructor call this file could not attribute to
            // anything at all.
            //
            // Counted in the text, comments included — which round 29 met
            // immediately: a comment *explaining* this test invented a fifth,
            // unnamed form on `Admin/Settings.vue`. Left as is on purpose. The
            // alternative is parsing JavaScript to tell code from prose, and
            // the false positive is loud, instant and lands on whoever wrote
            // the comment. A quiet miss would be the expensive kind.
            $attributed = count($named[1]) + count($collected);

            for ($i = $attributed; $i < substr_count($source, 'useForm('); $i++) {
                $silent[] = $relative.':*';
            }
        }

        sort($silent);
        $known = self::KNOWN_SILENT;
        sort($known);

        $this->assertSame(
            $known,
            $silent,
            "A form must render its validation errors.\n".
            "If you added one to this list, fix the form instead: Inertia keeps the message in\n".
            "`<form>.errors.<field>`, and `AppLayout` never shows it — see Admin/Users/Index.vue.\n".
            'If you fixed one, remove it from KNOWN_SILENT.',
        );
    }

    /**
     * The safety net itself, pinned in both layouts.
     *
     * Every page in the application renders inside one of these two, and the
     * split is not obvious: `Shift/Open.vue` — counter readings and the
     * previous shift's cash, the form that starts the working day — sits on
     * `AuthLayout` beside the login screen, not on `AppLayout`. A fix applied
     * to the obvious layout alone would have left exactly that one silent.
     */
    public function test_both_layouts_render_validation_errors(): void
    {
        foreach (['AppLayout.vue', 'AuthLayout.vue'] as $layout) {
            $source = (string) file_get_contents(resource_path('js/Layouts/'.$layout));

            $this->assertStringContainsString(
                'props.errors',
                $source,
                "{$layout} must read the errors Inertia shares on every page.\n".
                'Without it a form with no field-level display refuses in silence — that is R20-4.',
            );

            $this->assertStringContainsString(
                'validationErrors',
                $source,
                "{$layout} must render the messages, not merely read them.",
            );
        }
    }

    /**
     * The two pages round 20 made dangerous, pinned by name.
     *
     * Both grew a uniqueness rule that can actually fire, so silence on them is
     * not a latent defect but a reachable one. Naming them separately keeps the
     * fix from being quietly undone by an edit that only looks at the baseline.
     */
    public function test_the_reference_and_material_forms_report_their_collisions(): void
    {
        $university = $this->page('Admin/University/Index.vue');

        foreach (['deptForm.errors.name', 'signForm.errors.full_name', 'groupForm.errors.name'] as $binding) {
            $this->assertStringContainsString($binding, $university, "the university page must render {$binding}");
        }

        $this->assertStringContainsString(
            'form.errors.counter_type',
            $this->page('Admin/Materials/Index.vue'),
            'a second active material of one counter type is refused — the form has to say which field',
        );
    }

    /**
     * Names of objects a page fills with forms — `holder[key] = useForm(`.
     *
     * One name per `useForm()` call, not one per holder: the fallback below
     * counts attributed calls against `substr_count($source, 'useForm(')`, and
     * a holder filled in two places is two calls. Duplicates are what keeps
     * that arithmetic honest.
     *
     * @return array<int, string>
     */
    private function collectedForms(string $source): array
    {
        preg_match_all('/(\w+)\s*\[[^\]]+\]\s*=\s*useForm\(/', $source, $matches);

        return $matches[1];
    }

    /** @return array<string, string> relative path => source */
    private function pagesWithAForm(): array
    {
        $root = resource_path('js/Pages');
        $pages = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'vue') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, 'useForm(')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $pages[$relative] = $source;
        }

        return $pages;
    }

    private function page(string $relative): string
    {
        $path = resource_path('js/Pages/'.$relative);

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
