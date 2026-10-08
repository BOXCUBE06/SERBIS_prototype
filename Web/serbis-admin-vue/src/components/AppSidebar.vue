<template>
  <v-navigation-drawer
    v-model="isOpen"
    :permanent="!mobile"
    :temporary="mobile"
    width="272"
    class="modern-drawer"
  >
    <div class="d-flex flex-column h-100 sidebar-shell">

      <div class="sidebar-header">
        <span class="brand-tile"><img :src="logoUrl" alt="" width="40" height="40"></span>
        <div class="brand-text">
          <span class="brand-word">SERBIS</span>
          <span class="brand-sub">MDRRMO Echague</span>
        </div>
        <v-btn
          :icon="theme.global.name.value === 'dark' ? 'mdi-weather-sunny' : 'mdi-weather-night'"
          variant="text"
          class="theme-btn"
          :aria-label="theme.global.name.value === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
          @click="toggle"
        ></v-btn>
      </div>

      <nav ref="navScrollEl" class="nav-scroll" aria-label="Main">
        <div v-for="group in menu" :key="group.key">
          <button
            type="button"
            class="group-toggle"
            :aria-expanded="!collapsed.includes(group.key)"
            :aria-controls="`nav-group-${group.key}`"
            @click="toggleGroup(group.key)"
          >
            {{ group.label }}
            <v-icon size="14" class="group-chevron" :class="{ 'is-collapsed': collapsed.includes(group.key) }">mdi-chevron-down</v-icon>
          </button>
          <v-list v-show="!collapsed.includes(group.key)" :id="`nav-group-${group.key}`" bg-color="transparent" density="compact" nav class="nav-list">
            <v-list-item
              v-for="item in group.items"
              :key="item.to"
              :to="item.to"
              class="nav-item"
              active-class="active-nav-item"
              :aria-current="isCurrent(item.to) ? 'page' : undefined"
              :ripple="false"
            >
              <template v-slot:prepend>
                <v-icon size="20" class="nav-icon">{{ item.icon }}</v-icon>
              </template>
              <v-list-item-title class="nav-label">{{ item.title }}</v-list-item-title>
              <!-- What is waiting on staff in that section (see waitingCounts).
                   The server sends a count only to an account holding the
                   section, so no extra check here. -->
              <template v-if="(waitingCounts[item.key] ?? 0) > 0" v-slot:append>
                <span class="nav-count" :aria-label="`${waitingCounts[item.key]} waiting`">{{ waitingCounts[item.key] }}</span>
              </template>
            </v-list-item>
          </v-list>
        </div>
      </nav>

      <div class="sidebar-footer">
        <div class="profile-card">
          <span class="profile-avatar"><v-icon size="18">mdi-account-outline</v-icon></span>
          <div class="profile-text">
            <div class="profile-name">MDRRMO Admin</div>
            <div class="profile-sub">Echague Panel</div>
          </div>
          <v-tooltip text="Sign out" location="top">
            <template v-slot:activator="{ props: tip }">
              <button v-bind="tip" type="button" class="sign-out" aria-label="Sign out" @click="showLogoutDialog = true">
                <v-icon size="18">mdi-logout</v-icon>
              </button>
            </template>
          </v-tooltip>
        </div>
      </div>
    </div>
  </v-navigation-drawer>

  <!-- A confirmation holds nothing typed, so Esc and a click outside are both
       valid answers to it. -->
  <v-dialog v-model="showLogoutDialog" max-width="380">
    <v-card rounded="xl" elevation="10" class="pb-2">
      <v-card-title class="pa-6 pb-2 text-subtitle-1 font-weight-bold">Confirm Logout</v-card-title>
      <v-card-text class="px-6 py-2 text-body-2 text-grey-darken-1">
        Are you sure you want to log out of the SERBIS admin panel?
      </v-card-text>
      <v-card-actions class="pa-6 pt-4 d-flex justify-end" style="gap: 12px">
        <v-btn color="primary" variant="outlined" class="px-4 text-none" @click="showLogoutDialog = false" :disabled="isLoggingOut">
          Cancel
        </v-btn>
        <v-btn color="error" variant="flat" class="px-5 text-none" @click="handleLogout" :loading="isLoggingOut">
          Logout
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'
import { useAuth } from './index'
import { ADMIN_SECTIONS, SECTION_GROUPS, sectionForPath } from '@/composables/adminSections'
import logoUrl from '@/assets/logo/serbis-logo.png'
import { useAppTheme } from '@/composables/useAppTheme'
import { useCurrentAdmin } from '@/composables/useCurrentAdmin'
import { pulse, startPulse, stopPulse } from '@/composables/usePulse'

const { isLoggingOut, showLogoutDialog, handleLogout } = useAuth()
const { theme, toggle } = useAppTheme()

