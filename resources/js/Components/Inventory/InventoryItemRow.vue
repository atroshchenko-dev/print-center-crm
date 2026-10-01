<script setup>
import { computed } from 'vue'
import { fmtQty } from '@/utils/fmtQty'

/**
 * Рядок товару на складі.
 *
 * Той самий рядок малювався двічі — у таблиці паперу і в пласких таблицях
 * решти категорій — а групи розмірів твердої палітурки зробили б його втретє.
 * Тут він один: перетягування лишається в батька (він знає порядок усередині
 * категорії), рядок лише повідомляє про події.
 */
const props = defineProps({
    item: { type: Object, required: true },
    dragState: { type: Object, required: true },
    /** Рядок усередині групи розмірів — трохи глибший відступ у назві. */
    nested: { type: Boolean, default: false },
})

defineEmits(['dragstart', 'dragover', 'drop', 'dragend', 'receipt', 'edit'])

const belowMin = computed(
    () => Number(props.item.current_quantity) <= Number(props.item.min_quantity),
)
</script>

<template>
    <tr draggable="true"
        @dragstart="$emit('dragstart', $event)"
        @dragover="$emit('dragover', $event)"
        @drop="$emit('drop', $event)"
        @dragend="$emit('dragend', $event)"
        class="hover:bg-gray-50 transition-colors cursor-grab active:cursor-grabbing"
        :class="{
            'bg-red-50': belowMin,
            'opacity-40': dragState.draggedId === item.id,
            'border-t-2 border-indigo-400': dragState.overId === item.id && dragState.draggedId !== item.id,
        }">
        <td class="px-2 py-4 text-center text-gray-600 select-none">
            <span class="text-lg text-gray-600 cursor-grab">⠿</span>
        </td>
        <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 border-r border-gray-100"
            :class="nested ? 'pl-12' : ''">
            {{ item.name }}
        </td>
        <td class="px-6 py-4 whitespace-nowrap text-right border-r border-gray-100">
            <span class="text-lg font-bold" :class="belowMin ? 'text-red-700' : 'text-green-700'">
                {{ fmtQty(item.current_quantity) }}
            </span>
            <span class="text-gray-600 ml-1">{{ item.unit }}.</span>
        </td>
        <td class="px-6 py-4 whitespace-nowrap text-right font-mono text-gray-700 bg-gray-50 border-r border-gray-100">
            {{ parseFloat(item.avg_cost).toFixed(2) }} ₴
        </td>
        <td class="px-6 py-4 whitespace-nowrap text-center text-gray-600">
            {{ fmtQty(item.min_quantity) }}
        </td>
        <td class="px-6 py-4 whitespace-nowrap text-right space-x-3">
            <button @click="$emit('receipt')" class="text-green-700 hover:text-green-900 font-medium">
                Оприбуткувати
            </button>
            <button @click="$emit('edit')" class="text-indigo-600 hover:text-indigo-900 ml-3">
                Редаг.
            </button>
        </td>
    </tr>
</template>
