<script setup>
import AuthLayout from '@/Layouts/AuthLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'

const form = useForm({
    login: '',
    password: '',
    remember: false,
})

function submit() {
    form.post(route('login.submit'), {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <AuthLayout>
        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <label for="login" class="block text-sm font-medium text-gray-700 mb-1">
                    Логін (email або ім'я)
                </label>
                <input
                    id="login"
                    v-model="form.login"
                    type="text"
                    class="input"
                    :class="{ 'border-red-500': form.errors.login }"
                    autocomplete="username"
                    autofocus
                    :aria-invalid="form.errors.login ? 'true' : undefined"
                    :aria-describedby="form.errors.login ? 'login-error' : undefined"
                />
                <p v-if="form.errors.login" id="login-error" class="mt-1 text-xs text-red-600">
                    {{ form.errors.login }}
                </p>
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Пароль</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="input"
                    :class="{ 'border-red-500': form.errors.password }"
                    autocomplete="current-password"
                    :aria-invalid="form.errors.password ? 'true' : undefined"
                    :aria-describedby="form.errors.password ? 'password-error' : undefined"
                />
                <p v-if="form.errors.password" id="password-error" class="mt-1 text-xs text-red-600">
                    {{ form.errors.password }}
                </p>
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
                <input v-model="form.remember" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                Запам'ятати мене
            </label>

            <button
                type="submit"
                class="btn-primary w-full justify-center py-2.5 mt-2"
                :disabled="form.processing"
            >
                <span v-if="form.processing">Входжу…</span>
                <span v-else>Увійти</span>
            </button>

            <div class="text-center mt-3">
                <Link :href="route('password.request')" class="text-sm text-indigo-600 hover:text-indigo-800">
                    Забули пароль?
                </Link>
            </div>
        </form>
    </AuthLayout>
</template>
