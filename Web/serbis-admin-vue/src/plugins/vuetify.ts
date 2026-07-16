/**
 * plugins/vuetify.ts
 *
 * Framework documentation: https://vuetifyjs.com`
 */

// Composables
import { createVuetify } from 'vuetify'
// Styles
import '@mdi/font/css/materialdesignicons.css'

import 'vuetify/styles'

// https://vuetifyjs.com/en/introduction/why-vuetify/#feature-guides
export default createVuetify({
  theme: {
    defaultTheme: 'light',
    themes: {
      light: {
        dark: false,
        colors: {
          primary: '#2E8B75',
          secondary: '#0A2620',
          background: '#F8FAFC',
          surface: '#FFFFFF',
          success: '#2E8B75',
          warning: '#F57C00',
          error: '#D32F2F',
          info: '#1976D2',
        },
      },
      dark: {
        dark: true,
        colors: {
          primary: '#34C39A',
          secondary: '#0A2620',
          background: '#0B1220',
          surface: '#131B2E',
          success: '#34C39A',
          warning: '#F5A524',
          error: '#F16565',
          info: '#4F9EF8',
        },
      },
    },
  },
})
