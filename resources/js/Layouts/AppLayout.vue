<script setup>
import { computed, ref, watch, onErrorCaptured, onMounted, onBeforeUnmount } from 'vue'
import { Link, usePage, router } from '@inertiajs/vue3'

const page = usePage()
const user = computed(() => page.props.auth?.user)
const flash = computed(() => page.props.flash ?? {})

const shiftStatus = computed(() => page.props.shift?.status ?? null)

/** Check if current user has permission for a module (admin always has access) */
function can(module) {
    if (user.value?.role === 'admin') return true
    return user.value?.permissions?.includes(module) ?? false
}

/** Admin-only screens ask this, the same question their routes ask. */
const isAdmin = computed(() => user.value?.role === 'admin')

/**
 * Whether to show the admin panel at all (has at least one admin-level permission).
 *
 * `users` is deliberately not here and is not a module any more: `/admin/users`
 * is `role:admin`, so holding it opened nothing while lighting up this whole
 * zone and a link that answered 403. An admin still gets the zone
 * — `can()` above returns true for every module they are asked about.
 */
const hasAnyAdminPermission = computed(() => {
    const adminModules = ['reports', 'services', 'equipment', 'inventory', 'university']
    return adminModules.some(m => can(m))
})

function logout() {
    router.post(route('logout'))
}

// ─── Mobile sidebar toggle ───────────────────────────────
const sidebarOpen = ref(false)

// Auto-close mobile sidebar on Inertia navigation
router.on('navigate', () => {
    sidebarOpen.value = false
})

// ─── Error boundary ──────────────────────────────────
const renderError = ref(null)
onErrorCaptured((err) => {
    console.error('[CRM Error Boundary]', err)
    renderError.value = err.message || 'Невідома помилка'
    return false // prevent propagation
})

// ─── Collapsible sidebar sections (independent, no accordion) ──
const stored = JSON.parse(localStorage.getItem('sidebar_collapsed') || '{}')
const collapsed = ref({
    pricing:   stored.pricing ?? true,
    resources: stored.resources ?? true,
    reports:   stored.reports ?? true,
    control:   stored.control ?? true,
})
watch(collapsed, v => localStorage.setItem('sidebar_collapsed', JSON.stringify(v)), { deep: true })

function toggle(section) {
    collapsed.value[section] = !collapsed.value[section]
}

// ─── Flash auto-dismiss ──────────────────────────────────
const flashVisible = ref(true)
let flashTimer = null

watch(flash, (val) => {
    if (val.success || val.error || val.warning) {
        flashVisible.value = true
        clearTimeout(flashTimer)
        flashTimer = setTimeout(() => { flashVisible.value = false }, val.warning || val.error ? 12000 : 6000)
    }
}, { immediate: true })

function dismissFlash() {
    flashVisible.value = false
    clearTimeout(flashTimer)
}

// ─── Validation errors ───────────────────────────────────
//
// Inertia shares `errors` on **every** page (`parent::share()` in
// HandleInertiaRequests), and until now nothing in the application read it.
// The server answered 422 with the right Ukrainian sentence, the page rendered
// none of it, and the operator saw a click that did not register — that is
// R20-4, found on production, and the sweep after it counted **thirty** forms
// in the same state.
//
// This is the owner's decision of 2026-08-02 (CLOSEOUT §1.9), the first half:
// one place where a refusal is always visible. The second half is the message
// under the field itself, and it is worth more — but it has to be written form
// by form, and a form nobody has got to yet must not be silent in the meantime.
//
// Pages that do render their own field errors show the message twice: once here
// as a summary, once under the field. That is the accepted price. Silence is
// worse than repetition, and «нічого не відбувається» is worse than a 500,
// because a 500 is at least visible.
const validationErrors = computed(() =>
    Object.values(page.props.errors ?? {}).filter(m => typeof m === 'string' && m !== ''),
)

// Dismissed by hand, and un-dismissed the moment a new refusal arrives —
// otherwise the second attempt at the same form comes back silent again.
const errorsDismissed = ref(false)
watch(validationErrors, () => { errorsDismissed.value = false })

const showValidationErrors = computed(
    () => ! errorsDismissed.value && validationErrors.value.length > 0,
)

