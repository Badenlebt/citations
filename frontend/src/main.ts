import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import { installI18n } from './i18n'
import './scss/main.scss'

const app = createApp(App)
app.use(router)
installI18n(app)
app.mount('#app')
