<template>
  <div class="login-page d-flex align-center justify-center">
    <!-- Bleed wedge: sits behind the card and runs past its bottom-right, so
         the design reads as layers rather than a flat split. Decorative. -->
    <div class="bleed-wedge" aria-hidden="true"></div>

    <div class="login-card">

      <!-- Left Side -->
      <div class="brand-panel d-flex flex-column align-center justify-center pa-8">
        <div class="d-flex flex-column align-center text-center">
          <img
            src="@/assets/mdrrmo_logo.jpg"
            alt="MDRRMO Echague logo"
            class="mb-5 brand-seal"
          />
          <!-- This panel is a fixed off-white in both themes, so its text must
               stay dark. Theme tokens (text-high-emphasis) resolve to white in
               dark mode and make the title vanish against the light half. -->
          <h1 class="text-h3 font-weight-black text-grey-darken-4 mb-2" style="letter-spacing: 6px; line-height: 1.2;">
            SERBIS
          </h1>
          <div class="brand-subtitle">
            MDRRMO Echague Disaster Communication<br>&amp; Service Coordination System
          </div>
        </div>
      </div>

      <!-- Right Side -->
      <div class="form-panel d-flex flex-column justify-center pa-8">
        <div class="form-block">
          <!-- Password step. Unchanged apart from handleLogin now branching
               into the MFA step on a 403/mfa_required instead of always
               storing a token — see script setup. -->
          <v-form v-if="step === 'password'" @submit.prevent="handleLogin" class="w-100">

            <!-- Session-expiry notice. Not an error — the admin did nothing
                 wrong — so it must not look like a failed sign-in. Without it,
                 an expired token drops the visitor here with no explanation. -->
            <v-alert
              v-if="sessionExpired"
              role="status"
              variant="flat"
              rounded="lg"
              density="comfortable"
              class="mb-4"
              closable
              @click:close="sessionExpired = false"
              style="background-color: rgba(245, 165, 36, 0.15); border: 1px solid rgba(245, 165, 36, 0.4);"
            >
              <span class="text-body-2 font-weight-medium" style="color: #ffe0b2;">
                Your session expired. Sign in again to continue.
              </span>
            </v-alert>

            <!-- Error Alert. role="alert" so a failed login is announced;
                 without it the only failure signal is visual. -->
            <v-alert
  v-if="errorMessage"
  role="alert"
  type="error"
  variant="flat"
  rounded="lg"
  density="comfortable"
  class="mb-4"
  closable
  @click:close="errorMessage = ''"
  style="background-color: rgba(211, 47, 47, 0.15); border: 1px solid rgba(211, 47, 47, 0.4);"
>
  <span class="text-body-2 font-weight-medium" style="color: #ffcdd2;">
    {{ errorMessage }}
  </span>