// ─── Role icons (SVG paths) ──────────────────────────────
const roleIcons = {
    admin:    'M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z',
    executor: 'M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6M6 14h12v8H6z',
    manager:  'M3 3v18h18M18 17V9M13 17V5M8 17v-3',
}
</script>

<template>
    <div class="min-h-screen flex bg-gray-50 overflow-x-hidden">
        <!-- Mobile top bar (visible below xl) -->
        <div class="fixed top-0 left-0 right-0 z-30 xl:hidden">
            <div class="h-14 bg-white border-b border-gray-200 flex items-center gap-2 px-4">
                <button @click="sidebarOpen = true" class="p-2 text-gray-600 hover:text-indigo-600 transition" aria-label="Відкрити меню">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <span class="text-lg font-bold text-indigo-700 tracking-tight flex items-center gap-1.5">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6M6 14h12v8H6z"/></svg>
                    CRM Print
                </span>
                <span class="text-xs text-gray-600 font-mono">PRINT</span>
            </div>
            <div class="h-0.5 bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500"></div>
        </div>

        <!-- Mobile sidebar overlay -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="sidebarOpen" class="fixed inset-0 bg-black/50 z-40" @click="sidebarOpen = false"></div>
            </Transition>
        </Teleport>

        <!-- Sidebar: on xl+ it's static flex, below xl it's fixed overlay controlled by sidebarOpen -->
        <aside :class="[
            'sidebar',
            sidebarOpen
                ? 'fixed inset-y-0 left-0 z-50 flex shadow-xl'
                : 'hidden xl:flex'
        ]">
            <!-- Logo -->
            <div class="h-16 px-6 flex items-center border-b border-gray-100 justify-between">
                <div class="flex items-center">
                    <span class="text-lg font-bold text-indigo-700 tracking-tight flex items-center gap-1.5">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6M6 14h12v8H6z"/></svg>
                        CRM Print
                    </span>
                    <span class="ml-2 text-xs text-gray-600 font-mono">PRINT</span>
                </div>
                <button v-if="sidebarOpen" @click="sidebarOpen = false" class="p-1 text-gray-600 hover:text-gray-600 transition" aria-label="Закрити меню">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- Nav -->
            <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
                <Link :href="route('dashboard')"
                    class="sidebar-link"
                    :class="{ active: route().current('dashboard') }">
                    <svg class="w-5 h-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg> <span class="sidebar-text">Дашборд</span>
                </Link>

                <Link v-if="can('orders')" :href="route('orders.index')"
                    class="sidebar-link"
                    :class="{ active: route().current('orders.*') }">
                    <svg class="w-5 h-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 7h8M8 12h8M8 17h5"/></svg> <span class="sidebar-text">Замовлення</span>
                </Link>



                <Link v-if="can('ledger')" :href="route('ledger.history')"
                    class="sidebar-link"
                    :class="{ active: route().current('ledger.*') }">
                    <svg class="w-5 h-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg> <span class="sidebar-text">Каса</span>
                </Link>

                <div v-if="hasAnyAdminPermission" class="pt-3 space-y-1">
                    <div class="sidebar-zone-label">Адміністрування</div>

                    <!-- ─── Прайс-лист ────────────────────────── -->
                    <template v-if="can('services')">
                        <div class="sidebar-group" @click="toggle('pricing')">
                            <p class="sidebar-group-label">
                                <span class="collapse-chevron" :class="{ open: !collapsed.pricing }">▸</span>
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 12h16M4 17h10"/><path d="M20 17v-2a2 2 0 0 0-2-2h-1"/></svg> <span class="sidebar-text">Ціноутворення</span>
                            </p>
                            <p class="sidebar-group-hint sidebar-text">Послуги, ціни, вартість кліків</p>
                        </div>

                        <template v-if="!collapsed.pricing">
                            <Link :href="route('admin.services.index')" class="sidebar-link"
                                :class="{ active: route().current('admin.services.*') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8z"/><path d="M15 3v4a1 1 0 0 0 1 1h4"/></svg> <span class="sidebar-text">Послуги та Конструктор</span>
                            </Link>
                            <Link :href="route('admin.materials.index')" class="sidebar-link"
                                :class="{ active: route().current('admin.materials.*') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 12.4a1 1 0 0 0 1.1 1.1L21 15"/><circle cx="12" cy="3" r="1"/></svg> <span class="sidebar-text">Вартість кліку</span>
                            </Link>
                            <Link :href="route('admin.riso-pricing.index')" class="sidebar-link"
                                :class="{ active: route().current('admin.riso-pricing.*') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v3M21 16v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3M4 12h16"/></svg> <span class="sidebar-text">Тарифи ризографа</span>
                            </Link>
                        </template>
                    </template>

                    <!-- ─── Ресурси (merged: Виробництво + Організація) ── -->
                    <template v-if="can('equipment') || can('inventory') || can('university') || isAdmin">
                        <div class="sidebar-group mt-3" @click="toggle('resources')">
                            <p class="sidebar-group-label">
                                <span class="collapse-chevron" :class="{ open: !collapsed.resources }">▸</span>
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18zM6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2M10 6h4M10 10h4M10 14h4M10 18h4"/></svg> <span class="sidebar-text">Ресурси</span>
                            </p>
                            <p class="sidebar-group-hint sidebar-text">Апарати, склад, підписанти, доступ</p>
                        </div>

                        <template v-if="!collapsed.resources">
                            <Link v-if="can('equipment')" :href="route('admin.equipment.index')" class="sidebar-link"
                                :class="{ active: route().current('admin.equipment.*') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg> <span class="sidebar-text">Апарати</span>
                            </Link>
                            <Link v-if="can('inventory')" :href="route('admin.inventory.index')" class="sidebar-link"
                                :class="{ active: route().current('admin.inventory.*') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg> <span class="sidebar-text">Склад</span>
                            </Link>
                            <Link v-if="can('university')" :href="route('admin.university.index')" class="sidebar-link"
                                :class="{ active: route().current('admin.university.*') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5zM6 12v5c0 1.1 2.7 2 6 2s6-.9 6-2v-5"/></svg> <span class="sidebar-text">Університет</span>
                            </Link>
                            <Link v-if="isAdmin" :href="route('admin.users.index')" class="sidebar-link"
                                :class="{ active: route().current('admin.users.*') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg> <span class="sidebar-text">Користувачі</span>
                            </Link>
                        </template>
                    </template>

                    <!-- ─── ZONE: Звітність ─────────────────── -->
                    <template v-if="can('reports')">
                        <div class="sidebar-zone-label mt-2">Звітність</div>

                        <!-- ─── Звіти (4 document reports) ────── -->
                        <div class="sidebar-group" @click="toggle('reports')">
                            <p class="sidebar-group-label">
                                <span class="collapse-chevron" :class="{ open: !collapsed.reports }">▸</span>
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/></svg> <span class="sidebar-text">Звіти</span>
                            </p>
                            <p class="sidebar-group-hint sidebar-text">Акти, рух коштів, лічильники</p>
                        </div>

                        <template v-if="!collapsed.reports">
                            <Link :href="route('reports.internal')" class="sidebar-link"
                                :class="{ active: route().current('reports.internal') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M14 2v4a1 1 0 0 0 1 1h4M10 12h4M10 16h4M10 8h1"/></svg> <span class="sidebar-text">Внутрішній акт</span>
                            </Link>
                            <Link :href="route('reports.commercial')" class="sidebar-link"
                                :class="{ active: route().current('reports.commercial') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg> <span class="sidebar-text">Комерційний</span>
                            </Link>
                            <Link :href="route('reports.cash-flow')" class="sidebar-link"
                                :class="{ active: route().current('reports.cash-flow') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> <span class="sidebar-text">Рух коштів</span>
                            </Link>
                            <Link :href="route('reports.counters')" class="sidebar-link"
                                :class="{ active: route().current('reports.counters') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> <span class="sidebar-text">Лічильники</span>
                            </Link>
                        </template>

                        <!-- ─── Контроль (audit, reconciliation, analytics, retro) ── -->
                        <div class="sidebar-group mt-3" @click="toggle('control')">
                            <p class="sidebar-group-label">
                                <span class="collapse-chevron" :class="{ open: !collapsed.control }">▸</span>
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> <span class="sidebar-text">Контроль</span>
                            </p>
                            <p class="sidebar-group-hint sidebar-text">Звірки, аудит, аналітика</p>
                        </div>

                        <template v-if="!collapsed.control">
                            <Link :href="route('admin.reconciliation.index')" class="sidebar-link"
                                :class="{ active: route().current('admin.reconciliation.*') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> <span class="sidebar-text">Звірка внутрішніх</span>
                            </Link>
                            <Link :href="route('reports.audit')" class="sidebar-link"
                                :class="{ active: route().current('reports.audit') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg> <span class="sidebar-text">Аудит</span>
                            </Link>
                            <Link :href="route('analytics')" class="sidebar-link"
                                :class="{ active: route().current('analytics') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="7.5 4.21 12 6.81 16.5 4.21"/><polyline points="7.5 19.79 7.5 14.6 3 12"/><polyline points="21 12 16.5 14.6 16.5 19.79"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg> <span class="sidebar-text">Аналітика</span>
                            </Link>
                            <Link v-if="user?.role === 'admin'" :href="route('admin.backdated-orders.index')" class="sidebar-link"
                                :class="{ active: route().current('admin.backdated-orders.*') }">
                                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg> <span class="sidebar-text">Ретро-замовлення</span>
                            </Link>
                        </template>
                    </template>
                </div>

                <!-- ─── Footer links (always visible) ──────── -->
                <div class="mt-auto pt-2 px-3 pb-2 space-y-0.5">
                    <Link :href="route('price-list')" class="sidebar-link"
                        :class="{ active: route().current('price-list') }">
                        <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15h6"/></svg> <span class="sidebar-text">Комерційний прайс</span>
                    </Link>
                    <template v-if="user?.role === 'admin'">
                        <Link :href="route('admin.settings.index')" class="sidebar-link"
                            :class="{ active: route().current('admin.settings.*') }">
                            <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg> <span class="sidebar-text">Налаштування</span>
                        </Link>
                    </template>
                </div>
            </nav>

            <!-- User + Shift -->
            <div class="border-t border-gray-100 px-4 py-3 space-y-2">
                <!-- Shift indicator -->
                <div v-if="shiftStatus === 'open'" class="flex items-center gap-2 text-xs text-green-700 bg-green-50 px-3 py-1.5 rounded-lg">
                    <span class="w-2 h-2 rounded-full bg-green-500 inline-block"></span>
                    Зміна відкрита
                </div>

                <Link :href="route('shifts.close.form')"
                    class="text-xs text-gray-600 hover:text-red-600 transition block"
                    v-if="shiftStatus === 'open'">
                    Закрити зміну →
                </Link>

                <!-- User info & logout -->
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-800">{{ user?.name }}</p>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span class="text-xs px-1.5 py-0.5 rounded-full font-medium inline-flex items-center gap-1"
                                :class="{
                                    'bg-purple-100 text-purple-700': user?.role === 'admin',
                                    'bg-blue-100 text-blue-700': user?.role === 'executor',
                                    'bg-amber-100 text-amber-700': user?.role === 'manager',
                                }">
                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path :d="roleIcons[user?.role] || roleIcons.executor" />
                                </svg>
                                {{ user?.role === 'admin' ? 'Адмін' : user?.role === 'executor' ? 'Виконавець' : 'Менеджер' }}
                            </span>
                            <span class="text-[10px] text-gray-600">
                                {{ isAdmin ? '∞' : (user?.permissions?.length ?? 0) }}/{{ user?.module_count ?? 0 }}
                            </span>
                        </div>
                    </div>
                    <button @click="logout" class="text-xs text-gray-600 hover:text-red-600 transition" aria-label="Вийти з системи">
                        Вийти
                    </button>
                </div>
            </div>
        </aside>

        <!-- Main content -->
        <div class="flex-1 flex flex-col min-h-screen pt-14 xl:pt-0">
            <!-- Skip navigation (accessibility) -->
            <a href="#main-content"
               class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50
                      focus:px-4 focus:py-2 focus:bg-indigo-600 focus:text-white focus:rounded-lg
                      focus:text-sm focus:font-medium">
                Перейти до основного вмісту
            </a>

            <!-- Flash messages -->
            <Transition
                enter-active-class="transition ease-out duration-300"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-200"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <div v-if="flashVisible && (flash.success || flash.error || flash.warning)"
                     class="px-6 pt-4 space-y-2" role="status" aria-live="polite">
                    <div v-if="flash.success"
                        class="flex items-center gap-2.5 px-4 py-3 rounded-lg bg-green-50 text-green-800 border border-green-200 text-sm">
                        <svg class="w-5 h-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="flex-1 whitespace-pre-line">{{ flash.success }}</span>
                        <button @click="dismissFlash" class="p-1 hover:bg-green-100 rounded transition" aria-label="Закрити">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div v-if="flash.warning"
                        class="flex items-center gap-2.5 px-4 py-3 rounded-lg bg-amber-50 text-amber-800 border border-amber-200 text-sm">
                        <svg class="w-5 h-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <span class="flex-1 whitespace-pre-line">{{ flash.warning }}</span>
                        <button @click="dismissFlash" class="p-1 hover:bg-amber-100 rounded transition" aria-label="Закрити">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div v-if="flash.error"
                        class="flex items-center gap-2.5 px-4 py-3 rounded-lg bg-red-50 text-red-800 border border-red-200 text-sm">
                        <svg class="w-5 h-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="flex-1 whitespace-pre-line">{{ flash.error }}</span>
                        <button @click="dismissFlash" class="p-1 hover:bg-red-100 rounded transition" aria-label="Закрити">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>
            </Transition>

            <!--
                A refusal the operator can see, on every page that has a form.
                `role="alert"` and `aria-live="assertive"`: this one interrupts,
                unlike the flash above — the page did not do what was asked.
            -->
            <div v-if="showValidationErrors" class="px-6 pt-4" role="alert" aria-live="assertive">
                <div class="flex items-start gap-2.5 px-4 py-3 rounded-lg bg-red-50 text-red-800 border border-red-200 text-sm">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div class="flex-1">
                        <p class="font-semibold mb-1">Не збережено — форму відхилено:</p>
                        <ul class="space-y-0.5">
                            <li v-for="(message, i) in validationErrors" :key="i" class="whitespace-pre-line">
                                {{ message }}
                            </li>
                        </ul>
                    </div>
                    <button @click="errorsDismissed = true" class="p-1 hover:bg-red-100 rounded transition" aria-label="Закрити">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <!-- Page slot -->
            <main id="main-content" class="flex-1 p-6">
                <div v-if="renderError" class="max-w-lg mx-auto mt-12 text-center">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-amber-100 flex items-center justify-center">
                        <svg class="w-8 h-8 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <h2 class="text-lg font-semibold text-gray-800 mb-2">Щось пішло не так</h2>
                    <p class="text-sm text-gray-600 mb-4">{{ renderError }}</p>
                    <button @click="renderError = null; router.reload()" class="btn-primary">Оновити сторінку</button>
                </div>
                <slot v-else />
            </main>
        </div>
    </div>
