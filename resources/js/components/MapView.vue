<script setup>
import { onMounted, onUnmounted, watch, ref } from 'vue'
import maplibregl from 'maplibre-gl'
import 'maplibre-gl/dist/maplibre-gl.css'
import { useSessionStore } from '@/stores/session.js'

const store = useSessionStore()
const mapContainer = ref(null)
const showDivergence = ref(false)
const showOutliers   = ref(false)
const showSingle     = ref(false)
const legendOpen     = ref(true)
let map = null
let popup = null

const MAX_VALID_DISTANCE = 1000

onMounted(() => {
    map = new maplibregl.Map({
        container: mapContainer.value,
        style: 'https://tiles.openfreemap.org/styles/liberty',
        center: [92.8932, 56.0184],
        zoom: 11,
    })

    map.addControl(new maplibregl.NavigationControl(), 'top-right')
    map.on('load', () => { initLayers() })

    popup = new maplibregl.Popup({
        closeButton: false,
        closeOnClick: false,
        maxWidth: '320px',
    })
})

onUnmounted(() => {
    popup?.remove()
    map?.remove()
})

function formatDistance(meters) {
    if (meters === null || meters === undefined) return '—'
    if (meters >= 1000) return (meters / 1000).toFixed(1) + ' км'
    return Math.round(meters) + ' м'
}

function parseDistance(val) {
    if (val === null || val === 'null' || val === undefined) return null
    const f = parseFloat(val)
    return isNaN(f) ? null : f
}

function boolProp(val) {
    return val === true || val === 'true'
}

function buildMergedPopup(props) {
    const nName = props.nominatim_display_name && props.nominatim_display_name !== 'null'
        ? props.nominatim_display_name : null
    const pName = props.photon_display_name && props.photon_display_name !== 'null'
        ? props.photon_display_name : null

    return `
        <div style="font-size:13px;line-height:1.6">
            <b>${props.raw_address}</b>
            <div style="margin-top:6px;padding:5px 8px;background:#f0fdf4;border-radius:4px;color:#16a34a;font-size:12px">
                ✓ Оба геокодера указали на одну точку
            </div>
            ${nName ? `<div style="margin-top:6px;font-size:11px;color:#6b7280"><b>Nominatim нашёл:</b> ${nName}</div>` : ''}
            ${pName ? `<div style="margin-top:2px;font-size:11px;color:#6b7280"><b>Photon нашёл:</b> ${pName}</div>` : ''}
        </div>
    `
}

function buildPointPopup(props) {
    const providerName = props.type === 'nominatim' ? 'Nominatim' : 'Photon'
    const distance = parseDistance(props.distance_meters)
    const isOutlier = boolProp(props.is_outlier)

    const displayName = props.display_name && props.display_name !== 'null'
        ? `<div style="margin-top:2px;font-size:11px;color:#6b7280;word-break:break-word">
               <b>${providerName} нашёл:</b> ${props.display_name}
           </div>`
        : ''

    const outlierNote = isOutlier
        ? `<div style="margin-top:6px;padding:5px 8px;background:#fef9c3;border-radius:4px;color:#854d0e;font-size:11px">
               ⚠ Возможная ошибка геокодера — он мог найти объект с похожим названием в другом месте.
               Сравните адреса Nominatim и Photon чтобы определить причину расхождения.
           </div>`
        : ''

    return `
        <div style="font-size:13px;line-height:1.6">
            <b>Искомый адрес:</b> ${props.raw_address}
            ${displayName}
            <div style="margin-top:6px">
                Расхождение: <b>${formatDistance(distance)}</b>
            </div>
            ${outlierNote}
        </div>
    `
}

function buildLinePopup(props) {
    const distance = parseDistance(props.distance_meters)
    const isOutlier = boolProp(props.is_outlier)

    const outlierNote = isOutlier
        ? `<div style="margin-top:6px;padding:5px 8px;background:#fef9c3;border-radius:4px;color:#854d0e;font-size:11px">
               ⚠ Расхождение ${formatDistance(distance)} — адрес исключён из статистики.<br>
               Наведите на точки геокодеров чтобы сравнить что каждый нашёл.
           </div>`
        : ''

    return `
        <div style="font-size:13px;line-height:1.6">
            <b>${props.raw_address}</b><br>
            Расхождение: <b>${formatDistance(distance)}</b>
            ${outlierNote}
        </div>
    `
}