</v-alert>

            <label for="login-username" class="d-block text-body-2 text-white mb-2 font-weight-medium">Username</label>
            <v-text-field
              id="login-username"
              v-model="credentials.username"
              type="text"
              placeholder="e.g. juan.delacruz"
              autocapitalize="none"
              spellcheck="false"
              variant="solo"
              bg-color="white"
              density="comfortable"
              rounded="md"
              hide-details
              elevation="0"
              autofocus
              autocomplete="username"
              class="mb-5 flat-input"
            ></v-text-field>

            <label for="login-password" class="d-block text-body-2 text-white mb-2 font-weight-medium">Password</label>
            <v-text-field
              id="login-password"
              v-model="credentials.password"
              :type="showPassword ? 'text' : 'password'"
              placeholder="********"
              variant="solo"
              bg-color="white"
              density="comfortable"
              rounded="md"
              hide-details
              elevation="0"
              autocomplete="current-password"
              class="mb-4 flat-input"
            >
              <template #append-inner>
                <v-btn
                  :icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
                  :aria-label="showPassword ? 'Hide password' : 'Show password'"
                  :aria-pressed="showPassword"
                  variant="text"
                  density="comfortable"
                  size="small"
                  color="grey-darken-1"
                  @click="showPassword = !showPassword"
                ></v-btn>
              </template>
            </v-text-field>

            <!-- No "Forgot password?" link: staff usernames are not mailboxes, so
                 nothing can be emailed. Another admin resets it from Staff
                 Accounts; the note under the button says so. -->
            <div class="d-flex align-center mb-5">
              <!-- Was "for 30 days", which stopped being true when admin tokens
                   gained an 8-hour server-side TTL (audit #30): the server ends
                   the session first regardless of this box. It still decides how
                   long a dead token sits in localStorage on a shared desk. -->
              <v-checkbox
                v-model="rememberMe"
                label="Keep me signed in on this device"
                density="compact"
                hide-details
                class="custom-checkbox"
              ></v-checkbox>
            </div>

            <!-- Fixed #297A67 rather than color="primary". This card is pinned
                 light in both themes (same convention as the panels), and the
                 primary token resolves to #34C39A in dark, which Vuetify pairs
                 with black text. The button has to keep white text on the dark
                 panel, so the value is pinned. #297A67 is the light primary
                 value, not a new green. White on it measures 5.15:1. -->
            <v-btn
              type="submit"
              color="#297A67"
              block
              height="52"
              rounded="md"
              elevation="0"
              class="text-none font-weight-bold text-body-1 text-white"
              :loading="loading"
              :disabled="lockoutSeconds > 0"
            >
              {{ lockoutSeconds > 0 ? `LOCKED — ${lockoutSeconds}s` : 'SIGN IN' }}
            </v-btn>

            <p class="reset-note text-body-2 text-center mt-4 mb-0">
              Ask another admin to reset your password.
            </p>

          </v-form>

          <!-- MFA step. No token exists yet — handleLogin never called
               setToken for the mfa_required branch — so the router guard
               needs no changes: this whole page still looks unauthenticated
               to it until handleMfaSubmit succeeds. -->
          <v-form v-else @submit.prevent="handleMfaSubmit" class="w-100">
            <v-alert
              v-if="mfaError"
              role="alert"
              type="error"
              variant="flat"
              rounded="lg"
              density="comfortable"
              class="mb-4"
              closable
              @click:close="mfaError = ''"
              style="background-color: rgba(211, 47, 47, 0.15); border: 1px solid rgba(211, 47, 47, 0.4);"
            >
              <span class="text-body-2 font-weight-medium" style="color: #ffcdd2;">
                {{ mfaError }}
              </span>
            </v-alert>

            <!-- The QR only ever appears once per admin — see the code
                 comment on enrollment in AuthController::adminLogin. A second
                 login after enrollment sticks skips straight to the plain
                 code prompt below. -->
            <template v-if="enrollmentRequired">
              <p class="text-body-2 text-white mb-3">
                Scan this with Google Authenticator, Authy, or any TOTP app, then enter the 6-digit code it shows.
              </p>
              <div class="d-flex justify-center mb-4">
                <img :src="qrCodeDataUri" alt="Authenticator enrollment QR code" class="mfa-qr" />
              </div>
            </template>
            <p v-else class="text-body-2 text-white mb-4">
              Enter the 6-digit code from your authenticator app.
            </p>

            <v-otp-input
              v-model="mfaCode"
              length="6"
              class="mb-4"
              :disabled="mfaLoading"
              @finish="handleMfaSubmit"
            ></v-otp-input>

            <v-btn
              type="submit"
              color="#297A67"
              block
              height="52"
              rounded="md"
              elevation="0"
              class="text-none font-weight-bold text-body-1 text-white"
              :loading="mfaLoading"
              :disabled="mfaCode.length !== 6"
            >
              VERIFY
            </v-btn>

            <v-btn
              variant="text"
              block
              class="text-none text-white mt-2"
              @click="step = 'password'"
            >
              Back to sign in
            </v-btn>
          </v-form>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { ref, reactive, watch, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { setToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'

const router = useRouter()
const route = useRoute()

// Set by the 401 interceptor when a token expires mid-session (see
// composables/apiSession.ts), never by a failed sign-in.
const sessionExpired = ref(route.query.expired === '1')
const loading = ref(false)
const rememberMe = ref(false)
const showPassword = ref(false)
const errorMessage = ref('')
const lockoutSeconds = ref(0)
let lockoutTimer = null

// The login route is throttled at 5/min per username+IP (AppServiceProvider).
// Count the lockout down rather than leaving a dead button.
const startLockout = (seconds) => {
  clearInterval(lockoutTimer)
  lockoutSeconds.value = seconds
  lockoutTimer = setInterval(() => {
    lockoutSeconds.value--
    if (lockoutSeconds.value <= 0) {
      clearInterval(lockoutTimer)
      errorMessage.value = ''
    }
  }, 1000)
}

onUnmounted(() => clearInterval(lockoutTimer))

const credentials = reactive({
  username: '',
  password: ''
})

// MFA step. challengeId/qrCodeDataUri/enrollmentRequired come straight off
// the mfa_required response — nothing is derived or guessed client-side.
const step = ref('password')
const mfaChallengeId = ref('')
const mfaCode = ref('')
const qrCodeDataUri = ref('')
const enrollmentRequired = ref(false)
const mfaError = ref('')
const mfaLoading = ref(false)

// The throttle bucket is keyed per username, so a lockout on one says nothing
// about another. Editing the username releases the button — a typo'd name
// must not lock the account the user actually meant.
watch(() => credentials.username, () => {
  if (lockoutSeconds.value > 0) {
    clearInterval(lockoutTimer)
    lockoutSeconds.value = 0
    errorMessage.value = ''
  }
})

const handleLogin = async () => {
  loading.value = true
  errorMessage.value = ''

  try {
    const response = await fetch(`${API_BASE}/admin/login`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(credentials)
    })

    const data = await response.json()

    if (response.ok) {
      setToken(data.token, rememberMe.value)
      // A temporary password gets no further than the page that replaces it.
      router.push(data.user?.must_change_password ? '/change-password' : '/')
    } else if (response.status === 401) {
      errorMessage.value = 'Invalid username or password. Please try again.'
    } else if (response.status === 403 && data.code === 'mfa_required') {
      // Password proven. No token yet — that only happens once handleMfaSubmit
      // succeeds — so nothing here needs the router guard's attention.
      mfaChallengeId.value = data.challenge_id
      enrollmentRequired.value = !!data.enrollment_required
      qrCodeDataUri.value = data.qr_code || ''
      mfaCode.value = ''
      mfaError.value = ''
      step.value = 'mfa'
    } else if (response.status === 403) {
      // A deactivated account (audit #29). The server's own wording is shown
      // rather than the generic fallback: the credentials were correct, so
      // "something went wrong" sends a former employee to chase a fault that
      // does not exist, and "invalid password" sends them to reset a password
      // that was never the problem.
      errorMessage.value = data.message || 'This account is no longer active. Contact an MDRRMO admin.'
    } else if (response.status === 429) {
      const retryAfter = Number.parseInt(response.headers.get('Retry-After'), 10)
      const wait = Number.isNaN(retryAfter) ? 60 : retryAfter
      startLockout(wait)
      errorMessage.value = `Too many sign-in attempts. Wait ${wait} seconds, then try again.`
    } else if (response.status === 422) {
      // Laravel validation errors
      const errors = data.errors
      if (errors) {
        errorMessage.value = Object.values(errors).flat().join(' ')
      } else {
        errorMessage.value = data.message || 'Invalid input. Please check your details.'
      }
    } else {
      errorMessage.value = 'Something went wrong. Please try again later.'
    }
  } catch {
    errorMessage.value = 'Network error. Please check your connection.'
  } finally {
    loading.value = false
  }
}

const handleMfaSubmit = async () => {
  if (mfaCode.value.length !== 6) return

  mfaLoading.value = true
  mfaError.value = ''

  try {
    const response = await fetch(`${API_BASE}/admin/login/verify`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        challenge_id: mfaChallengeId.value,
        code: mfaCode.value
      })
    })

    const data = await response.json()

    if (response.ok) {
      setToken(data.token, rememberMe.value)
      router.push('/')
    } else if (response.status === 422 && data.code === 'mfa_challenge_expired') {
      // The 5-minute challenge is gone. Nothing left to verify against —
      // back to the password step rather than a code field that can never
      // succeed.
      step.value = 'password'
      errorMessage.value = data.message || 'That login attempt expired. Please sign in again.'
    } else if (response.status === 422) {
      mfaError.value = data.message || 'That code is not right. Try again.'
      mfaCode.value = ''
    } else if (response.status === 429) {
      // The challenge is invalidated server-side once this fires — same as
      // an expired one, there is nothing left to submit a code against.
      step.value = 'password'
      errorMessage.value = data.message || 'Too many wrong codes. Please sign in again.'
    } else {
      mfaError.value = 'Something went wrong. Please try again.'
    }
  } catch {
    mfaError.value = 'Network error. Please check your connection.'
  } finally {
    mfaLoading.value = false
  }
}
</script>

