<template>
  <v-container fluid class="fill-height align-start bg-background">
    <v-card elevation="3" rounded="lg" class="bg-surface w-100 empty-state">
      <v-icon size="56" class="text-medium-emphasis mb-4">mdi-lock-outline</v-icon>

      <div class="text-h6 font-weight-bold text-high-emphasis mb-1">No access yet</div>

      <div class="text-body-1 text-medium-emphasis mb-1">
        This account has not been given any part of the panel.
      </div>
      <div class="text-body-1 text-medium-emphasis mb-5">
        Ask a super admin to open Staff Accounts and choose what you can use.
        Once they have, check again.
      </div>

      <v-btn
        color="primary"
        variant="flat"
        rounded="lg"
        height="48"
        class="px-6 text-none font-weight-bold"
        :loading="checking"
        @click="checkAgain"
      >
        <v-icon start>mdi-refresh</v-icon> Check again
      </v-btn>
    </v-card>
  </v-container>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useCurrentAdmin } from '@/composables/useCurrentAdmin'

const router = useRouter()
const { loadCurrentAdmin, firstAllowedPath } = useCurrentAdmin()
const checking = ref(false)

// Re-reads the account rather than trusting the last answer: the whole point of
// this page is that someone else is about to change it.
const checkAgain = async () => {
  checking.value = true
  try {
    await loadCurrentAdmin(true)
    const next = firstAllowedPath()
    if (next) router.push(next)
  } finally {
    checking.value = false
  }
}
</script>

<style scoped>
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 64px 24px;
}
</style>
