/**
 * main.ts
 *
 * Bootstraps Vuetify and other plugins then mounts the App`
 */

// Composables
import { createApp } from 'vue'

// Plugins
import { registerPlugins } from '@/plugins'

// Components
import App from './App.vue'
import router from './router'
import { installSessionExpiryHandler } from '@/composables/apiSession'
import { installOverlayReposition } from '@/plugins/overlayReposition'

// Fonts: one variable face for the UI, mono only for transaction numbers (.mono).
import '@fontsource-variable/plus-jakarta-sans'
import '@fontsource/jetbrains-mono/400.css'
import '@fontsource/jetbrains-mono/500.css'
import '@/styles/motion.css'
import '@/styles/filter-bar.css'
import '@/styles/board-table.css'

const app = createApp(App)

registerPlugins(app)
app.use(router)
installSessionExpiryHandler(router)
installOverlayReposition()

app.mount('#app')
