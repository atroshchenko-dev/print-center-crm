<script setup>
import AuthLayout from '@/Layouts/AuthLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'

const props = defineProps({
    token: String,
    email: String,
})

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
})

function submit() {
    form.post(route('password.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <AuthLayout>
        <div class="text-center mb-6">
            <h2 class="text-lg font-semibold text-gray-800">Новий пароль</h2>
            <p class="text-sm text-gray-600 mt-1">Введіть новий пароль для вашого акаунту</p>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input aria-label="Email"
                    v-model="form.email"
                    type="email"
                    class="input bg-gray-50"
                    readonly
                />
                <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">
                    {{ form.errors.email }}
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Новий пароль</label>
                <input aria-label="Новий пароль"
                    v-model="form.password"
                    type="password"
                    class="input"
                    :class="{ 'border-red-500': form.errors.password }"
                    autocomplete="new-password"
                    autofocus
                />
                <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">
                    {{ form.errors.password }}
                </p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Підтвердіть пароль</label>
                <input aria-label="Підтвердіть пароль"
                    v-model="form.password_confirmation"
                    type="password"
                    class="input"
                    autocomplete="new-password"
                />
            </div>

            <button
                type="submit"
                class="btn-primary w-full justify-center py-2.5"
                :disabled="form.processing"
            >
                <span v-if="form.processing">Зберігаю…</span>
                <span v-else>Зберегти пароль</span>
            </button>

            <div class="text-center">
                <Link :href="route('login')" class="text-sm text-indigo-600 hover:text-indigo-800">
                    ← Повернутись до входу
                </Link>
            </div>
        </form>
    </AuthLayout>
</template>
