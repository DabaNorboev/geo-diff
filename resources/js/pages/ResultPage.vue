<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useSessionStore } from '@/stores/session.js'
import MapView from '@/components/MapView.vue'
import DistrictCard from '@/components/DistrictCard.vue'
import ReportPanel from '@/components/ReportPanel.vue'
import RetryModal from '@/components/RetryModal.vue'

const showReport = ref(false)
const showRetryModal = ref(false)

const route = useRoute()
const store = useSessionStore()

async function loadAll() {
    await Promise.all([
        store.loadSession(route.params.id),
        store.loadDistricts(route.params.id),
        store.loadPoints(route.params.id),
        store.loadPairs(route.params.id),
        store.loadCoverage(route.params.id),
    ])
}

onMounted(loadAll)

async function onRetryCompleted() {
    showRetryModal.value = false
    // повторный поиск мог поменять координаты, расхождения, покрытие и агрегаты районов —
    // перезапрашиваем те же данные, что и при первой загрузке страницы
    await loadAll()
}

function exportDistricts() {
    window.open(`/api/sessions/${route.params.id}/export/districts`)
}

function exportPoints() {
    window.open(`/api/sessions/${route.params.id}/export/points`)
}

function formatRate(rate) {
    if (rate === null || rate === undefined) return '—'
    return (rate * 100).toFixed(1) + '%'
}

function formatDistance(meters) {
    if (meters === null || meters === undefined) return '—'
    return Math.round(meters) + ' м'
}

function moranInterpretation(value) {
    if (value === null || value === undefined) return 'недостаточно данных'
    if (value > 0.3) return 'сильная кластеризация'
    if (value > 0.1) return 'умеренная кластеризация'
    if (value > -0.1) return 'случайное распределение'
    return 'рассеивание'
}
</script>

<template>
    <div class="flex h-screen bg-gray-50 font-sans overflow-hidden">

        <!-- Левый сайдбар -->
        <aside class="w-72 shrink-0 bg-white border-r border-gray-200 flex flex-col overflow-y-auto">

            <div class="px-4 py-4 border-b border-gray-100">
                <a href="/" class="text-xs text-blue-600 hover:underline">← Новый анализ</a>
                <h1 class="text-base font-semibold text-gray-800 mt-1">Результаты анализа</h1>
                <p class="text-xs text-gray-400 mt-0.5 truncate">{{ store.session?.original_filename }}</p>
            </div>

            <!-- Общая статистика -->
            <div v-if="store.session" class="px-4 py-4 border-b border-gray-100">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Статистика</p>
                <div class="flex flex-col gap-2.5">
                    <div class="flex justify-between items-center">
                        <span class="text-xs text-gray-500">Адресов</span>
                        <span class="text-sm font-semibold text-gray-800">{{ store.session.processed_addresses }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-xs text-gray-500">Среднее расхождение</span>
                        <span class="text-sm font-semibold text-gray-800">{{ formatDistance(store.session.avg_distance_meters) }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-xs text-gray-500">Проблемных</span>
                        <span class="text-sm font-semibold text-gray-800">{{ formatRate(store.session.problem_rate) }}</span>
                    </div>
                    <div class="h-px bg-gray-100 my-0.5" />
                    <div class="flex justify-between items-center">
                        <span class="text-xs text-gray-500">Индекс Морана</span>
                        <span class="text-sm font-semibold text-gray-800">
                            {{ store.session.moran_i !== null ? store.session.moran_i?.toFixed(3) : '—' }}
                        </span>
                    </div>
                    <div v-if="store.session.moran_i !== null" class="text-xs text-gray-400 bg-gray-50 rounded px-2.5 py-1.5">
                        {{ moranInterpretation(store.session.moran_i) }}
                    </div>
                </div>
            </div>

            <!-- Покрытие -->
            <div v-if="store.coverage" class="px-4 py-4 border-b border-gray-100">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Покрытие</p>
                <div class="flex flex-col gap-2">
                    <div class="flex justify-between items-center">
                        <span class="text-xs text-gray-500">Оба геокодера</span>
                        <span class="text-sm font-semibold text-green-600">{{ store.coverage.both_geocoders }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-xs text-gray-500">Один геокодер</span>
                        <span class="text-sm font-semibold text-amber-500">{{ store.coverage.one_geocoder }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-xs text-gray-500">Не найдено</span>
                        <span class="text-sm font-semibold text-red-500">{{ store.coverage.failed }}</span>
                    </div>
                    <p class="text-xs text-gray-400 leading-relaxed mt-1">
                        Расхождение считается только когда оба геокодера нашли адрес.
                    </p>

                    <button
                        v-if="store.coverage.one_geocoder > 0 || store.coverage.failed > 0"
                        class="w-full mt-1 text-xs py-2 px-3 rounded-lg font-medium bg-blue-50 text-blue-700 hover:bg-blue-100 transition-colors"
                        @click="showRetryModal = true"
                    >
                        Повторить проблемные адреса
                    </button>
                </div>
            </div>

            <!-- Район -->
            <div class="px-4 py-4 border-b border-gray-100 flex-1">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Район</p>
                <DistrictCard
                    v-if="store.selectedDistrict"
                    :district="store.selectedDistrict"
                />
                <p v-else class="text-xs text-gray-400">
                    Нажмите на район на карте
                </p>
            </div>

            <!-- Экспорт -->
            <div class="px-4 py-4 border-b border-gray-100">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Экспорт</p>
                <div class="flex flex-col gap-2">
                    <button
                        class="w-full text-xs py-2 px-3 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 hover:border-gray-300 transition-colors text-left flex items-center gap-2"
                        @click="exportDistricts"
                    >
                        <span class="text-gray-400">↓</span> Районы (GeoJSON)
                    </button>
                    <button
                        class="w-full text-xs py-2 px-3 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 hover:border-gray-300 transition-colors text-left flex items-center gap-2"
                        @click="exportPoints"
                    >
                        <span class="text-gray-400">↓</span> Адреса (GeoJSON)
                    </button>
                </div>
            </div>

            <!-- Отчёт -->
            <div class="px-4 py-4">
                <button
                    :class="[
                        'w-full text-xs py-2.5 px-3 rounded-lg font-medium transition-colors',
                        showReport
                            ? 'bg-gray-800 text-white hover:bg-gray-700'
                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                    ]"
                    @click="showReport = !showReport"
                >
                    {{ showReport ? 'Скрыть отчёт' : 'Текстовый отчёт' }}
                </button>
            </div>

        </aside>

        <!-- Карта -->
        <main class="flex-1 relative min-w-0">
            <MapView v-if="store.districts && store.points && store.pairs" />
            <div v-else class="flex items-center justify-center h-full text-gray-400 text-sm">
                Загружаем данные...
            </div>
        </main>

        <!-- Правый сайдбар — отчёт -->
        <Transition name="report">
            <aside
                v-if="showReport"
                class="w-96 shrink-0 bg-white border-l border-gray-200 flex flex-col overflow-hidden"
            >
                <ReportPanel @close="showReport = false" />
            </aside>
        </Transition>

        <!-- Повторный поиск -->
        <RetryModal
            v-if="showRetryModal"
            :session-id="route.params.id"
            @close="showRetryModal = false"
            @completed="onRetryCompleted"
        />

    </div>
</template>

<style scoped>
.report-enter-active, .report-leave-active {
    transition: width 0.25s ease, opacity 0.25s ease;
    overflow: hidden;
}
.report-enter-from, .report-leave-to {
    width: 0;
    opacity: 0;
}
.report-enter-to, .report-leave-from {
    width: 24rem;
    opacity: 1;
}
</style>