function buildSinglePopup(props) {
    const districtName = props.district_name && props.district_name !== 'null'
        ? `<div style="font-size:11px;color:#6b7280;margin-top:2px">${props.district_name}</div>`
        : ''

    return `
        <div style="font-size:13px;line-height:1.6">
            <b>${props.raw_address}</b>
            ${districtName}
            <div style="margin-top:6px;padding:5px 8px;background:#f9fafb;border-radius:4px;color:#6b7280;font-size:12px">
                Только один геокодер нашёл адрес — расхождение не вычислено
            </div>
        </div>
    `
}

function initLayers() {
    map.addSource('districts', { type: 'geojson', data: store.districts })
    map.addSource('points',    { type: 'geojson', data: store.points })
    map.addSource('pairs',     { type: 'geojson', data: store.pairs })

    map.addLayer({
        id: 'districts-fill',
        type: 'fill',
        source: 'districts',
        paint: {
            'fill-color': [
                'case',
                ['==', ['get', 'avg_distance_meters'], null], '#e5e7eb',
                [
                    'interpolate', ['linear'],
                    ['get', 'avg_distance_meters'],
                    0,   '#dbeafe',
                    50,  '#fef08a',
                    200, '#fca5a5',
                    500, '#dc2626',
                ],
            ],
            'fill-opacity': 0.65,
        },
    })

    map.addLayer({
        id: 'districts-outline',
        type: 'line',
        source: 'districts',
        paint: { 'line-color': '#6b7280', 'line-width': 1 },
    })

    map.addLayer({
        id: 'districts-label',
        type: 'symbol',
        source: 'districts',
        layout: {
            'text-field': ['get', 'name'],
            'text-size': 12,
            'text-font': ['Open Sans Regular'],
        },
        paint: {
            'text-color': '#1f2937',
            'text-halo-color': '#ffffff',
            'text-halo-width': 1,
        },
    })

    map.addLayer({
        id: 'pairs-lines-normal',
        type: 'line',
        source: 'pairs',
        filter: ['all', ['==', ['get', 'type'], 'line'], ['!=', ['get', 'is_outlier'], true], ['!=', ['get', 'is_outlier'], 'true']],
        layout: { visibility: 'none' },
        paint: {
            'line-color': ['case', ['get', 'is_problem'], '#dc2626', '#6b7280'],
            'line-width': ['interpolate', ['linear'], ['coalesce', ['get', 'distance_meters'], 0], 0, 1, 100, 2, 500, 4],
            'line-opacity': 0.85,
            'line-dasharray': [2, 1],
        },
    })

    map.addLayer({
        id: 'pairs-lines-outlier',
        type: 'line',
        source: 'pairs',
        filter: ['all', ['==', ['get', 'type'], 'line'], ['any', ['==', ['get', 'is_outlier'], true], ['==', ['get', 'is_outlier'], 'true']]],
        layout: { visibility: 'none' },
        paint: { 'line-color': '#7c3aed', 'line-width': 2, 'line-opacity': 0.6, 'line-dasharray': [3, 2] },
    })

    map.addLayer({
        id: 'pairs-nominatim-normal',
        type: 'circle',
        source: 'pairs',
        filter: ['all', ['==', ['get', 'type'], 'nominatim'], ['!=', ['get', 'is_outlier'], true], ['!=', ['get', 'is_outlier'], 'true']],
        layout: { visibility: 'none' },
        paint: { 'circle-radius': 7, 'circle-color': '#2563eb', 'circle-stroke-width': 2, 'circle-stroke-color': '#ffffff', 'circle-translate': [-5, -5] },
    })

    map.addLayer({
        id: 'pairs-photon-normal',
        type: 'circle',
        source: 'pairs',
        filter: ['all', ['==', ['get', 'type'], 'photon'], ['!=', ['get', 'is_outlier'], true], ['!=', ['get', 'is_outlier'], 'true']],
        layout: { visibility: 'none' },
        paint: { 'circle-radius': 7, 'circle-color': '#f59e0b', 'circle-stroke-width': 2, 'circle-stroke-color': '#ffffff', 'circle-translate': [5, 5] },
    })

    map.addLayer({
        id: 'pairs-merged',
        type: 'circle',
        source: 'pairs',
        filter: ['==', ['get', 'type'], 'merged'],
        layout: { visibility: 'none' },
        paint: { 'circle-radius': 8, 'circle-color': '#16a34a', 'circle-stroke-width': 2, 'circle-stroke-color': '#ffffff' },
    })

    map.addLayer({
        id: 'pairs-nominatim-outlier',
        type: 'circle',
        source: 'pairs',
        filter: ['all', ['==', ['get', 'type'], 'nominatim'], ['any', ['==', ['get', 'is_outlier'], true], ['==', ['get', 'is_outlier'], 'true']]],
        layout: { visibility: 'none' },
        paint: { 'circle-radius': 7, 'circle-color': '#2563eb', 'circle-stroke-width': 2, 'circle-stroke-color': '#7c3aed', 'circle-opacity': 0.5, 'circle-translate': [-5, -5] },
    })

    map.addLayer({
        id: 'pairs-photon-outlier',
        type: 'circle',
        source: 'pairs',
        filter: ['all', ['==', ['get', 'type'], 'photon'], ['any', ['==', ['get', 'is_outlier'], true], ['==', ['get', 'is_outlier'], 'true']]],
        layout: { visibility: 'none' },
        paint: { 'circle-radius': 7, 'circle-color': '#f59e0b', 'circle-stroke-width': 2, 'circle-stroke-color': '#7c3aed', 'circle-opacity': 0.5, 'circle-translate': [5, 5] },
    })

    map.addLayer({
        id: 'points-single',
        type: 'circle',
        source: 'points',
        filter: ['==', ['get', 'distance_meters'], null],
        layout: { visibility: 'none' },
        paint: { 'circle-radius': 6, 'circle-color': '#9ca3af', 'circle-stroke-width': 2, 'circle-stroke-color': '#ffffff', 'circle-opacity': 0.75 },
    })

    map.on('click', 'districts-fill', (e) => {
        const props = e.features[0].properties
        store.selectDistrict({
            ...props,
            avg_distance_meters: props.avg_distance_meters !== 'null' ? props.avg_distance_meters : null,
            problem_rate: props.problem_rate !== 'null' ? props.problem_rate : null,
        })
    })
    map.on('mouseenter', 'districts-fill', () => { map.getCanvas().style.cursor = 'pointer' })
    map.on('mouseleave', 'districts-fill', () => { map.getCanvas().style.cursor = '' })

    ;['pairs-lines-normal', 'pairs-lines-outlier'].forEach(id => {
        map.on('mouseenter', id, (e) => {
            popup.setLngLat(e.lngLat).setHTML(buildLinePopup(e.features[0].properties)).addTo(map)
            map.getCanvas().style.cursor = 'pointer'
        })
        map.on('mouseleave', id, () => { popup.remove(); map.getCanvas().style.cursor = '' })
    })

    ;['pairs-nominatim-normal', 'pairs-photon-normal', 'pairs-nominatim-outlier', 'pairs-photon-outlier'].forEach(id => {
        map.on('mouseenter', id, (e) => {
            popup.setLngLat(e.features[0].geometry.coordinates).setHTML(buildPointPopup(e.features[0].properties)).addTo(map)
            map.getCanvas().style.cursor = 'pointer'
        })
        map.on('mouseleave', id, () => { popup.remove(); map.getCanvas().style.cursor = '' })
    })

    map.on('mouseenter', 'pairs-merged', (e) => {
        popup.setLngLat(e.features[0].geometry.coordinates).setHTML(buildMergedPopup(e.features[0].properties)).addTo(map)
        map.getCanvas().style.cursor = 'pointer'
    })
    map.on('mouseleave', 'pairs-merged', () => { popup.remove(); map.getCanvas().style.cursor = '' })

    map.on('mouseenter', 'points-single', (e) => {
        popup.setLngLat(e.features[0].geometry.coordinates).setHTML(buildSinglePopup(e.features[0].properties)).addTo(map)
        map.getCanvas().style.cursor = 'pointer'
    })
    map.on('mouseleave', 'points-single', () => { popup.remove(); map.getCanvas().style.cursor = '' })
}

