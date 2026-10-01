<?php

/**
 * Which validated fields have no Ukrainian name, and therefore appear to the
 * operator as a database column.
 *
 * Written for round 24. The owner's decision of 2026-08-02 (CLOSEOUT §1.9) made
 * `AppLayout` render every refusal in a banner, so no rule is invisible any
 * more — but the banner shows Laravel's message and nothing else, and Laravel
 * fills `:attribute` from `lang/uk/validation.php`. A field missing from that
 * list falls back to its own key with the underscores turned into spaces, so a
 * Ukrainian sentence ends in an English column name.
 *
 * Reads `rules()` out of every FormRequest by regex rather than by booting the
 * container: a FormRequest wants a request to resolve, and several of these
 * build rules from the route. Keys are what is wanted here, not values.
 *
 * Run (PowerShell):
 *   docker run --rm -v "…:/app" -w /app crm-php:8.3 php scripts/audit-attributes.php
 */
$root = __DIR__.'/..';
$fields = [];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Http/Requests'));

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

$fields = array_keys($fields);
sort($fields);

$lang = require $root.'/lang/uk/validation.php';
$named = array_keys($lang['attributes'] ?? []);

$missing = array_values(array_diff($fields, $named));
$unused = array_values(array_diff($named, $fields));

echo 'Полів у rules(): '.count($fields).PHP_EOL;
echo 'З українською назвою: '.count(array_intersect($fields, $named)).PHP_EOL;
echo 'Без назви: '.count($missing).PHP_EOL;
echo 'Назв, що не відповідають жодному полю: '.count($unused).' ('.implode(', ', $unused).')'.PHP_EOL;
echo PHP_EOL.'--- без назви ---'.PHP_EOL;
echo implode(PHP_EOL, $missing).PHP_EOL;
