/**
 * Порядок підписантів у випадайках.
 *
 * `Intl.Collator('uk')` знає, що Ґ іде після Г, а Є, І, Ї — між Е та Й.
 * Байтове порівняння UTF-8 винесло б їх у хвіст списку, і «Фініник Т.В.»
 * у довіднику вже є.
 */
const collator = new Intl.Collator('uk', { sensitivity: 'base', numeric: true });

export function sortSignatories(list) {
    return [...(list || [])].sort((a, b) =>
        collator.compare(a?.full_name ?? '', b?.full_name ?? '')
    );
}
