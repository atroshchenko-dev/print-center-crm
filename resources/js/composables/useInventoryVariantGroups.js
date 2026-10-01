/**
 * Складські позиції, згорнуті в групи за назвою без кольору.
 *
 * Комплектна тверда палітурка — це 8 розмірів × 4 кольори = 32 рядки, з яких
 * реально зайняті чотири: решта стоїть на нулі й світиться червоним, бо нуль
 * нижчий за мінімум. Канали з обкладинками — ще 20 рядків тим самим чином.
 * Питання, заради якого цю сторінку відкривають, звучить «скільки в нас
 * 14 мм», а не «скільки 14 мм сірих».
 *
 * Ключ групи — назва без хвоста в дужках: «Канал 5мм (Синій)» і «Канал 5мм
 * (Червоний)» сходяться в «Канал 5мм», а «Обкладинка тверда (Синій)» — в
 * «Обкладинка тверда», хоч розміру в ній немає взагалі. Перше правило, яке тут
 * стояло, шукало міліметри в назві й на обкладинках не працювало.
 *
 * Групування суто екранне. Жодна позиція не зникає й не зливається з іншою:
 * кожен колір лишається окремим товаром зі своїм AVCO, своїм оприбуткуванням
 * і своїм звʼязком з опцією конструктора — злити їх в один товар означало б
 * втратити і те, і те, і те. Група лише підсумовує те, що лежить під нею.
 */

/** Хвіст-варіант у кінці назви: « (Синій)», « (230 г/м²)». */
const VARIANT_SUFFIX = /\s*\([^()]*\)\s*$/u;

/** Розмір, написаний упритул: «5мм» → «5 мм». */
const TIGHT_SIZE = /(\d)\s*мм/gu;

/**
 * Назва без хвоста-варіанта — вона ж ключ групи.
 *
 * Назва, яка з самих дужок і складається, лишається собою: інакше ключем стала
 * б порожня стрічка, і такі позиції злиплися б у групу без назви.
 *
 * @param {string} name
 * @returns {string}
 */
export function baseName(name) {
    const full = String(name ?? '').trim();
    const stripped = full.replace(VARIANT_SUFFIX, '').trim();

    return stripped === '' ? full : stripped;
}

/**
 * Групи в порядку першої появи — тобто в тому, який задав власник
 * перетягуванням рядків, а не в алфавітному.
 *
 * @param {Array<object>} items позиції однієї категорії, вже впорядковані
 * @returns {Array<{
 *   key: string,
 *   label: string,
 *   items: Array<object>,
 *   totalQuantity: number,
 *   belowMin: number,
 *   avgCost: number|null,
 *   unit: string,
 *   collapsible: boolean,
 * }>}
 */
export function variantGroups(items) {
    const groups = new Map();

    for (const item of items ?? []) {
        const key = baseName(item.name);
        if (! groups.has(key)) {
            groups.set(key, []);
        }
        groups.get(key).push(item);
    }

    const entries = [...groups.entries()];
    const prefix = commonWordPrefix(entries.map(([key]) => key));

    return entries.map(([key, groupItems]) => ({
        key,
        label: label(key, prefix),
        items: groupItems,
        totalQuantity: totalQuantity(groupItems),
        belowMin: groupItems.filter(isBelowMin).length,
        avgCost: weightedAvgCost(groupItems),
        unit: groupItems[0]?.unit ?? 'шт',
        // Групувати один рядок нема сенсу: він лишається звичайним рядком,
        // інакше до єдиної позиції довелось би клікати.
        collapsible: groupItems.length > 1,
    }));
}

/**
 * Та сама умова, за якою рядок у таблиці підсвічується червоним.
 *
 * @param {object} item
 */
export function isBelowMin(item) {
    return Number(item.current_quantity) <= Number(item.min_quantity);
}

/**
 * Скільки перших слів повторює КОЖНА група.
 *
 * У комплектній палітурці всі вісім груп починаються з «Палітурка комплектна»,
 * і вісім разів прочитати це підряд — марна робота для ока: у підпису лишається
 * «3.5 мм». Там, де спільного початку немає — канал і обкладинка, — не
 * відкидається нічого.
 *
 * Останнє слово недоторкане завжди: група без підпису гірша за багатослівну.
 */
function commonWordPrefix(keys) {
    if (keys.length < 2) {
        return 0;
    }

    const words = keys.map((key) => key.split(' '));
    const shortest = Math.min(...words.map((w) => w.length));
    let common = 0;

    while (common < shortest - 1 && words.every((w) => w[common] === words[0][common])) {
        common++;
    }

    return common;
}

function label(key, prefix) {
    return key.split(' ').slice(prefix).join(' ').replace(TIGHT_SIZE, '$1 мм');
}

function totalQuantity(items) {
    return items.reduce((sum, item) => sum + Number(item.current_quantity ?? 0), 0);
}

/**
 * AVCO того, що справді лежить на полиці: середнє, зважене залишками.
 *
 * Порожні позиції в нього не входять — їхня собівартість це ціна минулої
 * закупівлі, а не вартість запасу. Коли не лежить нічого, середнього немає:
 * null, а не нуль, щоб на екрані стояв прочерк, а не «0.00 ₴».
 */
function weightedAvgCost(items) {
    const inStock = items.filter((item) => Number(item.current_quantity) > 0);

    if (inStock.length === 0) {
        return null;
    }

    const quantity = totalQuantity(inStock);
    const value = inStock.reduce(
        (sum, item) => sum + Number(item.current_quantity) * Number(item.avg_cost ?? 0),
        0,
    );

    return quantity > 0 ? value / quantity : null;
}