const divergenceLayers = ['pairs-lines-normal', 'pairs-nominatim-normal', 'pairs-photon-normal', 'pairs-merged']
const outlierLayers    = ['pairs-lines-outlier', 'pairs-nominatim-outlier', 'pairs-photon-outlier']

watch(showDivergence, (val) => {
    if (!map) return
    divergenceLayers.forEach(id => map.setLayoutProperty(id, 'visibility', val ? 'visible' : 'none'))
})
watch(showOutliers, (val) => {
    if (!map) return
    outlierLayers.forEach(id => map.setLayoutProperty(id, 'visibility', val ? 'visible' : 'none'))
})
watch(showSingle, (val) => {
    if (!map) return
    map.setLayoutProperty('points-single', 'visibility', val ? 'visible' : 'none')
})

// ВАЖНО: addSource() в initLayers() выполняется один раз при первой загрузке карты.
// Без этих вотчеров источники карты навсегда остаются с данными на момент первой
// загрузки — после повторного поиска (RetryModal -> loadAll()) store.pairs/points/districts
// обновляются свежими данными с бэкенда, но карта их не видит, пока мы явно
// не вызовем setData() на соответствующем источнике. Это чинит и маркеры (pairs/points),
// и цвет хороплета (districts-fill читает avg_distance_meters из source 'districts').
watch(() => store.districts, (val) => {
    if (!map || !val) return
    const source = map.getSource('districts')
    if (source) source.setData(val)
})

