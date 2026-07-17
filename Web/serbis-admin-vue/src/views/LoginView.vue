<template>
  <div class="split-viewport d-flex">
    <v-row class="ma-0 w-100">

      <!-- Left Side -->
      <v-col cols="12" md="5" class="brand-panel d-flex flex-column align-center justify-center pa-8">
        <div class="d-flex flex-column align-center text-center">
          <img 
            src="@/assets/mdrrmo_logo.jpg" 
            alt="MDRRMO Logo"
            style="width: 220px; height: 220px; object-fit: contain; border-radius: 50%;"
            class="mb-6" 
          />
          <!-- This panel is a fixed white gradient in both themes, so its text
               must stay dark. Theme tokens (text-high-emphasis) resolve to white
               in dark mode and make the title vanish against the white half. -->
          <h1 class="text-h2 font-weight-black text-grey-darken-4 mb-3" style="letter-spacing: 6px; line-height: 1.2;">
            SERBIS
          </h1>
          <div class="text-body-1 text-grey-darken-1 font-weight-medium" style="max-width: 350px; line-height: 1.8;">
            MDRRMO Echague Disaster Communication<br>& Service Coordination System
          </div>
        </div>
      </v-col>

      <!-- Right Side -->
      <v-col cols="12" md="7" class="form-panel d-flex flex-column align-center justify-center pa-8">
        <div style="width: 100%; max-width: 420px;">
          <v-form @submit.prevent="handleLogin" class="w-100">
            
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

            <label for="login-email" class="d-block text-body-2 text-white mb-2 font-weight-medium">Email</label>
            <v-text-field
              id="login-email"
              v-model="credentials.email_address"
              type="email"
              inputmode="email"
              placeholder="Example@serbis.com"
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

            <!-- No "Recover password" link: there is no reset route and no
                 mail transport behind it. Admins are provisioned by hand, so
                 a forgotten password is a DB operation, not a self-serve flow.
                 See audit #29. -->
            <div class="d-flex align-center mb-5">
              <v-checkbox
                v-model="rememberMe"
                label="Keep me signed in for 30 days"
                density="compact"
                hide-details
                class="custom-checkbox"
              ></v-checkbox>
            </div>

            <v-btn
              type="submit"
              color="primary"
              block
              height="52"
              rounded="md"
              elevation="0"
              class="text-none font-weight-bold text-body-1"
              :loading="loading"
              :disabled="lockoutSeconds > 0"
            >
              {{ lockoutSeconds > 0 ? `LOCKED — ${lockoutSeconds}s` : 'SIGN IN' }}
            </v-btn>

          </v-form>
        </div>
      </v-col>

    </v-row>
  </div>
</template>

<script setup>
import { ref, reactive, watch, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { setToken } from '@/composables/authToken'

const router = useRouter()
const loading = ref(false)
const rememberMe = ref(false)
const showPassword = ref(false)
const errorMessage = ref('')
const lockoutSeconds = ref(0)
let lockoutTimer = null

// The login route is throttled at 5/min per email+IP (AppServiceProvider).
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
  email_address: '',
  password: ''
})

// The throttle bucket is keyed per email, so a lockout on one address says
// nothing about another. Editing the email releases the button — a typo'd
// address must not lock the account the user actually meant.
watch(() => credentials.email_address, () => {
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
    const response = await fetch('http://localhost:8000/api/admin/login', {
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
      router.push('/')
    } else if (response.status === 401) {
      errorMessage.value = 'Invalid email or password. Please try again.'
    } else if (response.status === 429) {
      const retryAfter = parseInt(response.headers.get('Retry-After'), 10)
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
  } catch (error) {
    errorMessage.value = 'Network error. Please check your connection.'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
/* min-height, not height: the form must be able to push the page taller and
   scroll. This was `height: 100vh` on an `overflow-hidden` container, which
   clipped SIGN IN out of reach below ~665px tall — 259px off-screen in
   landscape, with no way to scroll to it. 100dvh so mobile browser chrome
   does not eat the bottom of the form. */
.split-viewport {
  min-height: 100dvh;
  width: 100%;
  background: linear-gradient(105deg, #ffffff 42%, #113F36 42%);
}

@supports not (height: 100dvh) {
  .split-viewport { min-height: 100vh; }
}

@media (max-width: 959px) {
  /* Stacked, the columns break where the content ends, but a single background
     seam sits at a fixed 40% and cannot track that. The brand subtitle used to
     spill past it onto the green at 2.55:1 — dark grey on dark green. Give each
     panel its own background so text always sits on the half it was coloured
     for, and let the container's green fill any slack below the form. */
  .split-viewport {
    background: #113F36;
  }

  .brand-panel {
    background: #ffffff;
  }

  .form-panel {
    background: #113F36;
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

.hover-underline:hover {
  text-decoration: underline !important;
}
</style>