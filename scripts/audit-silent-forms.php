<?php

/**
 * How big each still-silent form is, and whether the client lets its rules through.
 *
 * Written for round 27. The debt list (`FormsReportValidationErrorsTest`) says
 * how many forms show no message under the field; it does not say how many are
 * worth fixing, and after round 24 those are different numbers — the banner now
 * names the field in Ukrainian, so on a one-field form it is already enough.
 *
 * Two measurements per form:
 *
 *  - **fields** — how many keys its `useForm({…})` starts with. A banner saying
 *    "something is wrong" costs the operator a search proportional to this;
 *  - **client-blocked** — whether the submit bound to it carries a `:disabled`
 *    beyond `processing`. If it does, some of its rules never reach the server
 *    at all, and fixing the display buys less than it looks (the round 21
 *    lesson, which changed the plan in rounds 24 and 25).
 *
 * Both are read out of the source, so both are approximations — a `:disabled`
 * elsewhere on the page can be attributed to the wrong form. The output is a
 * sorting aid for a human decision, not the decision.
 *
 * Run (PowerShell):
 *   docker run --rm -v "…:/app" -w /app crm-php:8.3 php scripts/audit-silent-forms.php
 */
$root = __DIR__.'/..';

// The debt, read from the test rather than copied, so the two cannot drift.
$test = (string) file_get_contents($root.'/tests/Feature/FormsReportValidationErrorsTest.php');
preg_match('/KNOWN_SILENT = \[(.*?)\];/s', $test, $block);
preg_match_all("/'([^']+)'/", $block[1] ?? '', $entries);

$rows = [];

foreach ($entries[1] ?? [] as $entry) {
    [$page, $variable] = explode(':', $entry, 2);
    $source = (string) @file_get_contents($root.'/resources/js/Pages/'.$page);

    if ($source === '') {
        $rows[] = [$entry, '?', '?'];

        continue;
    }

    $fields = '?';

    // `useForm({…})` is written both ways here — one line on the small forms,
    // many on the large — so the body is taken up to the closing `})` rather
    // than up to a newline. The first version required the newline and reported
    // `?` for two thirds of the list: a net measuring the formatting again.
    if ($variable !== '*' && preg_match('/(?:const|let|var)\s+'.preg_quote($variable, '/').'\s*=\s*useForm\(\s*\{(.*?)\}\s*\)/s', $source, $init)) {
        // Top-level keys: `key:` not nested inside another object literal.
        $body = preg_replace('/\{[^{}]*\}/', '', $init[1]);
        preg_match_all('/(?:^|,)\s*(\w+)\s*:/m', (string) $body, $keys);
        $fields = count($keys[1]);
    }

    // Any :disabled mentioning this form that is not merely `processing`.
    $blocked = 'ні';

    if (preg_match_all('/:disabled="([^"]*'.preg_quote($variable, '/').'[^"]*)"/', $source, $guards)) {
        foreach ($guards[1] as $guard) {
            if (trim(str_replace([$variable.'.processing', ' '], '', $guard)) !== '') {
                $blocked = 'ТАК';
            }
        }
    }

    $rows[] = [$entry, $fields, $blocked];
}

usort($rows, fn ($a, $b) => (is_int($b[1]) ? $b[1] : -1) <=> (is_int($a[1]) ? $a[1] : -1));

printf('%-46s %6s  %s%s', 'Форма', 'Полів', 'Клієнт блокує', PHP_EOL);
printf('%s%s', str_repeat('-', 78), PHP_EOL);

foreach ($rows as [$entry, $fields, $blocked]) {
    printf('%-46s %6s  %s%s', $entry, (string) $fields, $blocked, PHP_EOL);
}

printf('%sУсього форм у боргу: %d%s', PHP_EOL, count($rows), PHP_EOL);
