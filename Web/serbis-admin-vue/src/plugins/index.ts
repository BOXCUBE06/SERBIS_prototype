/**
 * plugins/index.ts
 *
 * Automatically included in `./src/main.ts`
 */

// Types
import type { App } from 'vue'

// Plugins
import vuetify from './vuetify'
import { installInputFilter } from './inputFilter'

export function registerPlugins (app: App) {
  app.use(vuetify)
  installInputFilter(app)
}