watch(() => store.points, (val) => {
    if (!map || !val) return
    const source = map.getSource('points')
    if (source) source.setData(val)
})

watch(() => store.pairs, (val) => {
    if (!map || !val) return
    const source = map.getSource('pairs')
    if (source) source.setData(val)
})
</script>

<template>
    <div class="relative w-full h-full">

        <div ref="mapContainer" class="w-full h-full" />

        <!-- Переключатели слоёв -->
        <div class="absolute top-4 left-4 bg-white rounded-lg shadow px-4 py-3 flex flex-col gap-2 text-sm z-10">
            <p class="font-medium text-gray-700 text-xs uppercase tracking-wide mb-1">Слои</p>
            <label class="flex items-center gap-2 cursor-pointer text-gray-700">
                <input type="checkbox" v-model="showDivergence" class="accent-blue-600" />
                Расхождения
            </label>
            <label class="flex items-center gap-2 cursor-pointer text-gray-700">
                <input type="checkbox" v-model="showOutliers" class="accent-purple-600" />
                Выбросы (&gt;1 км)
            </label>
            <label class="flex items-center gap-2 cursor-pointer text-gray-700">
                <input type="checkbox" v-model="showSingle" class="accent-gray-500" />
                Один геокодер
            </label>
        </div>

        <!-- Легенда -->
        <div class="absolute bottom-8 left-4 bg-white rounded-lg shadow text-xs text-gray-600 z-10 overflow-hidden">

            <!-- Шапка легенды — всегда видна -->
            <button
                class="w-full flex items-center justify-between px-4 py-2.5 font-medium text-gray-700 hover:bg-gray-50 transition-colors"
                @click="legendOpen = !legendOpen"
            >
                <span>Легенда</span>
                <span class="text-gray-400 text-xs ml-4">{{ legendOpen ? '▲' : '▼' }}</span>
            </button>

            <!-- Содержимое легенды -->
            <Transition name="legend">
                <div v-if="legendOpen" class="px-4 pb-3">

                    <p class="font-medium mb-2 text-gray-500">Расхождение по району</p>
                    <div class="flex flex-col gap-1 mb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-3 rounded" style="background:#dbeafe"></span> 0–50 м
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-3 rounded" style="background:#fef08a"></span> 50–200 м
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-3 rounded" style="background:#fca5a5"></span> 200–500 м
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-3 rounded" style="background:#dc2626"></span> 500+ м
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-3 rounded bg-gray-200"></span> Нет данных
                        </div>
                    </div>

                    <p class="font-medium mb-2 text-gray-500">Точки</p>
                    <div class="flex flex-col gap-1 mb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-3.5 h-3.5 rounded-full border-2 border-white shadow-sm" style="background:#16a34a"></span>
                            Оба, расхождение 0
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-3.5 h-3.5 rounded-full border-2 border-white shadow-sm" style="background:#2563eb"></span>
                            Nominatim
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-3.5 h-3.5 rounded-full border-2 border-white shadow-sm" style="background:#f59e0b"></span>
                            Photon
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-3.5 h-3.5 rounded-full border-2 opacity-50 shadow-sm" style="background:#2563eb;border-color:#7c3aed"></span>
                            Выброс
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-3.5 h-3.5 rounded-full border-2 border-white shadow-sm" style="background:#9ca3af"></span>
                            Один геокодер
                        </div>
                    </div>

                    <p class="font-medium mb-2 text-gray-500">Линии</p>
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center gap-2">
                            <span class="w-6 border-t-2 border-dashed border-gray-500"></span> Норма
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-6 border-t-2 border-dashed border-red-500"></span> Проблема
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-6 border-t-2 border-dashed" style="border-color:#7c3aed"></span> Выброс
                        </div>
                    </div>

                </div>
            </Transition>
        </div>

    </div>
</template>

<style scoped>
.legend-enter-active, .legend-leave-active {
    transition: max-height 0.2s ease, opacity 0.2s ease;
    overflow: hidden;
}
.legend-enter-from, .legend-leave-to {
    max-height: 0;
    opacity: 0;
}
.legend-enter-to, .legend-leave-from {
    max-height: 400px;
    opacity: 1;
}
</style>
