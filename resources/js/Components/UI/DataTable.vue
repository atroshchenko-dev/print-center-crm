<script setup>
/**
 * UiDataTable — Sortable data table with configurable columns.
 * Designed for admin panels (equipment, materials, inventory, etc.)
 * Features: loading skeleton, sticky header, aria-sort, hover highlight.
 */
import { ref, computed } from 'vue'

const props = defineProps({
    columns: {
        type: Array,
        required: true,
        // Each column: { key: string, label: string, sortable?: boolean, align?: 'left'|'center'|'right' }
    },
    rows: { type: Array, default: () => [] },
    emptyMessage: { type: String, default: 'Немає даних для відображення' },
    loading: { type: Boolean, default: false },
    skeletonRows: { type: Number, default: 5 },
    stickyHeader: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
})

const emit = defineEmits(['row-click'])

const sortKey = ref(null)
const sortAsc = ref(true)

function toggleSort(col) {
    if (!col.sortable) return
    if (sortKey.value === col.key) {
        sortAsc.value = !sortAsc.value
    } else {
        sortKey.value = col.key
        sortAsc.value = true
    }
}

const sortedRows = computed(() => {
    if (!sortKey.value) return props.rows

    return [...props.rows].sort((a, b) => {
        const va = a[sortKey.value] ?? ''
        const vb = b[sortKey.value] ?? ''
        const cmp = typeof va === 'number' ? va - vb : String(va).localeCompare(String(vb), 'uk')
        return sortAsc.value ? cmp : -cmp
    })
})

const alignClass = (col) => ({
    'text-left': !col.align || col.align === 'left',
    'text-center': col.align === 'center',
    'text-right': col.align === 'right',
})

function ariaSort(col) {
    if (!col.sortable || sortKey.value !== col.key) return undefined
    return sortAsc.value ? 'ascending' : 'descending'
}

const cellPadding = computed(() => props.compact ? 'px-3 py-2' : 'px-4 py-3')
</script>

<template>
    <div class="overflow-x-auto border border-gray-200"
         style="border-radius: var(--radius-lg);">
        <table class="w-full text-sm" role="table">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-200"
                    :class="{ 'sticky top-0 z-10': stickyHeader }">
                    <th scope="col"
                        v-for="col in columns"
                        :key="col.key"
                        :class="[
                            cellPadding,
                            'font-medium text-gray-600 whitespace-nowrap',
                            alignClass(col),
                            col.sortable ? 'cursor-pointer select-none hover:text-gray-700 transition-colors' : '',
                        ]"
                        :aria-sort="ariaSort(col)"
                        @click="toggleSort(col)"
                    >
                        <span class="inline-flex items-center gap-1">
                            {{ col.label }}
                            <template v-if="col.sortable && sortKey === col.key">
                                <svg class="w-3.5 h-3.5 text-indigo-500" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2.5">
                                    <path v-if="sortAsc" d="M12 5v14M5 12l7-7 7 7" />
                                    <path v-else d="M12 19V5M5 12l7 7 7-7" />
                                </svg>
                            </template>
                            <template v-else-if="col.sortable">
                                <svg class="w-3.5 h-3.5 text-gray-600" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2">
                                    <path d="M8 9l4-4 4 4M8 15l4 4 4-4" />
                                </svg>
                            </template>
                        </span>
                    </th>
                    <!-- Actions column -->
                    <th scope="col" v-if="$slots.actions" :class="[cellPadding, 'text-right font-medium text-gray-600']">Дії</th>
                </tr>
            </thead>
            <tbody>
                <!-- Loading skeleton -->
                <template v-if="loading">
                    <tr v-for="i in skeletonRows" :key="'skel-' + i"
                        class="border-b border-gray-100">
                        <td v-for="col in columns" :key="col.key" :class="cellPadding">
                            <div class="h-4 bg-gray-200 rounded animate-shimmer"
                                 :style="{ width: (40 + Math.random() * 40) + '%' }" />
                        </td>
                        <td v-if="$slots.actions" :class="cellPadding">
                            <div class="h-4 w-16 bg-gray-200 rounded animate-shimmer ml-auto" />
                        </td>
                    </tr>
                </template>

                <!-- Data rows -->
                <template v-else>
                    <tr
                        v-for="(row, idx) in sortedRows"
                        :key="row.id ?? idx"
                        class="border-b border-gray-100 hover:bg-indigo-50/30 transition-colors group"
                        @click="emit('row-click', row)"
                    >
                        <td
                            v-for="col in columns"
                            :key="col.key"
                            :class="[cellPadding, 'text-gray-700', alignClass(col)]"
                        >
                            <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                                {{ row[col.key] ?? '—' }}
                            </slot>
                        </td>
                        <td v-if="$slots.actions" :class="[cellPadding, 'text-right']">
                            <slot name="actions" :row="row" />
                        </td>
                    </tr>

                    <!-- Empty state -->
                    <tr v-if="sortedRows.length === 0">
                        <td :colspan="columns.length + ($slots.actions ? 1 : 0)"
                            class="px-4 py-12 text-center text-gray-600">
                            <svg class="w-8 h-8 mx-auto mb-2 text-gray-600" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.5">
                                <path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            {{ emptyMessage }}
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
