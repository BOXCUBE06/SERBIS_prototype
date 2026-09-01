import { fileURLToPath, URL } from 'node:url'
import Vue from '@vitejs/plugin-vue'
import Fonts from 'unplugin-fonts/vite'
import { defineConfig, loadEnv } from 'vite'
import Vuetify, { transformAssetUrls } from 'vite-plugin-vuetify'

// Catch a missing API base while the build is running rather than in the
// browser. Without it the bundle still builds, `import.meta.env.VITE_API_BASE`
// inlines as undefined, and the panel white-screens on load — a deploy that
// looks green and is dead. Build only; `vite dev` has .env.development.
const requireApiBase = {
  name: 'serbis:require-api-base',
  apply: 'build',
  config (_config: unknown, { mode }: { mode: string }) {
    if (!loadEnv(mode, process.cwd(), 'VITE_').VITE_API_BASE) {
      throw new Error(
        'VITE_API_BASE is not set, so this build would point at nothing. Set it in '
        + 'the build environment (e.g. the host\'s variables) to the API root '
        + 'including /api — see .env.example.',
      )
    }
  },
} as const

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [
    requireApiBase,
    Vue({
      template: { transformAssetUrls },
    }),
    // https://github.com/vuetifyjs/vuetify-loader/tree/master/packages/vite-plugin#readme
    Vuetify({
      autoImport: true,
      styles: {
        configFile: 'src/styles/settings.scss',
      },
    }),
    Fonts({
      fontsource: {
        families: [
          {
            name: 'Roboto',
            weights: [100, 300, 400, 500, 700, 900],
            styles: ['normal', 'italic'],
          },
        ],
      },
    }),
  ],
  define: { 'process.env': {} },
  // Every route is a lazy import and vite-plugin-vuetify injects component
  // imports at transform time, so the dependency scanner cannot see which
  // Vuetify components a view needs until that page is first opened — it then
  // re-optimizes and forces a full page reload mid-session. Listing them here
  // gets them all bundled during startup instead. A component missing from this
  // list still works; it just costs one reload the first time it is rendered.
  optimizeDeps: {
    include: [
      'vuetify/components/VAlert',
      'vuetify/components/VApp',
      'vuetify/components/VAvatar',
      'vuetify/components/VBadge',
      'vuetify/components/VBtn',
      'vuetify/components/VBtnToggle',
      'vuetify/components/VCard',
      'vuetify/components/VCheckbox',
      'vuetify/components/VChip',
      'vuetify/components/VChipGroup',
      'vuetify/components/VDataTable',
      'vuetify/components/VDatePicker',
      'vuetify/components/VDialog',
      'vuetify/components/VDivider',
      'vuetify/components/VForm',
      'vuetify/components/VGrid',
      'vuetify/components/VIcon',
      'vuetify/components/VImg',
      'vuetify/components/VList',
      'vuetify/components/VMain',
      'vuetify/components/VMenu',
      'vuetify/components/VNavigationDrawer',
      'vuetify/components/VPagination',
      'vuetify/components/VProgressCircular',
      'vuetify/components/VProgressLinear',
      'vuetify/components/VRadio',
      'vuetify/components/VRadioGroup',
      'vuetify/components/VSelect',
      'vuetify/components/VSheet',
      'vuetify/components/VSkeletonLoader',
      'vuetify/components/VSlideGroup',
      'vuetify/components/VSnackbar',
      'vuetify/components/VTabs',
      'vuetify/components/VTextField',
      'vuetify/components/VTextarea',
      'vuetify/components/VTimePicker',
      'vuetify/components/VTooltip',
      'vuetify/components/VWindow',
    ],
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('src', import.meta.url)),
    },
    extensions: [
      '.js',
      '.json',
      '.jsx',
      '.mjs',
      '.ts',
      '.tsx',
      '.vue',
    ],
  },
  server: {
    port: 3000,
  },
})