</template>

<style scoped>
/* ─── Sidebar shell ──────────────────────────────────── */
.sidebar {
    @apply bg-white border-r border-gray-200 flex-col shadow-sm;
    width: 16rem;
    min-width: 16rem;
}

/* ─── Links ──────────────────────────────────────────── */
.sidebar-link {
    @apply flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-gray-600
           hover:bg-indigo-50 hover:text-indigo-700 transition-colors cursor-pointer w-full;
    white-space: nowrap;
}
.sidebar-link.active {
    @apply bg-indigo-50 text-indigo-700 font-semibold;
    box-shadow: inset 3px 0 0 0 theme('colors.indigo.600');
}

/* ─── Zone labels (visual separators) ───────────────── */
.sidebar-zone-label {
    @apply text-[10px] text-gray-600 uppercase tracking-widest font-bold px-3 pt-3 pb-1;
    border-top: 1px solid theme('colors.gray.100');
}

/* ─── Groups (collapsible) ───────────────────────────── */
.sidebar-group {
    @apply px-3 pt-1 pb-0.5 cursor-pointer select-none;
}
.sidebar-group:hover .sidebar-group-label {
    @apply text-gray-600;
}
.sidebar-group-label {
    @apply text-xs text-gray-600 uppercase tracking-wider font-semibold flex items-center gap-1 transition-colors;
}
.sidebar-group-hint {
    @apply text-[10px] text-gray-600 leading-tight mt-0.5;
}

/* ─── Collapse chevron ───────────────────────────────── */
.collapse-chevron {
    display: inline-block;
    font-size: 10px;
    transition: transform 0.2s ease;
    color: inherit;
}
.collapse-chevron.open {
    transform: rotate(90deg);
}
</style>
