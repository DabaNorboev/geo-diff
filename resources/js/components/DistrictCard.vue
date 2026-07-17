<script setup>
defineProps({
    district: Object
})

function formatDistance(meters) {
    if (meters === null || meters === undefined) return '—'
    return Math.round(meters) + ' м'
}

function formatRate(rate) {
    if (rate === null || rate === undefined) return '—'
    return (rate * 100).toFixed(1) + '%'
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <p class="font-medium text-gray-800">{{ district.name }}</p>
        <div class="flex flex-col gap-2">
            <div class="flex justify-between text-sm">
                <span class="text-gray-500">Адресов в выборке</span>
                <span class="font-medium text-gray-800">{{ district.address_count ?? 0 }}</span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-gray-500">Среднее расхождение</span>
                <span class="font-medium text-gray-800">{{ formatDistance(district.avg_distance_meters) }}</span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-gray-500">Проблемных адресов</span>
                <span class="font-medium text-gray-800">{{ formatRate(district.problem_rate) }}</span>
            </div>
        </div>
    </div>
</template>