// Both sections share one scroll region (see the style block below) so a
// short viewport clips the end of the list instead of a section going
// unreachable — see the P0 finding in the 2026-09-15 sidebar audit.
const navScrollEl = ref<HTMLElement | null>(null)
const route = useRoute()
const router = useRouter()

// The link the page is on, for aria-current (the sub-pages of a section count).
const isCurrent = (to: string) => route.path === to || route.path.startsWith(`${to}/`)

function scrollActiveIntoView() {
  navScrollEl.value?.querySelector('.active-nav-item')?.scrollIntoView({ block: 'nearest' })
}

onMounted(() => {
  router.isReady().then(() => nextTick(scrollActiveIntoView))
})
watch(() => route.path, () => nextTick(scrollActiveIntoView))

// permanent forces the drawer to render at full width regardless of
// model-value, which is what made every page below Vuetify's own mobile
// breakpoint unusable — the drawer never yielded the screen, and
// App.vue's .outer-wrapper margin-left:260px pushed everything else into a
// sliver beside it (impeccable critique, P0, 2026-08-30). Below the
// breakpoint this becomes a real temporary drawer instead: closed by
// default, opened by the hamburger button App.vue renders only on mobile.
const { mobile } = useDisplay()
const isOpen = defineModel<boolean>('open', { default: true })

// The menu is the section catalogue (composables/adminSections.ts, which also
// holds the ordering notes) narrowed to what this account may open. A group
// with nothing left in it drops its header too. Until the account has been read
// nothing is hidden, so a slow `/me` does not blank the menu; the server refuses
// what the account does not hold either way.
const { can, loadCurrentAdmin } = useCurrentAdmin()

const menu = computed(() => SECTION_GROUPS
  .map((g) => ({ ...g, items: ADMIN_SECTIONS.filter((s) => s.group === g.key && can(s.key)) }))
  .filter((g) => g.items.length > 0))

// Collapsed group keys, remembered per browser. Storage can throw (private
// window, blocked site data), so the menu just starts expanded then.
const COLLAPSED_KEY = 'serbis.sidebar.collapsed'
const readCollapsed = (): string[] => {
  try { return JSON.parse(localStorage.getItem(COLLAPSED_KEY) || '[]') } catch { return [] }
}
const collapsed = ref<string[]>(readCollapsed())
const saveCollapsed = () => {
  try { localStorage.setItem(COLLAPSED_KEY, JSON.stringify(collapsed.value)) } catch { /* not remembered */ }
}
const toggleGroup = (key: string) => {
  collapsed.value = collapsed.value.includes(key) ? collapsed.value.filter((k) => k !== key) : [...collapsed.value, key]
  saveCollapsed()
}

// Landing on a page opens its group, so the active item is never hidden.
watch(() => route.path, (path) => {
  const key = ADMIN_SECTIONS.find((s) => s.key === sectionForPath(path))?.group
  if (key && collapsed.value.includes(key)) {
    collapsed.value = collapsed.value.filter((k) => k !== key)
    saveCollapsed()
  }
}, { immediate: true })

onMounted(() => { loadCurrentAdmin() })

// The sidebar is drawn exactly while someone is signed in, so it owns the poll.
onMounted(startPulse)
onUnmounted(stopPulse)
// Badge per section key: Pending requests and loans, ambulance requests that
// are Pending or Booked without a unit, accounts awaiting activation.
const waitingCounts = computed<Record<string, number | undefined>>(() => ({
  requests: pulse.value?.requests?.waiting,
  ambulance: pulse.value?.ambulance?.waiting,
  borrowings: pulse.value?.borrowings?.waiting,
  residents: pulse.value?.residents?.pending,
}))
</script>

<style scoped>
/* .modern-drawer's background lives in App.vue now -- it was a byte-for-byte
   duplicate here (this file is scoped, App.vue's copy is global and already
   matched this element by class name regardless). One definition, theme-aware. */

/* One scroll region between a pinned header and a pinned footer, instead of
   two independently-clippable v-lists — see the P0 sidebar audit finding
   (2026-09-15). Vuetify forces `.v-navigation-drawer .v-list{overflow:hidden}`
   (VNavigationDrawer.css), which gives a v-list an automatic flex min-height
   of 0; two such lists as flex-column siblings meant the shorter one
   (System) could be shrunk to zero by the flex algorithm while Main Menu
   kept most of the space. Wrapping both lists in one flex:1 region with its
   own overflow-y fixes that: there's exactly one thing left that can shrink. */
.sidebar-header, .sidebar-footer {
  flex: none;
}
.nav-scroll {
  flex: 1 1 auto;
  min-height: 0;
  /* Clear space under the brand block's divider, 16px above the footer's. */
  padding: 12px 12px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-width: thin;
  scrollbar-color: rgba(255, 255, 255, 0.15) transparent;
}
.nav-scroll::-webkit-scrollbar {
  width: 6px;
}
.nav-scroll::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.15);
  border-radius: 3px;
}

