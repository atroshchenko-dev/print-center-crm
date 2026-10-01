<script setup>
/**
 * UiConfirmDialog — "Are you sure?" dialog before destructive actions.
 * Wraps UiModal with confirm/cancel buttons.
 */
import Modal from './Modal.vue'
import Button from './Button.vue'

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: 'Підтвердження' },
    message: { type: String, default: 'Ви впевнені?' },
    confirmLabel: { type: String, default: 'Підтвердити' },
    cancelLabel: { type: String, default: 'Скасувати' },
    variant: { type: String, default: 'danger' },
    loading: { type: Boolean, default: false },
})

const emit = defineEmits(['confirm', 'cancel'])
</script>

<template>
    <Modal :show="show" :title="title" max-width="sm" @close="emit('cancel')">
        <p class="text-sm text-gray-600">{{ message }}</p>

        <template #footer>
            <Button variant="ghost" @click="emit('cancel')">
                {{ cancelLabel }}
            </Button>
            <Button :variant="variant" :loading="loading" @click="emit('confirm')">
                {{ confirmLabel }}
            </Button>
        </template>
    </Modal>
</template>
