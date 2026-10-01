/**
 * Чи не є щойно введена назва центру витрат одруківкою в наявній.
 *
 * Довідник уже містить «Департамерт реклами» поряд із «Департамент реклами»
 * і «Департамент зі побутового обслуговування» поряд із «з побутового» —
 * наслідок вільного поля, яке створювало підрозділ із будь-чого набраного.
 * Підказка попереджає; заборонити ввід не можна, бо підрозділу може справді
 * ще не бути в довіднику.
 */
const normalize = (value) => (value || '').trim().toLowerCase().replace(/\s+/g, ' ');

function distance(a, b) {
    const rows = a.length + 1;
    const cols = b.length + 1;
    let previous = Array.from({ length: cols }, (_, i) => i);

    for (let i = 1; i < rows; i++) {
        const current = [i];
        for (let j = 1; j < cols; j++) {
            current[j] = Math.min(
                previous[j] + 1,
                current[j - 1] + 1,
                previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1),
            );
        }
        previous = current;
    }

    return previous[cols - 1];
}

/** Схожими вважаються назви, що різняться не більш ніж на чверть довжини. */
const THRESHOLD = 0.25;

export function findSimilarName(input, names) {
    const trimmedInput = (input || '').trim();
    const needle = normalize(input);

    // Коротке слово надто легко «схоже» на будь-що: на трьох літерах одна
    // правка — це вже третина рядка.
    if (needle.length < 4) {
        return null;
    }

    let best = null;
    let bestScore = Infinity;

    for (const name of names || []) {
        // Точний збіг рахуємо по сирому рядку, а не по нормалізованому:
        // регістр чи зайвий пробіл — це і є та одруківка, яку підказка
        // повинна зловити, а не наявна позиція, яку можна пропустити.
        if (trimmedInput === name) {
            return null;
        }

        const candidate = normalize(name);
        const score = distance(needle, candidate) / Math.max(needle.length, candidate.length);

        if (score < bestScore) {
            bestScore = score;
            best = name;
        }
    }

    return bestScore <= THRESHOLD ? best : null;
}
