<template>
  <v-navigation-drawer
    v-model="isOpen"
    :permanent="!mobile"
    :temporary="mobile"
    width="260"
    class="modern-drawer"
  >
    <div class="d-flex flex-column h-100 sidebar-shell">

      <div class="sidebar-header d-flex align-center justify-space-between mb-2 mt-2 px-4">
        <div class="d-flex align-center">
          <span class="brand-tile"><img :src="logoUrl" alt="" width="40" height="40"></span>
          <div class="brand-text">
            <span class="brand-word text-h6 text-white tracking-widest">SERBIS</span>
            <span class="brand-sub text-white-50">MDRRMO Echague</span>
          </div>
        </div>
        <v-btn
          :icon="theme.global.name.value === 'dark' ? 'mdi-weather-sunny' : 'mdi-weather-night'"
          size="small"
          variant="text"
          color="grey-lighten-1"
          :aria-label="theme.global.name.value === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
          @click="toggle"
        ></v-btn>
      </div>

      <div ref="navScrollEl" class="nav-scroll px-4">
        <template v-for="(group, gi) in menu" :key="group.key">
          <button
            type="button"
            class="group-toggle text-caption font-weight-medium text-white-50 mb-1 px-2 tracking-widest"
            :class="{ 'mt-3': gi > 0 }"
            :aria-expanded="!collapsed.includes(group.key)"
            :aria-controls="`nav-group-${group.key}`"
            @click="toggleGroup(group.key)"
          >
            {{ group.label }}
            <v-icon size="16" class="group-chevron" :class="{ 'is-collapsed': collapsed.includes(group.key) }">mdi-chevron-down</v-icon>
          </button>
          <v-list v-show="!collapsed.includes(group.key)" :id="`nav-group-${group.key}`" bg-color="transparent" density="compact" nav class="px-0">
            <v-list-item
              v-for="item in group.items"
              :key="item.to"
              :to="item.to"
              class="nav-item"
              rounded="pill"
              active-class="active-nav-item"
              slim
              :ripple="false"
            >
              <template v-slot:prepend>
                <v-avatar rounded="circle" size="32" class="nav-icon-avatar" color="transparent">
                  <v-icon size="18" color="grey-lighten-1">{{ item.icon }}</v-icon>
                </v-avatar>
              </template>
              <v-list-item-title class="font-weight-medium text-body-2 text-grey-lighten-1 nav-label">
                {{ item.title }}
              </v-list-item-title>
            </v-list-item>
          </v-list>
        </template>
      </div>

      <div class="sidebar-footer px-4 pb-4">
        <v-card
          color="rgba(255, 255, 255, 0.03)"
          border="0"
          class="pa-2 d-flex align-center profile-card"
          style="cursor: pointer"
          @click="showLogoutDialog = true"
        >
          <v-avatar size="32" color="rgba(255, 255, 255, 0.1)" class="mr-2 avatar-soft">
            <v-icon color="white" size="small">mdi-account-outline</v-icon>
          </v-avatar>
          <div style="min-width: 0;">
            <div class="text-caption font-weight-bold text-white text-truncate">MDRRMO Admin</div>
            <div class="text-white-50 text-truncate" style="font-size: 0.65rem !important;">Echague Panel</div>
          </div>
          <v-spacer></v-spacer>
          <v-icon color="white-50" size="small" class="logout-icon">mdi-logout</v-icon>
        </v-card>
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
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'
import { useAuth } from './index'
import { ADMIN_SECTIONS, SECTION_GROUPS, sectionForPath } from '@/composables/adminSections'
import logoUrl from '@/assets/logo/serbis-logo.png'
import { useAppTheme } from '@/composables/useAppTheme'
import { useCurrentAdmin } from '@/composables/useCurrentAdmin'

const { isLoggingOut, showLogoutDialog, handleLogout } = useAuth()
const { theme, toggle } = useAppTheme()

// Both sections share one scroll region (see the style block below) so a
// short viewport clips the end of the list instead of a section going
// unreachable — see the P0 finding in the 2026-09-15 sidebar audit.
const navScrollEl = ref<HTMLElement | null>(null)
const route = useRoute()
const router = useRouter()

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
</script>

<style scoped>
/* .modern-drawer's background lives in App.vue now -- it was a byte-for-byte
   duplicate here (this file is scoped, App.vue's copy is global and already
   matched this element by class name regardless). One definition, theme-aware. */

.tracking-widest { letter-spacing: 0.1em; text-transform: uppercase; }
.text-white-50 { color: rgba(255, 255, 255, 0.5) !important; }

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

/* Tightened from the default compact item height so more of the 13 items
   fit before .nav-scroll needs to scroll at all — same value in both
   sections since they share this rule. */
.nav-scroll :deep(.v-list-item) {
  min-height: 38px;
  margin-bottom: 2px;
}

.group-toggle {
  display: flex; align-items: center; justify-content: space-between;
  width: 100%; border: 0; background: none; cursor: pointer; text-align: left;
  border-radius: 6px;
}
.group-toggle:focus-visible { outline: 2px solid rgba(255, 255, 255, 0.7); outline-offset: 2px; }
.group-chevron { transition: transform var(--motion-fast) var(--ease-out); }
.group-chevron.is-collapsed { transform: rotate(-90deg); }

/* 40px tile; the source is 2000px, so it only ever scales down. */
.brand-tile {
  width: 40px; height: 40px; flex: none; margin-right: 12px;
  border-radius: 10px; overflow: hidden;
}
.brand-tile img { display: block; width: 100%; height: 100%; object-fit: contain; }
.brand-text { display: flex; flex-direction: column; justify-content: center; line-height: 1.2; }
.brand-word { font-weight: 800; }
.brand-sub { font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; }

.nav-item, .nav-icon-avatar, .nav-label, .profile-card, .logout-icon, .avatar-soft {
  transition: all var(--motion-base) var(--ease-out) !important;
}

.nav-item:hover:not(.active-nav-item) {
  background-color: rgba(255, 255, 255, 0.04) !important;
}
.nav-item:hover:not(.active-nav-item) .nav-icon-avatar { background-color: rgba(255, 255, 255, 0.05) !important; }
.nav-item:hover:not(.active-nav-item) .nav-label,
.nav-item:hover:not(.active-nav-item) .v-icon { color: #fff !important; }

.active-nav-item {
  background: linear-gradient(90deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%) !important;
}
/* Colour only marks active: weight, transform and borders all change box
   metrics and make the row shift on click. */
.active-nav-item .nav-label, .active-nav-item .v-icon { color: #fff !important; }

.profile-card { border-radius: 12px !important; border: 1px solid rgba(255, 255, 255, 0.02) !important; }
.profile-card:hover { background-color: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.1) !important; transform: translateY(-2px); }
.profile-card:hover .logout-icon { color: #ef4444 !important; transform: translateX(2px); }
.profile-card:hover .avatar-soft { transform: scale(1.05); }
</style>