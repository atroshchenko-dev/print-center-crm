<script setup>
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
    categories: Array,
    riso_tiers: Array,
    updated_at: String,
})



function printPage() {
    window.print()
}

const hasAuth = computed(() => {
    try { return !!route('dashboard') } catch { return false }
})

function fmt(n) {
    return Number(n).toFixed(0)
}

/** Returns true if category has paper type + density columns (print), false for lamination */
function hasPaperColumns(cat) {
    return cat.paper_groups?.some(pg => pg.density && pg.density !== '') ?? false
}
</script>

<template>
    <Head title="Комерційний прайс-лист · CRM Print" />

    <div class="price-list-page">
        <!-- Header -->
        <header class="price-header">
            <div class="price-header-inner">
                <div>
                    <h1 class="price-title">Прайс-лист послуг</h1>
                    <p class="price-subtitle">Центр оперативної поліграфії університету</p>
                </div>
                <div class="price-meta">
                    <span class="price-date">Ціни актуальні на: <strong>{{ updated_at }}</strong></span>
                    <div class="no-print btn-group">
                        <button @click="printPage" class="btn-print">Друкувати</button>
                        <Link v-if="hasAuth" :href="route('dashboard')" class="btn-back">← CRM</Link>
                    </div>
                </div>
            </div>
        </header>

        <main class="price-content">
            <div v-for="cat in categories" :key="cat.id" class="price-category">
                <h2 class="category-title">
                    {{ cat.name }}
                    <span class="category-subtitle" v-if="cat.name.includes('друк')">
                        / {{ cat.name.includes('Чорно') ? 'Black and white print' : 'Color print' }}
                    </span>
                </h2>

                <!-- REQUEST type -->
                <div v-if="cat.type === 'request'" class="price-request">
                    Ціна за запитом
                </div>

                <!-- PIVOT type (print categories) -->
                <template v-else-if="cat.type === 'pivot' && cat.formats?.length">
                    <table class="price-table pivot-table">
                        <thead>
                            <tr>
                                <!-- Show paper type + density columns only for print (not lamination) -->
                                <template v-if="hasPaperColumns(cat)">
                                    <th scope="col" class="th-paper">Тип паперу<br><span class="th-sub">Type of paper</span></th>
                                    <th scope="col" class="th-density">Щільність<br><span class="th-sub">g/m</span></th>
                                    <th scope="col" class="th-fill">Заповненість<br>сторінок, %</th>
                                </template>
                                <template v-else>
                                    <th scope="col" class="th-fill">{{ cat.paper_groups?.[0]?.name || 'Послуга' }}</th>
                                </template>
                                <th scope="col" v-for="f in cat.formats" :key="f" class="th-format">
                                    {{ f }}
                                    <br><span class="th-sub">{{ f === 'А4' ? '210×297' : '297×420' }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="(pg, pgIdx) in cat.paper_groups" :key="pgIdx">
                                <tr v-for="(row, rowIdx) in pg.rows" :key="rowIdx"
                                    :class="{ 'group-first': rowIdx === 0, 'group-border': rowIdx === 0 && pgIdx > 0 }">
                                    <template v-if="hasPaperColumns(cat)">
                                        <td v-if="rowIdx === 0" :rowspan="pg.rows.length" class="td-paper">
                                            {{ pg.name }}
                                        </td>
                                        <td v-if="rowIdx === 0" :rowspan="pg.rows.length" class="td-density">
                                            {{ pg.density }}
                                        </td>
                                    </template>
                                    <td class="td-fill">{{ row.fill }}</td>
                                    <td v-for="f in cat.formats" :key="f" class="td-price">
                                        <template v-if="row.prices[f]">
                                            {{ fmt(row.prices[f]) }} грн
                                        </template>
                                        <template v-else>—</template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <p v-if="cat.print_note" class="price-note">* Ціни вказано за односторонній друк. Двосторонній — ×2.</p>
                </template>

                <!-- SIMPLE type (lamination, binding, scanning) -->
                <template v-else-if="cat.simple_rows?.length">
                    <table class="price-table simple-table">
                        <thead>
                            <tr>
                                <th scope="col" class="th-service">Послуга</th>
                                <th scope="col" class="th-price-simple">Ціна, грн</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, idx) in cat.simple_rows" :key="idx">
                                <td class="td-service">{{ row.label }}</td>
                                <td class="td-price">{{ fmt(row.price) }} грн</td>
                            </tr>
                        </tbody>
                    </table>
                </template>

                <!-- TIERS type (hard binding — page count tiers) -->
                <template v-else-if="cat.type === 'tiers' && cat.tier_rows?.length">
                    <table class="price-table simple-table">
                        <thead>
                            <tr>
                                <th scope="col" class="th-service">{{ cat.tier_header }}</th>
                                <th scope="col" class="th-price-simple">{{ cat.tier_price_header }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, idx) in cat.tier_rows" :key="idx">
                                <td class="td-service">{{ row.label }}</td>
                                <td class="td-price">{{ fmt(row.price) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </div>

            <!-- Footer -->
            <div class="price-footer no-print">
                <p>* Ціни можуть змінюватися. Для актуальної інформації зверніться до оператора.</p>
            </div>
            <div class="price-footer print-only">
                <p>Центр оперативної поліграфії університету · {{ updated_at }}</p>
            </div>
        </main>
    </div>
</template>

<style scoped>
/* ─── Page ────────────────────────────────────────────── */
.price-list-page {
    min-height: 100vh;
    background: #fff;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: #1a1a1a;
}

/* ─── Header ──────────────────────────────────────────── */
.price-header {
    border-bottom: 2px solid #1a1a1a;
}
.price-header-inner {
    max-width: 900px;
    margin: 0 auto;
    padding: 20px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.price-title {
    font-size: 1.4rem;
    font-weight: 800;
    color: #1a1a1a;
    letter-spacing: -0.5px;
}
.price-subtitle {
    font-size: 0.8rem;
    color: #666;
    margin-top: 2px;
}
.price-meta {
    text-align: right;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
}
.price-date {
    font-size: 0.8rem;
    color: #666;
}
.btn-group {
    display: flex;
    gap: 8px;
}
.btn-print {
    padding: 8px 20px;
    background: #1a1a1a;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
}
.btn-print:hover {
    background: #333;
}
.btn-back {
    padding: 8px 16px;
    background: white;
    color: #1a1a1a;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    text-decoration: none;
}
.btn-back:hover {
    background: #f5f5f5;
}

/* ─── Content ─────────────────────────────────────────── */
.price-content {
    max-width: 900px;
    margin: 0 auto;
    padding: 24px;
}

/* ─── Category ────────────────────────────────────────── */
.price-category {
    margin-bottom: 32px;
    break-inside: avoid;
}
.category-title {
    font-size: 1rem;
    font-weight: 700;
    color: #1a1a1a;
    text-align: center;
    padding: 10px 16px;
    margin-bottom: 0;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border: 2px solid #1a1a1a;
    border-bottom: none;
    background: #f8f8f8;
}
.category-subtitle {
    font-weight: 400;
    font-style: italic;
    text-transform: none;
    color: #666;
    font-size: 0.85rem;
}

/* ─── Pivot Table ─────────────────────────────────────── */
.price-table {
    width: 100%;
    border-collapse: collapse;
    border: 2px solid #1a1a1a;
    font-size: 0.85rem;
}
.price-table th {
    background: #f0f0f0;
    border: 1px solid #1a1a1a;
    padding: 8px 10px;
    text-align: center;
    font-weight: 700;
    font-size: 0.8rem;
    vertical-align: middle;
}
.th-sub {
    font-weight: 400;
    font-style: italic;
    color: #666;
    font-size: 0.7rem;
}
.th-paper { width: 22%; text-align: left; padding-left: 12px !important; }
.th-density { width: 10%; }
.th-fill { width: 18%; }
.th-format { width: 12%; }
.th-service { text-align: left; padding-left: 12px !important; }
.th-price-simple { width: 20%; text-align: center; }

.price-table td {
    border: 1px solid #999;
    padding: 6px 10px;
    vertical-align: middle;
}
.td-paper {
    font-weight: 600;
    font-size: 0.8rem;
    line-height: 1.3;
    background: #fafafa;
}
.td-density {
    text-align: center;
    font-size: 0.8rem;
    color: #555;
    background: #fafafa;
}
.td-fill {
    text-align: center;
    font-size: 0.8rem;
}
.td-price {
    text-align: center;
    font-weight: 700;
    font-size: 0.9rem;
    font-variant-numeric: tabular-nums;
}
.td-service {
    padding-left: 12px;
}

.group-border td {
    border-top: 2px solid #1a1a1a;
}

/* ─── Notes ───────────────────────────────────────────── */
.price-note {
    font-size: 0.75rem;
    color: #595959;
    margin-top: 6px;
    font-style: italic;
    padding-left: 4px;
}

.price-request {
    border: 2px solid #1a1a1a;
    border-top: none;
    padding: 20px;
    text-align: center;
    color: #595959;
    font-style: italic;
}

.price-footer {
    margin-top: 32px;
    text-align: center;
    font-size: 0.75rem;
    color: #595959;
}

/* ─── Print ───────────────────────────────────────────── */
.print-only { display: none; }

@media print {
    .no-print { display: none !important; }
    .print-only { display: block !important; }
    .price-list-page { background: white !important; }
    .price-header { border-bottom-width: 2px; }
    .price-content { padding: 12px 0; }
    .price-category { margin-bottom: 20px; }
    .price-table {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .price-table th {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .td-paper, .td-density {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}

@media (max-width: 640px) {
    .price-header-inner { flex-direction: column; align-items: flex-start; }
    .price-title { font-size: 1.2rem; }
    .price-table { font-size: 0.75rem; }
    .td-price { font-size: 0.8rem; }
}
</style>
