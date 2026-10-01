/**
 * Shared order status labels and colors.
 * Single source of truth for all Vue components.
 */

export const statusLabels = {
    new:               'Нове',
    in_progress:       'В роботі',
    ready:             'Готове',
    paid_issued:       'Оплачено/Видано',
    completed_issued:  'Завершено/Видано',
    cancelled:         'Скасовано',
}

export const statusColors = {
    new:               'badge bg-gray-100 text-gray-600',
    in_progress:       'badge bg-blue-100 text-blue-700',
    ready:             'badge bg-yellow-100 text-yellow-700',
    paid_issued:       'badge bg-green-100 text-green-700',
    completed_issued:  'badge bg-green-100 text-green-700',
    cancelled:         'badge bg-red-100 text-red-600',
}

export function isTerminal(status) {
    return ['paid_issued', 'completed_issued', 'cancelled'].includes(status)
}
