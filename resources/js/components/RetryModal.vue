<script setup>
import { ref, onMounted, onBeforeUnmount, computed } from 'vue'

const props = defineProps({
    sessionId: { type: String, required: true },
})

const emit = defineEmits(['close', 'completed'])

// 'loading' -> 'review' -> 'submitting' -> 'polling' -> 'done'
const phase = ref('loading')
const candidates = ref([])
const error = ref(null)

const progress = ref({ processed: 0, total: 0 })
let pollTimer = null

const categoryLabel = {
    not_found: 'Не найден',
    single: 'Нашёл только один геокодер',
    outlier: 'Выброс (>1000м)',
}

async function loadCandidates() {
    phase.value = 'loading'
    error.value = null
    try {
        const res = await fetch(`/api/sessions/${props.sessionId}/retry-candidates`)
        if (!res.ok) throw new Error('Не удалось получить список адресов')
        const data = await res.json()
        candidates.value = data.candidates.map((c) => ({
            ...c,
            selected: true,
            editedAddress: c.suggested_address,
        }))
        phase.value = 'review'
    } catch (e) {
        error.value = e.message
        phase.value = 'review'
    }
}

const selectedCount = computed(() => candidates.value.filter((c) => c.selected).length)

async function submitRetry() {
    const selected = candidates.value.filter((c) => c.selected)
    if (selected.length === 0) return

    phase.value = 'submitting'
    error.value = null

    try {
        const res = await fetch(`/api/sessions/${props.sessionId}/retry`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                addresses: selected.map((c) => ({
                    id: c.id,
                    address_to_use: c.editedAddress.trim(),
                })),
            }),
        })

        if (!res.ok) {
            const body = await res.json().catch(() => ({}))
            throw new Error(body.message || 'Не удалось запустить повторный поиск')
        }

        progress.value = { processed: 0, total: selected.length }
        phase.value = 'polling'
        startPolling()
    } catch (e) {
        error.value = e.message
        phase.value = 'review'
    }
}

function startPolling() {
    pollTimer = setInterval(async () => {
        try {
            const res = await fetch(`/api/sessions/${props.sessionId}`)
            if (!res.ok) return
            const data = await res.json()

            progress.value.processed = data.processed_addresses
            progress.value.total = data.total_addresses

            if (data.status === 'completed') {
                clearInterval(pollTimer)
                phase.value = 'done'
            }
        } catch {
            // сеть могла моргнуть — просто пробуем на следующем тике, ничего не ломаем
        }
    }, 10000)
}

function close() {
    if (pollTimer) clearInterval(pollTimer)
    emit('close')
}

function finish() {
    if (pollTimer) clearInterval(pollTimer)
    emit('completed')
}

onMounted(loadCandidates)
onBeforeUnmount(() => {
    if (pollTimer) clearInterval(pollTimer)
})
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-2xl max-h-[85vh] flex flex-col rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between border-b px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">Повторный поиск адресов</h2>
                <button
                    v-if="phase !== 'polling' && phase !== 'submitting'"
                    @click="close"
                    class="text-gray-400 hover:text-gray-600"
                >
                    ✕
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-5 py-4">
                <!-- загрузка кандидатов -->
                <div v-if="phase === 'loading'" class="py-10 text-center text-gray-500">
                    Загружаю список проблемных адресов…
                </div>

                <!-- список для правки -->
                <div v-else-if="phase === 'review' || phase === 'submitting'">
                    <p v-if="error" class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ error }}
                    </p>

                    <p v-if="candidates.length === 0" class="py-10 text-center text-gray-500">
                        Нет адресов, требующих повторного поиска.
                    </p>

                    <div v-else class="space-y-3">
                        <p class="text-sm text-gray-500">
                            Выбрано {{ selectedCount }} из {{ candidates.length }}. Проверьте предложенный
                            вариант адреса — при необходимости отредактируйте перед подтверждением.
                        </p>

                        <div
                            v-for="c in candidates"
                            :key="c.id"
                            class="rounded border border-gray-200 p-3"
                        >
                            <div class="flex items-start gap-3">
                                <input type="checkbox" v-model="c.selected" class="mt-1.5" />
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 text-sm">
                                        <span class="font-medium text-gray-900">{{ c.raw_address }}</span>
                                        <span
                                            class="rounded px-1.5 py-0.5 text-xs"
                                            :class="{
                        'bg-red-100 text-red-700': c.category === 'not_found',
                        'bg-amber-100 text-amber-700': c.category === 'single',
                        'bg-purple-100 text-purple-700': c.category === 'outlier',
                      }"
                                        >
                      {{ categoryLabel[c.category] }}
                    </span>
                                        <span v-if="c.was_simplified" class="text-xs text-emerald-600">
                      автоупрощено
                    </span>
                                    </div>

                                    <input
                                        v-model="c.editedAddress"
                                        type="text"
                                        class="mt-2 w-full rounded border border-gray-300 px-2 py-1 text-sm"
                                        :disabled="!c.selected"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- polling -->
                <div v-else-if="phase === 'polling'" class="py-8 text-center">
                    <p class="text-gray-700">
                        Обрабатываю {{ progress.processed }} / {{ progress.total || '…' }}
                    </p>
                    <div class="mx-auto mt-4 h-2 w-64 overflow-hidden rounded-full bg-gray-200">
                        <div
                            class="h-full bg-blue-500 transition-all"
                            :style="{
                width: progress.total
                  ? Math.min(100, (progress.processed / progress.total) * 100) + '%'
                  : '5%',
              }"
                        />
                    </div>
                    <p class="mt-3 text-xs text-gray-400">Можно закрыть окно позже — идёт в фоне</p>
                </div>

                <!-- готово -->
                <div v-else-if="phase === 'done'" class="py-10 text-center">
                    <p class="text-lg font-medium text-emerald-600">Готово</p>
                    <p class="mt-1 text-sm text-gray-500">Данные сессии обновлены</p>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t px-5 py-4">
                <template v-if="phase === 'review'">
                    <button @click="close" class="rounded px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">
                        Отмена
                    </button>
                    <button
                        @click="submitRetry"
                        :disabled="selectedCount === 0"
                        class="rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-40"
                    >
                        Подтвердить и запустить ({{ selectedCount }})
                    </button>
                </template>

                <template v-else-if="phase === 'submitting'">
                    <button disabled class="rounded bg-blue-400 px-4 py-2 text-sm text-white">
                        Запускаю…
                    </button>
                </template>

                <template v-else-if="phase === 'polling'">
                    <button @click="close" class="rounded px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">
                        Скрыть
                    </button>
                </template>

                <template v-else-if="phase === 'done'">
                    <button @click="finish" class="rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700">
                        Обновить страницу
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>
