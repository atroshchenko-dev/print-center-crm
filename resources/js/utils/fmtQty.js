/**
 * Кількість на екрані: цілі — без хвоста, дробові — не довше двох знаків.
 *
 * Ця функція лежала двома однаковими копіями — на сторінці складу і в таблиці
 * закупівель — і третя мала зʼявитись у рядку товару, винесеному з тієї ж
 * сторінки. Копії поки що збігались; далі вони збігаються рівно доти, доки
 * хтось не правитиме одну з них.
 *
 * @param {number|string} val
 * @returns {string}
 */
export function fmtQty(val) {
    const n = Number(val);

    return Number.isInteger(n)
        ? n.toLocaleString('uk-UA')
        : n.toLocaleString('uk-UA', { maximumFractionDigits: 2 });
}
