<script setup>
/**
 * RequestBadge — позначка «Заявка» для внутрішнього замовлення.
 *
 * Колір відповідає на «чи є підтвердження», іконка — на «де його шукати»:
 * конверт означає, що підпис живе тільки в системі, аркуш — що заявка
 * лежить у теці. Зняти позначку, яку поставив лист, не можна; сервер
 * відмовляє так само (OrderController::toggleRequestReceived), тож
 * disabled тут — зручність, а не захист.
 *
 * Один компонент на обидві копії списку: основну й «Незавершені з
 * попередніх днів». Доти бейдж був описаний двічі, і правка мала шанс
 * поїхати лише в одну з них.
 */
import { computed } from 'vue'
import Icon from '@/Components/Icon.vue'

const props = defineProps({
    order: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['toggle'])

const byLetter = computed(() => Boolean(props.order.request_received && props.order.has_email_approval))

const iconName = computed(() => {
    if (!props.order.request_received) return null

    return byLetter.value ? 'mail' : 'file-text'
})

const title = computed(() => {
    if (byLetter.value) return 'Погоджено листом — позначку зняти не можна'
    if (props.order.request_received) return 'Заявку отримано — зняти?'

    return 'Позначити заявку отриманою'
})

function onClick() {
    if (byLetter.value) return

    emit('toggle')
}
</script>

<template>
    <button
        type="button"
        class="ml-2 px-2 py-1 rounded-md text-xs font-medium transition-all duration-150 border inline-flex items-center gap-1"
        :class="order.request_received
            ? (byLetter
                ? 'bg-green-50 border-green-200 text-green-700 cursor-default'
                : 'bg-green-50 border-green-200 text-green-700 hover:bg-red-50 hover:border-red-200 hover:text-red-600')
            : 'bg-gray-50 border-gray-200 text-gray-600 hover:bg-green-50 hover:border-green-200 hover:text-green-700'"
        :disabled="disabled || byLetter"
        :title="title"
        @click="onClick"
    >
        <Icon v-if="iconName" :name="iconName" size="3" />
        {{ order.request_received ? 'Заявка ✓' : 'Заявка' }}
    </button>
</template>
