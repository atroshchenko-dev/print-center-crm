<script setup>
import AuthLayout from '@/Layouts/AuthLayout.vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const flash = computed(() => usePage().props.flash ?? {})

const form = useForm({
    email: '',
})

function submit() {
    form.post(route('password.email'))
}
</script>

<template>
    <AuthLayout>
        <div class="text-center mb-6">
            <h2 class="text-lg font-semibold text-gray-800">Відновлення пароля</h2>
            <p class="text-sm text-gray-600 mt-1">Вкажіть email, і ми надішлемо посилання</p>
        </div>

        <div v-if="flash.success" class="mb-4 px-4 py-3 rounded-lg bg-green-50 text-green-700 border border-green-200 text-sm">
            {{ flash.success }}
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input aria-label="Email"
                    v-model="form.email"
                    type="email"
                    class="input"
                    :class="{ 'border-red-500': form.errors.email }"
                    placeholder="your@email.com"
                    autofocus
                />
                <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">
                    {{ form.errors.email }}
                </p>
            </div>

            <button
                type="submit"
                class="btn-primary w-full justify-center py-2.5"
                :disabled="form.processing"
            >
                <span v-if="form.processing">Надсилаю…</span>
                <span v-else>Надіслати посилання</span>
            </button>

            <div class="text-center">
                <Link :href="route('login')" class="text-sm text-indigo-600 hover:text-indigo-800">
                    ← Повернутись до входу
                </Link>
            </div>
        </form>
    </AuthLayout>
</template>
