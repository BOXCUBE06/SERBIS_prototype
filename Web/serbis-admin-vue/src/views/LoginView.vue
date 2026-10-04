<template>
  <div class="login-page d-flex align-center justify-center">
    <!-- Bleed wedge: sits behind the card and runs past its bottom-right, so
         the design reads as layers rather than a flat split. Decorative. -->
    <div class="bleed-wedge" aria-hidden="true"></div>

    <div class="login-card">

      <!-- The dark half: a viewport-sized layer centred on the card, so its edge
           continues the page's diagonal (the Login board). Decorative. -->
      <div class="card-dark" aria-hidden="true"></div>

      <!-- Left Side -->
      <div class="brand-panel">
        <img
          src="@/assets/mdrrmo_logo.jpg"
          alt="MDRRMO Echague logo"
          class="brand-seal"
        />
        <div class="brand-text">
          <!-- This panel is a fixed off-white in both themes, so its text must
               stay dark. Theme tokens (text-high-emphasis) resolve to white in
               dark mode and make the title vanish against the light half. -->
          <h1 class="brand-title">SERBIS</h1>
          <p class="brand-subtitle">MDRRMO Echague Disaster Communication &amp; Service Coordination System</p>
        </div>
        <div class="brand-place">Echague, Isabela</div>
      </div>

      <!-- Right Side -->
      <div class="form-panel">
        <div class="form-block">
          <div class="form-head">
            <h2 class="form-title">Sign in</h2>
            <p class="form-sub">Staff access to the MDRRMO Echague panel.</p>
          </div>
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

            <div class="form-fields">
              <div>
                <label for="login-username" class="login-label">Username</label>
                <input
                  id="login-username"
                  v-model="credentials.username"
                  class="login-field"
                  type="text"
                  placeholder="e.g. juan.delacruz"
                  autocapitalize="none"
                  spellcheck="false"
                  autofocus
                  autocomplete="username"
                />
              </div>
              <div>
                <label for="login-password" class="login-label">Password</label>
                <div class="login-pw">
                  <input
                    id="login-password"
                    v-model="credentials.password"
                    class="login-field login-field--pw"
                    :type="showPassword ? 'text' : 'password'"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                  />
                  <button
                    type="button" class="login-eye"
                    :aria-label="showPassword ? 'Hide password' : 'Show password'"
                    :aria-pressed="showPassword"
                    @click="showPassword = !showPassword"
                  >
                    <v-icon size="20" aria-hidden="true">{{ showPassword ? 'mdi-eye-off-outline' : 'mdi-eye-outline' }}</v-icon>
                  </button>
                </div>
              </div>
              <!-- No "Forgot password?" link: staff usernames are not mailboxes, so
                   nothing can be emailed. Another admin resets it from Staff
                   Accounts; the note under the button says so. -->
              <!-- Was "for 30 days", which stopped being true when admin tokens
                   gained an 8-hour server-side TTL (audit #30): the server ends
                   the session first regardless of this box. It still decides how
                   long a dead token sits in localStorage on a shared desk. -->
              <label class="login-check">
                <input v-model="rememberMe" type="checkbox" />
                Keep me signed in on this device
              </label>
            </div>

            <!-- The Sign in button is #34C39A with dark text on this dark half in
                 both themes (the card is pinned, as the panels are). -->
            <div class="form-actions">
              <button type="submit" class="login-signin" :disabled="loading || lockoutSeconds > 0">
                <span v-if="loading" class="login-spin" aria-hidden="true"></span>
                {{ lockoutSeconds > 0 ? `LOCKED — ${lockoutSeconds}s` : 'Sign in' }}
              </button>
              <p class="login-helper">Forgot your password? Ask another admin to reset it.</p>
            </div>

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
              Enter the 6-digit code sent to <strong>{{ mfaSentTo }}</strong>.
              <span v-if="mfaDeliveryUnknown"> The text may take a minute; use Resend if it does not arrive.</span>
            </p>

            <v-otp-input
              v-model="mfaCode"
              length="6"
              class="mb-4"
              :disabled="mfaLoading"
              @finish="handleMfaSubmit"
            ></v-otp-input>

            <button type="submit" class="login-signin" :disabled="mfaLoading || mfaCode.length !== 6">
              <span v-if="mfaLoading" class="login-spin" aria-hidden="true"></span>
              Verify
            </button>

            <!-- Same 60-second wait the server enforces (resend_too_soon). -->
            <v-btn
              variant="text"
              block
              class="text-none text-white mt-2 login-link"
              :loading="resendLoading"
              :disabled="resendSeconds > 0"
              @click="handleResend"
            >
              {{ resendSeconds > 0 ? `Resend code in ${resendSeconds}s` : 'Resend code' }}
            </v-btn>

            <v-btn
              variant="text"
              block
              class="text-none text-white mt-2 login-link"
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
const mfaSentTo = ref('')
const mfaDeliveryUnknown = ref(false)
const resendLoading = ref(false)
const resendSeconds = ref(0)
let resendTimer = null

const startResendCountdown = (seconds) => {
  clearInterval(resendTimer)
  resendSeconds.value = Math.max(0, Number(seconds) || 0)
  if (resendSeconds.value === 0) return
  resendTimer = setInterval(() => {
    resendSeconds.value--
    if (resendSeconds.value <= 0) clearInterval(resendTimer)
  }, 1000)
}

onUnmounted(() => clearInterval(resendTimer))

// Shared by the first send and every resend: where the code went, how it went,
// and how long until another may be asked for.
const applyDelivery = (data) => {
  mfaSentTo.value = data.sent_to_masked || `your number ending ${data.sent_to || ''}`
  mfaDeliveryUnknown.value = data.delivery === 'unknown'
  startResendCountdown(data.retry_after)
}

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
      applyDelivery(data)
      step.value = 'mfa'
    } else if (response.status === 403) {
      // A deactivated account (audit #29), or phone_missing ("Ask a super
      // admin to add your mobile number") when two-step sign-in is on. The server's own wording is shown
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
    } else if (response.status === 503) {
      // sms_unavailable: the code could not be texted.
      errorMessage.value = data.message || 'We could not send the sign-in code. Try again in a minute.'
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
  // @finish and the form submit (Enter / VERIFY) can both fire for one code;
  // the second call would hit an already-used challenge and 422.
  if (mfaLoading.value || mfaCode.value.length !== 6) return

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
      router.push(data.user?.must_change_password ? '/change-password' : '/')
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

const handleResend = async () => {
  resendLoading.value = true
  mfaError.value = ''

  try {
    const response = await fetch(`${API_BASE}/admin/login/resend`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({ challenge_id: mfaChallengeId.value })
    })

    const data = await response.json()

    if (response.ok) {
      applyDelivery(data)
      mfaCode.value = ''
    } else if (response.status === 422 && data.code === 'mfa_challenge_expired') {
      step.value = 'password'
      errorMessage.value = data.message || 'That login attempt expired. Please sign in again.'
    } else if (response.status === 429 && data.code === 'resend_too_soon') {
      startResendCountdown(data.retry_after)
    } else {
      mfaError.value = data.message || 'Could not send a new code. Please try again.'
    }
  } catch {
    mfaError.value = 'Network error. Please check your connection.'
  } finally {
    resendLoading.value = false
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
  padding: 32px;
  box-sizing: border-box;
  overflow: hidden;
  background: #EEF1F0;
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
  background: #0A2620;
  clip-path: polygon(58% 0, 100% 0, 100% 100%, 44% 100%);
  pointer-events: none;
}
/* The card's dark half: the same polygon on a page-sized layer centred on the
   card (it is centred on the page), so the two edges are one line. */
.card-dark {
  position: absolute;
  left: 50%;
  top: 50%;
  width: 100vw;
  height: 100dvh;
  transform: translate(-50%, -50%);
  background: #12403A;
  clip-path: polygon(58% 0, 100% 0, 100% 100%, 44% 100%);
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
  width: min(1180px, 100%);
  height: 640px;
  min-height: 640px;
  border-radius: 24px;
  overflow: hidden;
  box-shadow: 0 28px 64px rgba(2, 20, 16, 0.32);
  background: #FBFBF9;
}

/* Identity on the left 44%, the form on the right (72px from the edge, 380px
   wide): both centred on the card's height, which is what keeps them clear of
   the diagonal. */
.brand-panel {
  position: absolute;
  left: 0; top: 0; bottom: 0;
  width: 44%;
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 20px;
  padding: 0 24px 0 40px;
  box-sizing: border-box;
  text-align: center;
}
.brand-text { display: flex; flex-direction: column; align-items: center; gap: 10px; }
.brand-title {
  margin: 0; padding-left: 0.24em;
  font-size: 36px; line-height: 44px; font-weight: 800; letter-spacing: 0.24em;
  color: #0A2620;
}
.brand-subtitle { margin: 0; max-width: 300px; font-size: 14px; line-height: 22px; color: rgba(0, 0, 0, 0.62); }
.brand-place { position: absolute; left: 40px; bottom: 32px; font-size: 12px; line-height: 16px; color: rgba(0, 0, 0, 0.6); }

.form-panel {
  position: absolute;
  right: 72px; top: 0; bottom: 0;
  width: 380px;
  display: flex; flex-direction: column; justify-content: center;
  color: #fff;
}
.form-block { display: flex; flex-direction: column; gap: 28px; }
.form-title { margin: 0; font-size: 28px; line-height: 36px; font-weight: 700; letter-spacing: -0.4px; }
.form-sub { margin: 4px 0 0; font-size: 14px; line-height: 20px; color: rgba(255, 255, 255, 0.76); }
.form-fields { display: flex; flex-direction: column; gap: 18px; }
.form-actions { display: flex; flex-direction: column; gap: 16px; }

/* The seal is a JPG on a pure-white background, and the panel is off-white, so
   it landed as a visible white disc. multiply drops the white to the panel
   colour. The board shows it 128px. */
.brand-seal {
  width: 128px;
  height: 128px;
  object-fit: contain;
  mix-blend-mode: multiply;
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
    height: auto;
    min-height: 100dvh;
    border-radius: 0;
    box-shadow: none;
    background: #12403A;
  }

  .card-dark { display: none; }

  .brand-panel,
  .form-panel {
    position: static;
    width: auto;
    flex: 0 0 auto;
  }

  .brand-panel {
    padding: 32px 24px;
    background: #FBFBF9;
  }
  .brand-place { position: static; margin-top: -8px; }

  .form-panel {
    padding: 32px 24px;
    background: #12403A;
    align-items: center;
  }
  .form-block { width: 100%; max-width: 380px; }
}

