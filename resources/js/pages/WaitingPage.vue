<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'

const route = useRoute()
const router = useRouter()

const session = ref(null)
const error = ref(null)
let pollInterval = null

const progress = computed(() => {
    if (!session.value || !session.value.total_addresses) return 0
    return Math.round((session.value.processed_addresses / session.value.total_addresses) * 100)
})

async function poll() {
    try {
        const { data } = await axios.get(`/api/sessions/${route.params.id}`)
        session.value = data

        if (data.status === 'completed') {
            clearInterval(pollInterval)
            router.push(`/sessions/${route.params.id}/result`)
        }

        if (data.status === 'failed') {
            clearInterval(pollInterval)
            error.value = 'Произошла ошибка при обработке. Попробуйте загрузить файл снова.'
        }
    } catch (e) {
        error.value = 'Не удалось получить статус сессии'
        clearInterval(pollInterval)
    }
}

onMounted(() => {
    poll()
    pollInterval = setInterval(poll, 10000)
})

onUnmounted(() => {
    clearInterval(pollInterval)
})
</script>

<template>
    <div class="min-h-screen bg-gray-50 flex items-center justify-center px-4">
        <div class="w-full max-w-md">

            <div v-if="error" class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-4 py-3">
                {{ error }}
                <br />
                <a href="/" class="underline mt-2 inline-block">Вернуться на главную</a>
            </div>

            <template v-else>
                <h1 class="text-2xl font-semibold text-gray-800 mb-2">
                    Идёт анализ...
                </h1>
                <p class="text-gray-500 text-sm mb-8">
                    Геокодируем адреса и считаем расхождения. Страница обновляется автоматически.
                </p>

                <div v-if="session">
                    <div class="flex justify-between text-sm text-gray-500 mb-1">
                        <span>{{ session.processed_addresses }} из {{ session.total_addresses }} адресов</span>
                        <span>{{ progress }}%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div
                            class="bg-blue-600 h-2 rounded-full transition-all duration-500"
                            :style="{ width: progress + '%' }"
                        />
                    </div>
                </div>

                <div v-else class="text-sm text-gray-400">
                    Загружаем статус...
                </div>
            </template>

        </div>
    </div>
</template>
