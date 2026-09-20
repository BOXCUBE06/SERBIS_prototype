<template>
  <div class="change-page d-flex align-center justify-center">
    <v-card class="change-card pa-6" rounded="xl" elevation="0">
      <h1 class="text-h5 font-weight-bold text-high-emphasis mb-2">Set a new password</h1>
      <p class="text-body-2 text-medium-emphasis mb-5">
        Your password was reset by another admin. Choose a new one to continue.
      </p>

      <v-alert
        v-if="errorMessage" type="error" variant="tonal" density="compact"
        rounded="lg" class="mb-4" role="alert"
      >{{ errorMessage }}</v-alert>

      <v-form ref="formRef" @submit.prevent="submit">
        <v-text-field
          v-model="form.current_password" label="Temporary password" variant="outlined"
          density="comfortable" rounded="lg" class="mb-1" autocomplete="current-password"
          :type="showPassword ? 'text' : 'password'" autofocus
          :rules="[requiredRule('Temporary password')]" :error-messages="fieldErrors.current_password"
        ></v-text-field>

        <div class="text-caption text-medium-emphasis mb-3">
          At least 8 characters, with upper and lower case and a number.
        </div>

        <v-text-field
          v-model="form.password" label="New password" variant="outlined"
          density="comfortable" rounded="lg" class="mb-1" autocomplete="new-password"
          :type="showPassword ? 'text' : 'password'"
          :rules="[requiredRule('New password')]" :error-messages="fieldErrors.password"
        >
          <template #append-inner>
            <v-btn
              :icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
              :aria-label="showPassword ? 'Hide passwords' : 'Show passwords'"
              :aria-pressed="showPassword"
              variant="text" density="comfortable" size="small"
              @click="showPassword = !showPassword"
            ></v-btn>
          </template>
        </v-text-field>

        <v-text-field
          v-model="form.password_confirmation" label="Confirm new password" variant="outlined"
          density="comfortable" rounded="lg" class="mb-4" autocomplete="new-password"
          :type="showPassword ? 'text' : 'password'"
          :rules="[confirmRule]" :error-messages="fieldErrors.password_confirmation"
        ></v-text-field>

        <v-btn
          type="submit" color="primary" variant="flat" block height="48" rounded="lg"
          class="text-none font-weight-bold" :loading="loading"
        >
          Save and continue
        </v-btn>
        <v-btn variant="text" block class="text-none mt-2" :disabled="loading" @click="signOut">
          Sign out
        </v-btn>
      </v-form>
    </v-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { clearToken, getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'

const router = useRouter()

const form = reactive({ current_password: '', password: '', password_confirmation: '' })
const formRef = ref()
const loading = ref(false)
const showPassword = ref(false)
const errorMessage = ref('')
const fieldErrors = ref<Record<string, string>>({})

const requiredRule = (label: string) => (v: unknown) =>
  (v !== null && v !== undefined && String(v) !== '') || `${label} is required.`
const confirmRule = (v: unknown) =>
  String(v || '') === form.password || 'The two passwords do not match.'

const submit = async () => {
  errorMessage.value = ''
  fieldErrors.value = {}

  const { valid } = await formRef.value.validate()
  if (!valid) return

  loading.value = true
  try {
    const res = await fetch(`${API_BASE}/admin/change-password`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${getToken()}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(form),
    })
    const data = await res.json().catch(() => ({}))

    if (res.ok) {
      router.push('/')
    } else if (res.status === 422 && data.errors) {
      // Known fields land on their input; anything else stays in the banner.
      const leftovers: string[] = []
      for (const [key, messages] of Object.entries(data.errors as Record<string, string[]>)) {
        const text = Array.isArray(messages) ? messages.join(' ') : String(messages)
        if (key in form) fieldErrors.value[key] = text
        else leftovers.push(text)
      }
      if (leftovers.length) errorMessage.value = leftovers.join(' ')
    } else if (res.status === 429) {
      errorMessage.value = 'Too many attempts. Wait a minute, then try again.'
    } else {
      errorMessage.value = data.message || 'Something went wrong. Please try again.'
    }
  } catch {
    errorMessage.value = 'Network error. Please check your connection.'
  } finally {
    loading.value = false
  }
}

const signOut = () => {
  clearToken()
  router.push('/login')
}
</script>

<style scoped>
.change-page {
  min-height: 100dvh;
  padding: 24px 16px;
  background: #EDF1F0;
}

.change-card {
  width: 100%;
  max-width: 440px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
</style>