/* Form controls (Login board). The password-manager autofill buttons are
   deliberately left visible: admins should be able to use a manager here. */
.login-label {
  display: block; margin-bottom: 8px;
  font-size: 12px; line-height: 16px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
  color: rgba(255, 255, 255, 0.86);
}
.login-field {
  width: 100%; height: 48px; box-sizing: border-box; padding: 0 16px;
  border: 0; border-radius: 12px; background: #fff;
  font-family: inherit; font-size: 15px; color: rgba(0, 0, 0, 0.87);
}
.login-field:focus { outline: 3px solid rgba(52, 195, 154, 0.55); outline-offset: 0; }
.login-field--pw { padding-right: 52px; }
.login-pw { position: relative; }
.login-eye {
  position: absolute; right: 4px; top: 4px; width: 40px; height: 40px;
  display: grid; place-items: center; border: 0; border-radius: 8px;
  background: transparent; color: rgba(0, 0, 0, 0.6); cursor: pointer;
}
.login-eye:focus-visible,
.login-signin:focus-visible,
.login-check input:focus-visible { outline: 3px solid #fff; outline-offset: 2px; }
.login-check {
  display: inline-flex; align-items: center; gap: 10px; align-self: flex-start;
  font-size: 14px; line-height: 20px; color: rgba(255, 255, 255, 0.92); cursor: pointer;
}
.login-check input { width: 18px; height: 18px; margin: 0; accent-color: #34C39A; }
.login-signin {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  width: 100%; height: 48px; border: 0; border-radius: 12px;
  background: #34C39A; color: #04201A;
  font-family: inherit; font-size: 15px; font-weight: 700; letter-spacing: 0.02em;
  box-shadow: 0 8px 16px -4px rgba(52, 195, 154, 0.28); cursor: pointer;
}
.login-signin:disabled { opacity: 0.7; cursor: default; }
.login-spin {
  width: 16px; height: 16px; box-sizing: border-box; border-radius: 50%;
  border: 2px solid rgba(4, 32, 26, 0.35); border-top-color: #04201A;
  animation: btn-spin 700ms linear infinite;
}
/* On the dark half, so it needs light text (0.76 white, the board's helper). */
.login-helper { margin: 0; font-size: 14px; line-height: 20px; color: rgba(255, 255, 255, 0.76); text-align: center; }
.login-link { font-weight: 600; }
/* The code step's boxes: white, 48px, 12px corners. */
.form-block :deep(.v-otp-input .v-field) { border-radius: 12px; background: #fff; }
.form-block :deep(.v-otp-input .v-field__input) { color: rgba(0, 0, 0, 0.87); }

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