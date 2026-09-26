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
  // Fade and a 0.98 scale (styles/motion.css), not the default grow-from-activator.
  defaults: {
    VDialog: { transition: 'dialog-soft' },
    VSnackbar: { transition: 'snack-up' },
  },
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
          // Success is the same green as primary, so it borrows the same fix.
          // #297A67 on rgba(success, 0.14) over white is 4.27:1; this is 6.57:1.
          'success-strong': '#1B5B4B',
          warning: '#F57C00',
          // The worst of the ramp: #F57C00 on its own 14% tint is 2.36:1 — the
          // token is a fill colour, never a text colour, in the light theme.
          // 5.94:1. Already proved on UsersView's pending pill, which hardcoded
          // this same value before the token existed.
          'warning-strong': '#8A4B00',
          error: '#D32F2F',
          // The same problem as primary-strong, on the error ramp. Error text on
          // rgba(error, 0.1) over white is 4.28:1 and fails AA; this is 5.62:1.
          // On the plain surface #D32F2F is 4.98:1 and stays as it is.
          'error-strong': '#B3261E',
          info: '#1976D2',
          // #1976D2 on rgba(info, 0.14) over white is 3.84:1. This is 5.41:1 —
          // the shallowest darkening on the ramp that clears AA, kept close to
          // the token so Responding still reads as the same blue.
          'info-strong': '#155FA8',
          // Neutral counterpart to the status colours, for things that are
          // information rather than good or bad (the dashboard's Ongoing Trips
          // tile, the Filed series). 4.76:1 on the plain surface.
          slate: '#64748B',
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
          // #4F9EF8 until this pass: Vuetify's own v-alert tonal variant reads
          // `info` directly as `currentColor` for its text (not `info-strong`
          // — that token is our own, Vuetify doesn't know about it), so a
          // bright, fully-saturated sky blue sat as vivid full-opacity text on
          // a near-black card and read as glare, not just a tinted background.
          // Desaturated to a muted steel blue at the same lightness band:
          // still 6.05:1 on the plain surface and 5.05:1 on its own ~12%
          // tonal tint over #131B2E (VAlert.css's --v-activated-opacity),
          // clearly above AA with room to spare, and calm enough to sit next
          // to the rest of the dark palette.
          info: '#7C9CC4',
          // Every dark token already clears AA on its own 10% tint over #131B2E
          // — success 6.48:1, warning 7.08:1, info 5.05:1, error 4.95:1 — so the
          // strong keys are aliases here. They exist only so the pill CSS can be
          // written once and work in both themes, the same arrangement
          // primary-strong already uses.
          'success-strong': '#34C39A',
          'warning-strong': '#F5A524',
          'error-strong': '#F16565',
          'info-strong': '#7C9CC4',
          // 6.7:1 on the dark surface.
          slate: '#94A3B8',
        },
      },
    },
  },
})
