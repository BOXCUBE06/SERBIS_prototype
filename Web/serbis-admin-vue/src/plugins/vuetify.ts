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
          // #297A67 rather than the lighter #2E8B75: white-on-primary needs
          // 4.5:1 for AA and #2E8B75 only reaches 4.15:1. Keep success in step
          // with primary — they are meant to read as the same green.
          primary: '#297A67',
          // For text drawn on a tint of primary rather than on the surface.
          // Primary on rgba(primary, 0.14) over white is only 4.25:1, which
          // fails AA for anything short of large text; this reaches 6.61:1.
          'primary-strong': '#1B5B4B',
          secondary: '#0A2620',
          background: '#F8FAFC',
          surface: '#FFFFFF',
          success: '#297A67',
          warning: '#F57C00',
          error: '#D32F2F',
          // The same problem as primary-strong, on the error ramp. Error text on
          // rgba(error, 0.1) over white is 4.28:1 and fails AA; this is 5.62:1.
          // On the plain surface #D32F2F is 4.98:1 and stays as it is.
          'error-strong': '#B3261E',
          info: '#1976D2',
        },
      },
      dark: {
        dark: true,
        colors: {
          primary: '#34C39A',
          // Already 6.00:1 on the same tint over the dark surface, so the dark
          // theme needs no separate value — the key exists to keep the CSS the
          // same in both themes.
          'primary-strong': '#34C39A',
          secondary: '#0A2620',
          background: '#0B1220',
          surface: '#131B2E',
          success: '#34C39A',
          warning: '#F5A524',
          error: '#F16565',
          // Already 4.93:1 on the same tint over the dark surface, so the dark
          // theme keeps its own error and the key exists only to let the CSS be
          // written once — the same arrangement primary-strong uses.
          'error-strong': '#F16565',
          info: '#4F9EF8',
        },
      },
    },
  },
})
