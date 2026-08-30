<template>
  <v-app>
    <!-- Mobile-only: the drawer is temporary and closed by default below the
         breakpoint (see AppSidebar.vue), so there has to be some way to open
         it that isn't already off-screen itself. Desktop keeps the permanent
         drawer it always had; this bar never renders there. -->
    <v-app-bar v-if="!isAuthPage && mobile" theme="dark" color="#0A2620" density="comfortable" flat>
      <v-app-bar-nav-icon aria-label="Open menu" @click="sidebarOpen = true"></v-app-bar-nav-icon>
      <span class="text-subtitle-1 font-weight-black text-white tracking-widest">SERBIS</span>
    </v-app-bar>
    <AppSidebar v-if="!isAuthPage" v-model:open="sidebarOpen" />
    <v-main>
      <div v-if="!isAuthPage" class="outer-wrapper" :class="{ 'outer-wrapper--mobile': mobile }">
        <div class="inner-wrapper" :class="{ 'inner-wrapper--fixed': isFixedHeight }">
          <RouterView />
        </div>
      </div>
      <RouterView v-else />
    </v-main>
  </v-app>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useDisplay } from 'vuetify'
import AppSidebar from '@/components/AppSidebar.vue'
import { useAppTheme } from '@/composables/useAppTheme'

const route = useRoute()

const isAuthPage = computed(() => route?.path === '/login')

// Set by the route, not sniffed from the path — see the note in router/index.ts.
const isFixedHeight = computed(() => route?.meta?.fixedHeight === true)

const { mobile } = useDisplay()
// `permanent` on v-navigation-drawer changes behaviour (no scrim, no
// auto-close) — it does NOT force visibility regardless of model-value, as
// wrongly assumed the first time this was written. A bare `ref(false)` left
// the drawer closed on every fresh load, desktop included, since desktop
// never renders the hamburger that would reopen it. Start open on desktop,
// closed on mobile, and keep it in sync if the breakpoint is crossed during
// the session (a resized window, a rotated tablet).
const sidebarOpen = ref(!mobile.value)
watch(mobile, (isMobile) => { sidebarOpen.value = !isMobile })

useAppTheme().init()
</script>

<style>
.v-application {
  font-family: 'Inter', sans-serif !important;
}

/* Shared theme-aware surfaces. Vuetify's own bg-surface-light resolves to a
   fixed grey (#424242) that ignores the palette, so tint on-surface instead —
   that tracks whichever theme is active. */
.subtle-surface {
  background-color: rgba(var(--v-theme-on-surface), 0.05);
}

.subtle-border {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08) !important;
}

.v-main {
  padding: 0 !important;
  background-color: #0A2620;
}

.v-main__wrap {
  padding: 0 !important;
}

.outer-wrapper {
  display: flex;
  height: 100vh;
  padding: 12px;
  box-sizing: border-box;
  margin-left: 260px;
}

/* Matches this codebase's existing 959px breakpoint (see LoginView.vue) and
   Vuetify's own default mobile threshold, so this and the drawer's
   useDisplay().mobile flip at the same width. Below it the drawer is an
   overlay, not a permanent 260px column, so the margin that reserved space
   for it has nothing left to reserve. */
@media (max-width: 959px) {
  .outer-wrapper {
    margin-left: 0;
  }
}

.outer-wrapper--mobile {
  height: calc(100vh - 56px);
}

.inner-wrapper {
  flex: 1;
  background-color: rgb(var(--v-theme-background));
  border-radius: 24px;
  overflow-y: auto;
  height: 100%;

  /* The scrollbar used to be hidden outright (`scrollbar-width: none` plus a
     `::-webkit-scrollbar { display: none }`). Content still scrolled — there
     was simply nothing on screen saying so, which is how a page that overflowed
     read as a page that was cut off. A slim theme-aware bar instead: it says
     "there is more" without the chrome of a default scrollbar. */
  scrollbar-width: thin;
  scrollbar-color: rgba(var(--v-theme-on-surface), 0.25) transparent;
}

.inner-wrapper::-webkit-scrollbar {
  width: 8px;
}
.inner-wrapper::-webkit-scrollbar-track {
  background: transparent;
}
.inner-wrapper::-webkit-scrollbar-thumb {
  background: rgba(var(--v-theme-on-surface), 0.25);
  border-radius: 4px;
}

/* Route-scoped, opt-in. A fixed-height view manages scrolling inside its own
   panes, so the shell must not add a second scroll axis around it — that is
   what made the whole dashboard slide behind the (then invisible) bar when the
   request list grew. */
.inner-wrapper--fixed {
  overflow: hidden;
}

.v-main .v-container {
  padding-left: 40px !important;
  padding-right: 24px !important;
}

.modern-drawer {
  font-family: 'Inter', sans-serif;
  background: radial-gradient(circle at -10% 50%, #154c41 0%, #0A2620 80%) !important;
  border-right: none !important;
}

.nav-item {
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important;
}
</style>