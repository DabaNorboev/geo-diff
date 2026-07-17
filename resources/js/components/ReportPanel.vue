<script setup>
import { onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useSessionStore } from '@/stores/session.js'

const emit = defineEmits(['close'])

const route = useRoute()
const store = useSessionStore()

onMounted(async () => {
    if (!store.report) {
        await store.loadReport(route.params.id)
    }
})

function formatDistance(meters) {
    if (meters === null || meters === undefined) return '—'
    if (meters >= 1000) return (meters / 1000).toFixed(1) + ' км'
    return Math.round(meters) + ' м'
}

const categoryConfig = {
    exact: {
        label: 'Совпадение',
        bg: 'bg-green-50',
        border: 'border-green-200',
        badge: 'bg-green-100 text-green-700',
        icon: '✓',
    },
    divergent: {
        label: 'Расхождение',
        bg: 'bg-yellow-50',
        border: 'border-yellow-200',
        badge: 'bg-yellow-100 text-yellow-700',
        icon: '~',
    },
    outlier: {
        label: 'Большое расхождение',
        bg: 'bg-red-50',
        border: 'border-red-200',
        badge: 'bg-red-100 text-red-700',
        icon: '!',
    },
    single: {
        label: 'Один геокодер',
        bg: 'bg-gray-50',
        border: 'border-gray-200',
        badge: 'bg-gray-100 text-gray-600',
        icon: '½',
    },
    not_found: {
        label: 'Не найдено',
        bg: 'bg-gray-50',
        border: 'border-gray-300',
        badge: 'bg-gray-200 text-gray-500',
        icon: '✕',
    },
}
</script>

<template>
    <div class="flex flex-col h-full">

        <!-- Шапка -->
        <div class="flex items-center justify-between px-4 py-3.5 border-b border-gray-200 shrink-0">
            <div>
                <h2 class="text-sm font-semibold text-gray-800">Отчёт по адресам</h2>
                <p v-if="store.report" class="text-xs text-gray-400 mt-0.5">
                    {{ store.report.length }} адресов
                </p>
            </div>
            <button
                class="w-7 h-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors text-lg leading-none"
                @click="emit('close')"
            >
                ×
            </button>
        </div>

        <!-- Список -->
        <div class="flex-1 overflow-y-auto px-3 py-3 flex flex-col gap-2">

            <div v-if="!store.report" class="text-xs text-gray-400 text-center mt-8">
                Загружаем отчёт...
            </div>

            <template v-else>
                <div
                    v-for="(item, index) in store.report"
                    :key="index"
                    :class="[
                        'rounded-lg border px-3 py-2.5',
                        categoryConfig[item.category].bg,
                        categoryConfig[item.category].border,
                    ]"
                >
                    <!-- Адрес + бейдж -->
                    <div class="flex items-start justify-between gap-2 mb-1">
                        <span class="text-xs font-semibold text-gray-800 leading-snug">
                            {{ item.raw_address }}
                        </span>
                        <span
                            :class="[
                                'shrink-0 text-xs px-1.5 py-0.5 rounded-full font-medium whitespace-nowrap',
                                categoryConfig[item.category].badge,
                            ]"
                        >
                            {{ categoryConfig[item.category].icon }}
                            {{ categoryConfig[item.category].label }}
                        </span>
                    </div>

                    <!-- Расхождение -->
                    <div
                        v-if="item.distance_meters !== null"
                        class="text-xs text-gray-500 mb-1.5"
                    >
                        Расхождение: <b>{{ formatDistance(item.distance_meters) }}</b>
                    </div>

                    <!-- Exact — display_name -->
                    <div
                        v-if="item.category === 'exact' && item.nominatim_display_name"
                        class="text-xs text-gray-400 mt-1"
                    >
                        {{ item.nominatim_display_name }}
                    </div>

                    <!-- Divergent — оба display_name -->
                    <template v-if="item.category === 'divergent'">
                        <div v-if="item.nominatim_display_name" class="text-xs text-gray-500 mt-1">
                            <span class="font-medium text-gray-600">Nominatim:</span>
                            {{ item.nominatim_display_name }}
                        </div>
                        <div v-if="item.photon_display_name" class="text-xs text-gray-500 mt-0.5">
                            <span class="font-medium text-gray-600">Photon:</span>
                            {{ item.photon_display_name }}
                        </div>
                    </template>

                    <!-- Outlier — оба display_name + предупреждение -->
                    <template v-if="item.category === 'outlier'">
                        <div v-if="item.nominatim_display_name" class="text-xs text-gray-500 mt-1">
                            <span class="font-medium text-gray-600">Nominatim нашёл:</span>
                            {{ item.nominatim_display_name }}
                        </div>
                        <div v-if="item.photon_display_name" class="text-xs text-gray-500 mt-0.5">
                            <span class="font-medium text-gray-600">Photon нашёл:</span>
                            {{ item.photon_display_name }}
                        </div>
                        <div class="text-xs text-amber-700 bg-amber-50 rounded px-2 py-1 mt-1.5">
                            ⚠ Возможная ошибка геокодера — сравните адреса выше
                        </div>
                    </template>

                    <!-- Single — кто нашёл -->
                    <div
                        v-if="item.category === 'single' && item.found_by"
                        class="text-xs text-gray-400 mt-1"
                    >
                        Нашёл: <span class="font-medium text-gray-600">{{ item.found_by }}</span>
                    </div>

                </div>
            </template>
        </div>
    </div>
</template>