<style scoped>
/* min-height, never height: the card must be able to push the page taller and
   scroll. An earlier `height: 100vh` on an `overflow-hidden` container clipped
   SIGN IN out of reach below ~665px tall — 259px off-screen in landscape, with
   no way to scroll to it. Every height here is a floor, not a cap. */
.login-page {
  position: relative;
  min-height: 100dvh;
  width: 100%;
  padding: 4vh 3vw;
  background: #EDF1F0;
}

@supports not (height: 100dvh) {
  .login-page { min-height: 100vh; }
}

/* The card's seam and this wedge are one continuous line, not two parallel
   ones. That is why both stops are exactly 50%.

   A gradient's stop line is measured along an axis centred on its own box, so
   a 50% stop always lands on that box's centre. This wedge is inset:0 on
   .login-page and the card is centred within it, so the two boxes share a
   centre — same angle + same centre point = the same line, at every viewport
   size, whatever the card's max-width or content height do.

   Anything other than 50% breaks it: the stop would sit (f - 0.5) x gradient-
   line-length away from each centre, and those lengths differ because the boxes
   differ, so the two lines would separate by an amount that changes with the
   window. That was the step. */
.bleed-wedge {
  position: absolute;
  inset: 0;
  background: linear-gradient(105deg, transparent 50%, #0E352D 50%);
  pointer-events: none;
}

.login-card {
  position: relative;
  z-index: 1;
  display: flex;
  /* 78vw, not 78%: a percentage here resolves against .login-page's content
     box, which the 3vw side padding has already shrunk to 94vw — so 78% only
     ever reached 73.3vw and the spec's 75-80% was unreachable. 78vw + the 6vw
     of padding still leaves room, so nothing overflows.

     The cap stops the card sprawling on ultrawides (at 2560 it would otherwise
     be ~2000px, stranding the form far right). 1500px keeps it inactive at
     1920 and below, so those sizes get the full 78%. */
  width: 78vw;
  max-width: 1500px;
  min-height: 70dvh;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 28px 64px rgba(2, 20, 16, 0.32);
  /* 105deg leans the seam 15deg off plumb (90deg would be dead vertical).
     Stop at 50% so this lands on the card's centre — which is also the page's
     centre, and therefore exactly on the wedge's line. See .bleed-wedge. */
  background: linear-gradient(105deg, #FAFAF8 50%, #113F36 50%);
}

@supports not (height: 100dvh) {
  .login-card { min-height: 70vh; }
}

/* 50/50 to match the seam. The seam is diagonal, so it sweeps ~±85px either
   side of the 50% mark over the card's height — each panel's content is
   centred/right-aligned well clear of that sweep. */
.brand-panel {
  flex: 0 0 50%;
}

/* Centred, which puts the block on the usable dark area's centre rather than
   the panel rectangle's — the two coincide here.

   The dark area is a trapezoid: the seam cuts into its left edge, narrow at the
   top and wide at the bottom. Its centre at height y is (seamX(y) + cardRight)/2.
   Averaged over the block, the seam's lean cancels, because the block is
   vertically centred and so its mid-line sits exactly on the card's centre —
   where the seam crosses the card's mid-x. That leaves (cardMidX + cardRight)/2,
   which is the panel rectangle's centre. So centring here is centring in the
   usable space. The brand panel measures 0.0px off for the same reason.

   This only holds while the block stays vertically centred; if it ever moves
   off-centre the lean stops cancelling and this needs a real offset. */
.form-panel {
  flex: 0 0 50%;
  align-items: center;
}

.form-block {
  width: 100%;
  max-width: 380px;
}

/* The seal is a JPG on a pure-white background, and the panel is off-white, so
   it landed as a visible white disc. multiply drops the white to the panel
   colour instead of cropping it to a circle — which only ever worked because
   the old panel happened to be pure white too. */
.brand-seal {
  width: 180px;
  height: 180px;
  object-fit: contain;
  mix-blend-mode: multiply;
}

.brand-subtitle {
  max-width: 340px;
  font-size: 0.8125rem;
  font-style: italic;
  line-height: 1.7;
  color: #4A4A4A;
}

@media (max-width: 959px) {
  /* Stacked, the panels break where the content ends, but a single diagonal
     seam sits at a fixed percentage and cannot track that. The brand subtitle
     used to spill past it onto the green at 2.55:1 — dark grey on dark green.
     Each panel carries its own background so text always sits on the half it
     was coloured for. */
  .login-page {
    padding: 0;
  }

  .bleed-wedge {
    display: none;
  }

  .login-card {
    flex-direction: column;
    width: 100%;
    max-width: none;
    min-height: 100dvh;
    border-radius: 0;
    box-shadow: none;
    background: #113F36;
  }

  .brand-panel,
  .form-panel {
    flex: 0 0 auto;
  }

  .brand-panel {
    background: #FAFAF8;
  }

  .form-panel {
    background: #113F36;
    align-items: center;
  }
}

.flat-input :deep(.v-field) {
  box-shadow: none !important;
  border-radius: 6px;
}

/* The password-manager autofill buttons are deliberately left visible — admins
   should be able to use a manager here. These fields previously hid them, and
   hid every .v-icon with them, which also silently swallowed the show/hide
   password toggle. */

.custom-checkbox :deep(.v-label) {
  color: white !important;
  opacity: 1 !important;
  font-size: 0.875rem !important;
}

.custom-checkbox :deep(.v-selection-control__input > .v-icon) {
  color: white !important;
}

/* On the dark half of the card, so it needs light text. 0.85 white on #113F36
   is well above the AA floor. */
.reset-note {
  color: rgba(255, 255, 255, 0.85);
}

.hover-underline:hover {
  text-decoration: underline !important;
}

/* White backing plate: the QR is an SVG rendered on a transparent background
   (BaconQrCode's SvgImageBackEnd), and this form sits on the dark half of the
   card. Without it the code's light modules would vanish into the panel. */
.mfa-qr {
  width: 180px;
  height: 180px;
  background: #ffffff;
  border-radius: 8px;
  padding: 12px;
}
</style>