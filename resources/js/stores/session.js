import { defineStore } from 'pinia'
import axios from 'axios'

export const useSessionStore = defineStore('session', {
    state: () => ({
        session: null,
        districts: null,
        points: null,
        pairs: null,
        coverage: null,
        selectedDistrict: null,
        report: null
    }),

    actions: {
        async loadSession(id) {
            const { data } = await axios.get(`/api/sessions/${id}`)
            this.session = data
        },

        async loadDistricts(id) {
            const { data } = await axios.get(`/api/sessions/${id}/export/districts`)
            this.districts = data
        },

        async loadPoints(id) {
            const { data } = await axios.get(`/api/sessions/${id}/export/points`)
            this.points = data
        },

        selectDistrict(properties) {
            this.selectedDistrict = properties
        },

        clearSelectedDistrict() {
            this.selectedDistrict = null
        },

        async loadPairs(id) {
            const { data } = await axios.get(`/api/sessions/${id}/export/pairs`)
            this.pairs = data
        },

        async loadCoverage(id) {
            const { data } = await axios.get(`/api/sessions/${id}/coverage`)
            this.coverage = data
        },

        async loadReport(id) {
            const { data } = await axios.get(`/api/sessions/${id}/report`)
            this.report = data
        },


    }
})