/* Group heading (Sidebar board): 32px, 11px/700, .08em, 62% white. */
.group-toggle {
  display: flex; align-items: center; justify-content: space-between;
  width: 100%; height: 32px; padding: 0 12px; border: 0; border-radius: 8px;
  background: transparent; color: rgba(255, 255, 255, 0.62); cursor: pointer; text-align: left;
  font-family: inherit; font-size: 11px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
}
.group-toggle:hover { color: #fff; }
.group-toggle:focus-visible { outline: 2px solid rgba(255, 255, 255, 0.7); outline-offset: 2px; }
.group-chevron { transition: transform var(--motion-fast) var(--ease-out); }
.group-chevron.is-collapsed { transform: rotate(-90deg); }

/* Brand block: logo circle, name, theme toggle, a hairline under it. */
.sidebar-header {
  display: flex; align-items: center; gap: 12px;
  padding: 20px 16px 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
/* 40px circle on white; the source is 2000px, so it only ever scales down. */
.brand-tile { width: 40px; height: 40px; flex: none; border-radius: 50%; overflow: hidden; background: #fff; }
.brand-tile img { display: block; width: 100%; height: 100%; object-fit: contain; }
.brand-text { flex: 1; min-width: 0; }
.brand-word { display: block; font-size: 16px; line-height: 20px; font-weight: 800; letter-spacing: 0.08em; color: #fff; }
.brand-sub {
  display: block; font-size: 11px; line-height: 14px; font-weight: 600; letter-spacing: 0.06em;
  text-transform: uppercase; color: rgba(255, 255, 255, 0.62);
}
.theme-btn { flex: none; width: 36px !important; height: 36px !important; border-radius: 10px !important; color: rgba(255, 255, 255, 0.8) !important; }
.theme-btn :deep(.v-icon) { font-size: 18px; }

/* Nav items: 40px, 10px radius, 12px padding, 20px icons, 14px text. Active is a
   green tint with a brighter icon, no bar; hover is a white wash. Vuetify's own
   overlay, prepend spacing and dimmed icon are switched off. */
.nav-list { padding: 0 !important; margin: 2px 0 0; display: flex; flex-direction: column; gap: 2px; }
.nav-item {
  height: 40px; min-height: 40px !important; margin: 0 !important;
  padding: 0 12px !important; border-radius: 10px !important;
  transition: background-color var(--motion-fast) var(--ease-out);
}
.nav-item :deep(.v-list-item__overlay) { display: none; }
.nav-item :deep(.v-list-item__spacer) { display: none; }
.nav-item :deep(.v-list-item__prepend) { width: auto; }
.nav-item :deep(.v-list-item__prepend > .v-icon) { margin-inline-end: 12px; opacity: 1; }
.nav-icon { color: rgba(255, 255, 255, 0.7); }
.nav-label { font-size: 14px; font-weight: 500; color: rgba(255, 255, 255, 0.84); }
.nav-count {
  min-width: 22px;
  padding: 0 7px;
  border-radius: 11px;
  background: #F5A524;
  color: #2B1A00;
  font-size: 12px;
  font-weight: 700;
  line-height: 20px;
  text-align: center;
  font-variant-numeric: tabular-nums;
}
.nav-item:hover:not(.active-nav-item) { background-color: rgba(255, 255, 255, 0.08) !important; }
.active-nav-item { background-color: rgba(52, 195, 154, 0.18) !important; }
.active-nav-item .nav-label { color: #fff; font-weight: 700; }
.active-nav-item .nav-icon { color: #34c39a; }

/* Account card: pinned under the scrolling nav, with its own hairline. */
.sidebar-footer { padding: 12px; border-top: 1px solid rgba(255, 255, 255, 0.08); }
.profile-card {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 10px 10px 12px; border-radius: 14px; background: rgba(255, 255, 255, 0.08);
}
.profile-avatar {
  flex: none; display: grid; place-items: center; width: 36px; height: 36px;
  border-radius: 50%; background: rgba(255, 255, 255, 0.14); color: #fff;
}
.profile-text { flex: 1; min-width: 0; }
.profile-name { font-size: 14px; line-height: 20px; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.profile-sub { font-size: 12px; line-height: 16px; color: rgba(255, 255, 255, 0.62); }
/* Sign out: white at 80%, red only on hover. */
.sign-out {
  flex: none; display: grid; place-items: center; width: 40px; height: 40px;
  border: 0; border-radius: 10px; background: transparent; color: rgba(255, 255, 255, 0.8); cursor: pointer;
  transition: background-color var(--motion-fast) var(--ease-out), color var(--motion-fast) var(--ease-out);
}
.sign-out:hover { background: rgba(241, 101, 101, 0.18); color: #ffb4b4; }
.sign-out:focus-visible { outline: 2px solid rgba(255, 255, 255, 0.7); outline-offset: 2px; }
</style>