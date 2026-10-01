<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps({
    tiers:     Array,
    paper_cost: Number,
    gaps:      { type: Array, default: () => [] },
})

// Пропуски в драбині: тираж, що падає в такий, не порахується взагалі, і це
// краще за те, що було до раунду 30 — тиха ціна з найдорожчого тарифу.
function gapLabel(gap) {
    return gap.to === null ? `${gap.from} і більше` : `${gap.from}–${gap.to}`
}

const form = useForm({
    tiers: props.tiers.map(t => ({ ...t })),
})

function save() {
    form.patch(route('admin.riso-pricing.update'))
}

// Add new tier
const showAdd = ref(false)
const newTier = useForm({
    min_qty: 1,
    max_qty: null,
    cost_per_copy: 0,
})

function addTier() {
    newTier.post(route('admin.riso-pricing.store'), {
        onSuccess: () => { showAdd.value = false; newTier.reset() },
    })
}

function deleteTier(tier) {
    if (confirm(`Видалити тариф ${tier.min_qty}–${tier.max_qty ?? '∞'}?`)) {
        router.delete(route('admin.riso-pricing.destroy', tier.id))
    }
}
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="page-header !mb-0">
                    <div class="page-header-icon bg-gradient-to-br from-violet-400 to-purple-600">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Тарифи ризографа</h1>
                        <p class="page-header-subtitle">Тарифна сітка прогонів</p>
                    </div>
                </div>
                <button @click="showAdd = !showAdd" class="btn-primary w-full sm:w-auto">+ Додати тариф</button>
            </div>

            <!-- Пропуски в драбині -->
            <div v-if="gaps.length" class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-300 text-sm text-red-800">
                <p class="font-semibold">
                    У драбині {{ gaps.length === 1 ? 'є пропуск' : 'є пропуски' }} — ці тиражі не порахуються:
                </p>
                <ul class="mt-1 ml-4 list-disc">
                    <li v-for="gap in gaps" :key="gap.from"><strong>{{ gapLabel(gap) }}</strong> аркушів А3</li>
                </ul>
                <p class="mt-2 text-xs text-red-700">
                    Замовлення з таким тиражем буде відхилено з повідомленням, а не пораховано.
                    Так безпечніше: доти ціна тихо бралася з найдорожчого тарифу — тираж 220
                    коштував як 50–99, тобто майже втричі більше.
                </p>
            </div>

            <!-- Paper cost info -->
            <div class="mb-4 px-4 py-3 rounded-lg bg-indigo-50 border border-indigo-200 text-sm text-indigo-800">
                Ціна аркуша А3: <strong>{{ paper_cost.toFixed(2) }} грн</strong>
                <span class="text-xs text-indigo-500 ml-2">(автоматично з AVCO «Папір А3 80 г/м²» на складі)</span>
            </div>

            <!-- Add tier form -->
            <div v-if="showAdd" class="card mb-4 p-4">
                <h2 class="text-sm font-bold text-gray-700 mb-3">Новий тариф</h2>
                <form @submit.prevent="addTier" class="flex gap-3 items-start">
                    <div>
                        <label class="text-xs text-gray-600 block mb-1">Від (шт)</label>
                        <input aria-label="Від (шт)" v-model.number="newTier.min_qty" type="number" min="1" class="input w-24" required />
                        <p v-if="newTier.errors.min_qty" class="text-xs text-red-600 mt-1 w-24">{{ newTier.errors.min_qty }}</p>
                    </div>
                    <div>
                        <label class="text-xs text-gray-600 block mb-1">До (шт)</label>
                        <input aria-label="До (шт)" v-model.number="newTier.max_qty" type="number" min="1" class="input w-24"
                            placeholder="∞" />
                        <p v-if="newTier.errors.max_qty" class="text-xs text-red-600 mt-1 w-24">{{ newTier.errors.max_qty }}</p>
                    </div>
                    <div>
                        <label class="text-xs text-gray-600 block mb-1">Ціна за прогін</label>
                        <input aria-label="Ціна за прогін" v-model.number="newTier.cost_per_copy" type="number" step="0.0001" min="0"
                            class="input w-32" required />
                        <p v-if="newTier.errors.cost_per_copy" class="text-xs text-red-600 mt-1 w-32">{{ newTier.errors.cost_per_copy }}</p>
                    </div>
                    <button type="submit" class="btn-primary mt-5" :disabled="newTier.processing">Додати</button>
                    <button type="button" class="btn-ghost border border-gray-200 mt-5" @click="showAdd = false">Скасувати</button>
                </form>
            </div>

            <!-- Tiers table -->
            <form @submit.prevent="save">
                <div class="card p-0 overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 border-b text-left text-gray-600 text-xs uppercase tracking-wide">
                                <th scope="col" class="px-4 py-3">Від (аркушів А3)</th>
                                <th scope="col" class="px-4 py-3">До (аркушів А3)</th>
                                <th scope="col" class="px-4 py-3">Ціна за 1 прогін (грн)</th>
                                <th scope="col" class="px-4 py-3">2 ст. (×2)</th>
                                <th scope="col" class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="(tier, idx) in form.tiers" :key="tier.id" class="hover:bg-gray-50 transition">
                                <td class="px-4 py-2">
                                    <input aria-label="Тираж від" v-model.number="tier.min_qty" type="number" min="1"
                                        class="input w-20 text-center font-mono" />
                                </td>
                                <td class="px-4 py-2">
                                    <input aria-label="Тираж до" v-model.number="tier.max_qty" type="number" min="1"
                                        class="input w-20 text-center font-mono" :placeholder="'∞'" />
                                </td>
                                <td class="px-4 py-2">
                                    <input aria-label="Собівартість копії" v-model.number="tier.cost_per_copy" type="number" step="0.0001" min="0"
                                        class="input w-28 text-center font-mono font-semibold" />
                                </td>
                                <td class="px-4 py-2 font-mono text-gray-600">
                                    {{ (tier.cost_per_copy * 2).toFixed(4) }}
                                </td>
                                <td class="px-4 py-2">
                                    <button type="button" @click="deleteTier(tier)"
                                        class="text-xs text-red-600 hover:text-red-800"><svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4 flex justify-end">
                    <button type="submit" class="btn-primary" :disabled="form.processing">
                        {{ form.processing ? 'Зберігаю…' : 'Зберегти зміни' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
