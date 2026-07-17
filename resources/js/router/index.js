import { createRouter, createWebHistory } from 'vue-router'
import UploadPage from '@/pages/UploadPage.vue'
import WaitingPage from '@/pages/WaitingPage.vue'
import ResultPage from '@/pages/ResultPage.vue'

export default createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/',                    component: UploadPage },
        { path: '/sessions/:id/waiting', component: WaitingPage },
        { path: '/sessions/:id/result',  component: ResultPage },
    ]
})
