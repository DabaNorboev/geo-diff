<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

const router = useRouter()

const file = ref(null)
const error = ref(null)
const loading = ref(false)

function onFileChange(e) {
    file.value = e.target.files[0]
    error.value = null
}

async function submit() {
    if (!file.value) {
        error.value = 'Выберите файл'
        return
    }

    loading.value = true
    error.value = null

    const formData = new FormData()
    formData.append('file', file.value)

    try {
        const { data } = await axios.post('/api/sessions', formData)
        router.push(`/sessions/${data.session_id}/waiting`)
    } catch (e) {
        error.value = e.response?.data?.message ?? 'Ошибка при загрузке файла'
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <div class="min-h-screen bg-gray-50 flex items-center justify-center px-4">
        <div class="w-full max-w-md">
            <h1 class="text-2xl font-semibold text-gray-800 mb-2">
                Анализ качества геокодирования
            </h1>
            <p class="text-gray-500 text-sm mb-8 leading-relaxed">
                Загрузите файл со списком адресов — система сравнит результаты Nominatim и Photon
                и покажет расхождения на карте. Поддерживаются форматы:
                <code class="bg-gray-100 px-1 rounded">.csv</code>
                (с колонкой <code class="bg-gray-100 px-1 rounded">address</code>),
                <code class="bg-gray-100 px-1 rounded">.txt</code>
                (адрес на строке) и
                <code class="bg-gray-100 px-1 rounded">.json</code>
                (массив строк или объектов с полем <code class="bg-gray-100 px-1 rounded">address</code>).
            </p>

            <div class="flex flex-col gap-4">
                <label class="flex items-center gap-3 border-2 border-dashed border-gray-300 rounded-lg px-4 py-3 cursor-pointer hover:border-blue-400 transition-colors">
                    <input type="file" accept=".csv,.txt,.json" class="hidden" @change="onFileChange" />
                    <span class="text-gray-600 text-sm truncate">
                        {{ file ? file.name : 'Выберите файл (.csv, .txt, .json)' }}
                    </span>
                </label>

                <p class="text-xs text-gray-400">
                    Максимум 300 адресов. Для CSV первая строка — заголовок с колонкой address.
                </p>

                <div v-if="error" class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
                    {{ error }}
                </div>

                <button
                    :disabled="loading"
                    class="w-full py-3 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    @click="submit"
                >
                    {{ loading ? 'Загружаем...' : 'Начать анализ' }}
                </button>
            </div>
        </div>
    </div>
</template>
