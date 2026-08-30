<template>
  <v-container fluid class="align-start pa-6 bg-background" style="min-height: 100vh;">
    <!-- Header -->
    <div class="page-header">
      <h2 class="text-h5 font-weight-bold text-high-emphasis">Equipment Borrowing</h2>
      <div class="text-subtitle-2 text-medium-emphasis">
        Move each request through the pipeline — approve, release, then confirm its return
      </div>
    </div>

    <v-tabs v-model="activeTab" color="primary" class="page-tabs border-b">
      <v-tab value="board" class="text-none font-weight-bold">
        <v-icon start>mdi-view-list-outline</v-icon>
        Active pipeline
        <v-chip size="x-small" variant="tonal" class="ml-2 font-weight-bold">{{ activeItems.length }}</v-chip>
      </v-tab>
      <v-tab value="history" class="text-none font-weight-bold">
        <v-icon start>mdi-archive-outline</v-icon>
        History
        <v-chip size="x-small" variant="tonal" class="ml-2 font-weight-bold">{{ historyItems.length }}</v-chip>
      </v-tab>
    </v-tabs>

    <!-- Filters sit under the tabs, not in the header, because they apply to
         whichever surface is showing and reading them second makes that order
         explicit. -->
    <div v-if="!loadError" class="filter-bar">
      <v-text-field
        v-model="search"
        label="Search"
        placeholder="Resident or item"
        prepend-inner-icon="mdi-magnify"
        variant="outlined"
        density="compact"
        hide-details
        clearable
        rounded="lg"
        class="filter-field"
      ></v-text-field>
      <v-select
        v-model="itemFilter"
        :items="itemOptions"
        label="Equipment"
        prepend-inner-icon="mdi-package-variant-closed"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        class="filter-field"
      ></v-select>
      <v-select
        v-model="barangayFilter"
        :items="barangayOptions"
        label="Barangay"
        prepend-inner-icon="mdi-map-marker-outline"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        class="filter-field"
      ></v-select>
      <!-- History only: Returned and Denied were only ever visible per-row, in
           the Outcome column's chip — there was no way to filter to just one
           of them. -->
      <v-select
        v-if="activeTab === 'history'"
        v-model="outcomeFilter"
        :items="outcomeOptions"
        label="Outcome"
        prepend-inner-icon="mdi-check-decagram-outline"
        variant="outlined"
        density="compact"
        hide-details
        rounded="lg"
        class="filter-field"
      ></v-select>
    </div>

    <!-- Active filters, each removable on its own, plus a clear-all. -->
    <div v-if="!initialLoad && !loadError" class="filter-active d-flex align-center flex-wrap gap-2">
      <template v-if="activeFilters.length">
        <span class="text-caption font-weight-bold text-medium-emphasis">Filtered by</span>
        <v-chip
          v-for="f in activeFilters"
          :key="f.key"
          size="small"
          variant="outlined"
          closable
          class="filter-chip font-weight-medium"
          :close-label="`Remove filter: ${f.label}`"
          @click:close="clearFilter(f.key)"
        >{{ f.label }}</v-chip>
        <v-btn
          variant="text"
          size="small"
          class="text-none font-weight-bold"
          @click="clearAllFilters"
        >Clear all</v-btn>
      </template>
      <span class="text-caption text-medium-emphasis ml-auto" aria-live="polite">
        {{ resultSummary }}
      </span>
    </div>

    <!-- An action that failed used to leave no trace once the snackbar timed
         out, 3.5 seconds later. It is the same error the modal has always
         shown in place; the page simply had nowhere to put it. -->
    <v-alert
      v-if="apiError && !modal.isOpen"
      type="error"
      variant="tonal"
      density="compact"
      closable
      class="mb-4"
      close-label="Dismiss error"
      @click:close="apiError = ''"
    >{{ apiError }}</v-alert>

    <v-skeleton-loader v-if="initialLoad" type="table" class="rounded-lg"></v-skeleton-loader>

    <!-- A failed load used to render as an empty table, indistinguishable
         from an empty database — the operator would read a dead API as a
         quiet morning. -->
    <v-card v-else-if="loadError" elevation="0" border rounded="lg" class="bg-surface">
      <div class="text-center py-12 px-6">
        <v-icon size="40" aria-hidden="true" class="text-error mb-2">mdi-cloud-off-outline</v-icon>
        <div class="text-body-1 font-weight-bold text-high-emphasis">Could not load borrowings</div>
        <div class="text-body-2 text-medium-emphasis mb-4">{{ loadError }}</div>
        <v-btn
          color="primary"
          variant="flat"
          class="text-none font-weight-bold px-6"
          height="44"
          :loading="reloading"
          @click="fetchData"
        >Try again</v-btn>
      </div>
    </v-card>

    <template v-else-if="activeTab === 'board'">
      <!-- Status strip. Replaces the kanban columns' at-a-glance counts:
           click a tile to filter the table to that stage, same mechanic as
           Fleet Management's readiness tiles. Overdue is a fourth tile, not
           a status, since a record can be Approved-and-overdue. -->
      <div class="status-strip d-flex flex-wrap gap-3">
        <button
          v-for="tile in statusTiles"
          :key="tile.status"
          type="button"
          class="stat-tile"
          :class="{ 'stat-tile--active': statusFilter === tile.status }"
          :style="{ '--tile-accent': tile.accent }"
          @click="toggleStatusFilter(tile.status)"
        >
          <span class="dot" :style="{ backgroundColor: tile.accent }"></span>
          <span class="stat-value text-high-emphasis">{{ tile.count }}</span>
          <span class="stat-label text-medium-emphasis">{{ tile.label }}</span>
        </button>
        <button
          type="button"
          class="stat-tile"
          :class="{ 'stat-tile--active': overdueOnly }"
          style="--tile-accent: rgb(var(--v-theme-error));"
          @click="overdueOnly = !overdueOnly"
        >
          <span class="dot" style="background-color: rgb(var(--v-theme-error));"></span>
          <span class="stat-value text-high-emphasis">{{ overdueCount }}</span>
          <span class="stat-label text-medium-emphasis">Overdue</span>
        </button>
        <!-- Returned/Denied never appear in this table — they're terminal, so
             they only ever live in History. Same "hide when zero" rule
             Service Requests uses for its own status tabs: shown only when
             there's something behind it, and a click jumps straight to the
             filtered History view rather than pretending to filter this table. -->
        <button
          v-for="tile in terminalTiles"
          :key="tile.status"
          type="button"
          class="stat-tile stat-tile--link"
          :style="{ '--tile-accent': tile.accent }"
          :aria-label="`${tile.count} ${tile.label} — open in History`"
          @click="goToOutcome(tile.status)"
        >
          <span class="dot" :style="{ backgroundColor: tile.accent }"></span>
          <span class="stat-value text-high-emphasis">{{ tile.count }}</span>
          <span class="stat-label text-medium-emphasis">{{ tile.label }}</span>
          <v-icon size="13" class="stat-tile__go" aria-hidden="true">mdi-arrow-top-right</v-icon>
        </button>
      </div>

      <v-card elevation="0" border rounded="lg" class="bg-surface overflow-hidden">
        <v-data-table
          :headers="activeHeaders"
          :items="activeItems"
          :items-per-page="-1"
          density="comfortable"
          hover
          class="bg-transparent borrow-table"
          item-value="borrow_id"
          :row-props="rowProps"
          @click:row="(_event, { item }) => openDetail(item)"
        >
          <template v-slot:item.rowNumber="{ item }">
            <span class="row-number text-medium-emphasis">{{ activeRowNumber(item) }}</span>
          </template>

          <template v-slot:item.avatar="{ item }">
            <v-avatar size="40" class="avatar-tint">
              <span class="avatar-initials">{{ initials(item.resident) }}</span>
            </v-avatar>
          </template>

          <template v-slot:item.resident="{ item }">
            <v-tooltip :text="`${item.resident?.last_name}, ${item.resident?.first_name}`" location="top">
              <template v-slot:activator="{ props }">
                <div v-bind="props" class="font-weight-bold text-high-emphasis cell-truncate">
                  {{ item.resident?.last_name }}, {{ item.resident?.first_name }}
                </div>
              </template>
            </v-tooltip>
          </template>

          <template v-slot:item.barangay="{ item }">
            <span class="text-body-2 text-medium-emphasis cell-truncate">
              {{ item.resident?.barangay?.barangay_name || 'N/A' }}
            </span>
          </template>

          <template v-slot:item.equipment="{ item }">
            <div class="min-w-0">
              <v-tooltip :text="item.equipment?.item_name || 'Unknown'" location="top">
                <template v-slot:activator="{ props }">
                  <div v-bind="props" class="text-body-2 font-weight-medium text-high-emphasis cell-truncate">
                    {{ item.equipment?.item_name || 'Unknown' }} <span class="text-medium-emphasis">&times;{{ item.quantity }}</span>
                  </div>
                </template>
              </v-tooltip>
              <div v-if="shortStock(item)" class="text-caption font-weight-bold" style="color: rgb(var(--v-theme-error-strong));">
                Only {{ item.equipment?.available_quantity ?? 0 }} in stock
              </div>
            </div>
          </template>

          <template v-slot:item.status="{ item }">
            <v-chip
              size="small"
              variant="flat"
              class="font-weight-bold"
              :style="{ backgroundColor: statusAccent(item.status), color: '#FFFFFF' }"
            >
              <v-icon start size="14">{{ statusIcon(item.status) }}</v-icon>
              {{ item.status }}
            </v-chip>
          </template>

          <template v-slot:item.timeline="{ item }">
            <div class="text-body-2 font-weight-medium" :class="isOverdue(item) ? 'text-error' : 'text-high-emphasis'">
              {{ dueLabel(item) || agingLabel(item) }}
            </div>
            <div v-if="dueLabel(item)" class="text-caption text-medium-emphasis">{{ agingLabel(item) }}</div>
          </template>

          <template v-slot:item.actions="{ item }">
            <div class="d-flex justify-end gap-2" @click.stop>
              <template v-if="item.status === 'Pending'">
                <v-btn
                  size="small" variant="outlined" color="error" class="text-none font-weight-bold"
                  :loading="processingId === (item.borrow_id || item.id)"
                  :aria-label="`Deny ${cardLabel(item)}`"
                  @click="requestAction(item, 'Denied')"
                >Deny</v-btn>
                <v-btn
                  size="small" variant="flat" color="primary" class="text-none font-weight-bold"
                  :loading="processingId === (item.borrow_id || item.id)"
                  :aria-label="`Approve ${cardLabel(item)}`"
                  @click="requestAction(item, 'Approved')"
                >Approve</v-btn>
              </template>
              <v-btn
                v-else-if="item.status === 'Approved'"
                size="small" variant="flat" color="primary" class="text-none font-weight-bold"
                :loading="processingId === (item.borrow_id || item.id)"
                :aria-label="`Release ${cardLabel(item)}`"
                @click="requestAction(item, 'Released')"
              >Release</v-btn>
              <v-btn
                v-else-if="item.status === 'Released'"
                size="small" variant="flat" color="primary" class="text-none font-weight-bold"
                :loading="processingId === (item.borrow_id || item.id)"
                :aria-label="`Confirm return of ${cardLabel(item)}`"
                @click="requestAction(item, 'Returned')"
              >Confirm return</v-btn>
            </div>
          </template>

          <template v-slot:no-data>
            <div class="text-center py-12">
              <v-icon size="40" class="text-medium-emphasis mb-2">
                {{ activeFilters.length ? 'mdi-filter-remove-outline' : 'mdi-inbox-outline' }}
              </v-icon>
              <template v-if="activeFilters.length">
                <div class="text-body-2 font-weight-bold text-high-emphasis">No requests match</div>
                <v-btn variant="outlined" size="small" class="text-none font-weight-bold mt-3" @click="clearAllFilters">
                  Clear all filters
                </v-btn>
              </template>
              <div v-else class="text-body-2 font-weight-bold text-high-emphasis">Nothing needs action right now</div>
            </div>
          </template>
        </v-data-table>
      </v-card>
    </template>

    <!-- History. This list only ever grows, and the questions asked of it are
         lookups ("did the Cruz family return the generator?") rather than a
         pipeline glance. -->
    <v-card v-else elevation="0" border rounded="lg" class="bg-surface overflow-hidden">
      <v-data-table
        :headers="historyHeaders"
        :items="historyItems"
        density="comfortable"
        class="bg-transparent borrow-table"
        hover
        item-value="borrow_id"
        :row-props="rowProps"
        @click:row="(_event, { item }) => openDetail(item)"
      >
        <template v-slot:item.rowNumber="{ item }">
          <span class="row-number text-medium-emphasis">{{ historyRowNumber(item) }}</span>
        </template>

        <template v-slot:item.status="{ item }">
          <v-chip
            size="small"
            variant="flat"
            class="font-weight-bold"
            :style="{ backgroundColor: statusAccent(item.status), color: '#FFFFFF' }"
          >
            <v-icon start size="14">{{ statusIcon(item.status) }}</v-icon>
            {{ item.status }}
          </v-chip>
        </template>

        <template v-slot:item.resident="{ item }">
          <v-tooltip :text="`${item.resident?.last_name}, ${item.resident?.first_name}`" location="top">
            <template v-slot:activator="{ props }">
              <div v-bind="props" class="font-weight-bold text-high-emphasis cell-truncate">
                {{ item.resident?.last_name }}, {{ item.resident?.first_name }}
              </div>
            </template>
          </v-tooltip>
        </template>

        <template v-slot:item.barangay="{ item }">
          {{ item.resident?.barangay?.barangay_name || 'N/A' }}
        </template>

        <template v-slot:item.equipment="{ item }">
          {{ item.equipment?.item_name || 'Unknown' }}
          <span class="text-medium-emphasis">&times;{{ item.quantity }}</span>
        </template>

        <template v-slot:item.created_at="{ item }">
          {{ fmtDate(item.created_at) }}
        </template>

        <template v-slot:no-data>
          <div class="text-center py-12">
            <v-icon size="40" class="text-medium-emphasis mb-2">
              {{ activeFilters.length ? 'mdi-filter-remove-outline' : 'mdi-archive-outline' }}
            </v-icon>
            <template v-if="activeFilters.length">
              <div class="text-body-2 font-weight-bold text-high-emphasis">No completed requests match</div>
              <div class="text-caption text-medium-emphasis mb-3">
                {{ totalHistory }} record{{ totalHistory === 1 ? '' : 's' }} are hidden by the filters above.
              </div>
              <v-btn variant="outlined" size="small" class="text-none font-weight-bold" @click="clearAllFilters">
                Clear all filters
              </v-btn>
            </template>
            <template v-else>
              <div class="text-body-2 font-weight-bold text-high-emphasis">No completed requests yet</div>
              <div class="text-caption text-medium-emphasis">
                Returned and denied requests are kept here once they leave the pipeline.
              </div>
            </template>
          </div>
        </template>
      </v-data-table>
    </v-card>

    <!-- Detail modal (full record + fallback actions) -->
    <v-dialog v-model="modal.isOpen" max-width="900" persistent transition="dialog-fade-transition">
      <v-card rounded="lg" elevation="4">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-surface">
          <div class="d-flex align-center gap-3">
            <span class="text-h6 font-weight-bold text-high-emphasis">Borrowing Request Details</span>
            <v-chip
              :color="statusAccent(selectedRecord?.status)"
              size="small" rounded="pill" variant="flat"
              class="text-uppercase font-weight-bold"
            >{{ selectedRecord?.status }}</v-chip>
          </div>
          <v-btn icon="mdi-close" variant="text" size="small" aria-label="Close details" @click="closeModal"></v-btn>
        </v-card-title>

        <v-card-text class="pa-0">
          <v-row class="ma-0 h-100">
            <v-col cols="12" md="5" class="subtle-surface pa-6 border-e">
              <div class="d-flex flex-column align-center mb-6">
                <v-avatar size="80" class="avatar-tint mb-3">
                  <span class="text-h4 font-weight-black avatar-initials">{{ initials(selectedRecord?.resident) }}</span>
                </v-avatar>
                <div class="text-h6 font-weight-bold text-center text-high-emphasis">
                  {{ selectedRecord?.resident?.first_name }} {{ selectedRecord?.resident?.last_name }}
                </div>
                <div class="text-caption text-medium-emphasis text-uppercase font-weight-bold mt-1">Head of the Family Profile</div>
              </div>
              <v-divider class="mb-4"></v-divider>
              <div class="mb-3">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Phone Number</div>
                <div class="font-weight-medium text-body-1 text-high-emphasis">{{ selectedRecord?.resident?.phone_number || 'N/A' }}</div>
              </div>
              <div class="mb-3">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Barangay</div>
                <div class="font-weight-medium text-body-1 text-high-emphasis">{{ selectedRecord?.resident?.barangay?.barangay_name || 'N/A' }}</div>
              </div>
            </v-col>

            <v-col cols="12" md="7" class="pa-6 bg-surface">
              <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact">{{ apiError }}</v-alert>

              <v-alert
                v-if="selectedRecord?.status === 'Denied'"
                type="error" variant="tonal" class="mb-4" density="compact"
                :title="'Request denied'"
              >{{ selectedRecord?.denial_reason || 'No reason was recorded.' }}</v-alert>

              <v-alert
                v-else-if="isOverdue(selectedRecord)"
                type="error" variant="tonal" class="mb-4" density="compact"
                :title="dueLabel(selectedRecord)"
              >This item was due back on {{ fmtDate(selectedRecord?.due_date) }} and has not been returned.</v-alert>

              <h3 class="text-subtitle-1 font-weight-bold mb-4 text-high-emphasis text-uppercase">Equipment Requested</h3>
              <v-card variant="outlined" border class="pa-6 mb-6 rounded-lg subtle-surface d-flex justify-space-between align-center">
                <div>
                  <div class="text-h5 font-weight-black text-high-emphasis">{{ selectedRecord?.equipment?.item_name }}</div>
                  <div class="text-subtitle-2 font-weight-medium text-medium-emphasis mt-1">
                    Current Stock Available:
                    <span class="font-weight-bold" :class="selectedRecord?.equipment?.available_quantity > 0 ? 'text-primary' : 'text-error'">
                      {{ selectedRecord?.equipment?.available_quantity }}
                    </span>
                  </div>
                </div>
                <div class="text-h3 font-weight-black text-high-emphasis">{{ selectedRecord?.quantity }}<span class="text-h5 text-medium-emphasis ml-1">×</span></div>
              </v-card>

              <v-row class="mb-4">
                <v-col cols="6">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Requested On</div>
                  <div class="font-weight-medium text-body-1 text-high-emphasis">{{ fmtDateTime(selectedRecord?.created_at) }}</div>
                </v-col>
                <v-col cols="6" v-if="selectedRecord?.due_date">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Due Back On</div>
                  <div
                    class="font-weight-medium text-body-1"
                    :class="isOverdue(selectedRecord) ? 'text-error' : 'text-high-emphasis'"
                  >{{ fmtDate(selectedRecord?.due_date) }}</div>
                </v-col>
                <v-col cols="6" v-if="selectedRecord?.released_at">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Released On</div>
                  <div class="font-weight-medium text-body-1 text-primary">{{ fmtDateTime(selectedRecord?.released_at) }}</div>
                </v-col>
                <v-col cols="6" v-if="selectedRecord?.returned_at">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Returned On</div>
                  <div class="font-weight-medium text-body-1 text-success">{{ fmtDateTime(selectedRecord?.returned_at) }}</div>
                </v-col>
              </v-row>
            </v-col>
          </v-row>
        </v-card-text>

        <v-card-actions
          v-if="selectedRecord && selectedRecord.status !== 'Returned' && selectedRecord.status !== 'Denied'"
          class="pa-6 d-flex justify-end subtle-surface border-t gap-3"
        >
          <template v-if="selectedRecord.status === 'Pending'">
            <v-btn color="error" variant="outlined" class="px-6 text-none font-weight-bold" height="44" :loading="loading" @click="requestAction(selectedRecord, 'Denied')">Deny Request</v-btn>
            <v-btn color="primary" variant="flat" class="px-6 text-none font-weight-bold" height="44" :loading="loading" @click="requestAction(selectedRecord, 'Approved')">Approve Request</v-btn>
          </template>
          <v-btn v-else-if="selectedRecord.status === 'Approved'" color="primary" variant="flat" class="px-6 text-none font-weight-bold w-100" height="44" :loading="loading" @click="requestAction(selectedRecord, 'Released')">Mark as Released to Resident</v-btn>
          <v-btn v-else-if="selectedRecord.status === 'Released'" color="success" variant="flat" class="px-6 text-none font-weight-bold w-100" height="44" :loading="loading" @click="requestAction(selectedRecord, 'Returned')">Confirm Items Returned</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- One dialog for the three transitions that need something from the
         operator before they fire. -->
    <v-dialog v-model="actionDialog.open" max-width="440" @after-leave="clearActionDialog">
      <v-card rounded="lg">
        <v-card-title class="text-subtitle-1 font-weight-bold pa-5 pb-2 text-high-emphasis">
          {{ actionCopy.title }}
        </v-card-title>
        <v-card-text class="px-5 pt-2">
          <div class="text-body-2 text-medium-emphasis mb-4">{{ actionCopy.body }}</div>

          <v-alert
            v-if="actionDialog.mode === 'confirm' && actionDialog.error"
            type="error" variant="tonal" density="compact" class="mb-4"
          >{{ actionDialog.error }}</v-alert>

          <v-text-field
            v-if="actionDialog.mode === 'due'"
            v-model="actionDialog.dueDate"
            type="date"
            :min="todayInput()"
            label="Due back on"
            variant="outlined"
            density="comfortable"
            :error-messages="actionDialog.error"
            @update:model-value="actionDialog.error = ''"
          ></v-text-field>

          <v-textarea
            v-else-if="actionDialog.mode === 'deny'"
            v-model="actionDialog.reason"
            label="Reason for denial"
            hint="The resident is shown this. Say what would make a future request succeed."
            persistent-hint
            variant="outlined"
            rows="3"
            counter="255"
            maxlength="255"
            :error-messages="actionDialog.error"
            @update:model-value="actionDialog.error = ''"
          ></v-textarea>
        </v-card-text>
        <v-card-actions class="px-5 pb-5 pt-0 justify-end gap-3">
          <v-btn variant="text" class="text-none font-weight-bold" height="44" @click="actionDialog.open = false">Cancel</v-btn>
          <v-btn
            :color="actionDialog.status === 'Denied' ? 'error' : 'primary'"
            variant="flat"
            class="px-6 text-none font-weight-bold"
            height="44"
            :loading="loading"
            @click="confirmAction"
          >{{ actionCopy.confirm }}</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3500" location="bottom right" rounded="lg">
      {{ snackbar.text }}
    </v-snackbar>

    <!-- The snackbar is not a live region — Vuetify mounts it on show, and a
         region that appears at the same moment as its text is not reliably
         announced. This span is always in the DOM, so a request moving from
         Pending to Approved is spoken instead of happening in silence. -->
    <span class="sr-only" role="status" aria-live="polite">{{ liveMessage }}</span>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { getToken } from '@/composables/authToken'
import { useRowNumbers } from '@/composables/rowNumber'
import { API_BASE } from '@/config/api'

const route = useRoute()

// Status colours: saturated 700-level ramp, each AA with white text as a
// badge (measured, see EquipmentBorrowingView audit history). Semantic
// (data-viz), not brand tokens — except Returned, which uses the system
// primary green (success tracks primary).
const columns = [
  { status: 'Pending',  label: 'Pending',  accent: '#B45309', icon: 'mdi-clock-outline' },
  { status: 'Approved', label: 'Approved', accent: '#1D4ED8', icon: 'mdi-check-decagram-outline' },
  { status: 'Released', label: 'Released', accent: '#0E7490', icon: 'mdi-hand-extended-outline' },
  { status: 'Returned', label: 'Returned', accent: '#297A67', icon: 'mdi-check-circle-outline', terminal: true },
  { status: 'Denied',   label: 'Denied',   accent: '#B91C1C', icon: 'mdi-close-circle-outline', terminal: true },
]

// The "no filter" sentinel for each select. Named rather than repeated as a
// string literal: it is compared in four places and rendered in one.
const ALL_ITEMS = 'All items'
const ALL_BARANGAYS = 'All barangays'
const ALL_STATUS = 'All'
const ALL_OUTCOMES = 'All'

const borrowings = ref([])
const activeTab = ref('board')
const search = ref('')
const itemFilter = ref(ALL_ITEMS)
const barangayFilter = ref(ALL_BARANGAYS)
const statusFilter = ref(ALL_STATUS)
const outcomeFilter = ref(ALL_OUTCOMES)
const overdueOnly = ref(false)
const initialLoad = ref(true)

// Dashboard KPI cards deep-link here with ?status=... / ?overdue=1 — honor
// them once on arrival so the operator lands on the filtered view.
if (columns.some((c) => c.status === route.query.status)) statusFilter.value = route.query.status
if (route.query.overdue === '1') overdueOnly.value = true
const loading = ref(false)
const reloading = ref(false)
const processingId = ref(null)
const apiError = ref('')
const loadError = ref('')
const liveMessage = ref('')
const modal = ref({ isOpen: false })
const selectedRecord = ref(null)
const snackbar = ref({ show: false, text: '', color: 'success' })

const emptyAction = () => ({
  open: false,
  mode: null,
  status: null,
  record: null,
  dueDate: '',
  reason: '',
  error: '',
})
const actionDialog = ref(emptyAction())

const notify = (text, color = 'success') => {
  snackbar.value = { show: true, text, color }
  // Re-announce even when the same text repeats: an unchanged live region is
  // not read again, and denying two requests in a row is two events.
  liveMessage.value = ''
  requestAnimationFrame(() => { liveMessage.value = text })
}

const terminalStatuses = columns.filter((c) => c.terminal).map((c) => c.status)

const activeHeaders = [
  { title: '#', key: 'rowNumber', sortable: false, align: 'center', width: '64px' },
  { title: '', key: 'avatar', sortable: false, align: 'center', width: '60px' },
  { title: 'Head of the Family', key: 'resident', width: '20%' },
  { title: 'Barangay', key: 'barangay', width: '12%' },
  { title: 'Equipment', key: 'equipment', width: '22%' },
  { title: 'Status', key: 'status', align: 'center', width: '13%' },
  { title: 'Timeline', key: 'timeline', width: '13%' },
  { title: '', key: 'actions', sortable: false, align: 'end', width: '11%' },
]

const historyHeaders = [
  { title: '#', key: 'rowNumber', sortable: false, align: 'center', width: '64px' },
  { title: 'Head of the Family', key: 'resident', width: '21%' },
  { title: 'Barangay', key: 'barangay', width: '15%' },
  { title: 'Equipment', key: 'equipment', width: '24%' },
  { title: 'Requested', key: 'created_at', width: '17%' },
  { title: 'Outcome', key: 'status', align: 'center', width: '16%' },
]

// Sourced from the master lists (/equipments, /barangays — same endpoints
// EquipmentInventoryView and SmsView/UsersView already use), not from
// borrowings.value. The prior version derived options from loaded records
// only, so an item or barangay with zero borrowings could never even be
// selected as a filter (impeccable ui-audit, 2026-08-30) — a barangay
// genuinely having no current borrowers is exactly the case a filter
// exists to confirm, not a case to hide.
const equipmentMaster = ref([])
const barangayMaster = ref([])

const itemOptions = computed(() => [
  ALL_ITEMS,
  ...[...equipmentMaster.value]
    .map((e) => e.item_name)
    .filter(Boolean)
    .sort((a, b) => a.localeCompare(b)),
])
const barangayOptions = computed(() => [
  ALL_BARANGAYS,
  ...[...barangayMaster.value]
    .map((b) => b.barangay_name)
    .filter(Boolean)
    .sort((a, b) => a.localeCompare(b)),
])

// `clearable` writes null, not '', so the guard is not decorative.
const matchesSearch = (b) => {
  const q = (search.value || '').trim().toLowerCase()
  if (!q) return true
  const name = `${b.resident?.first_name || ''} ${b.resident?.last_name || ''}`.toLowerCase()
  const item = (b.equipment?.item_name || '').toLowerCase()
  return name.includes(q) || item.includes(q)
}

const matchesItem = (b) =>
  itemFilter.value === ALL_ITEMS || b.equipment?.item_name === itemFilter.value

const matchesBarangay = (b) =>
  barangayFilter.value === ALL_BARANGAYS ||
  b.resident?.barangay?.barangay_name === barangayFilter.value

// The three filters shared by both tabs and the status strip's tile counts.
// Status and overdue are not among them — each tile needs the count as if
// it, specifically, were the only one applied.
const matchesFilters = (b) => matchesItem(b) && matchesBarangay(b) && matchesSearch(b)

const countByStatus = (status) =>
  borrowings.value.filter((b) => b.status === status && matchesFilters(b)).length

// Tile row above the table — the pipeline glance the kanban columns used to
// carry, without a fixed-width board.
const statusTiles = computed(() =>
  columns.filter((c) => !c.terminal).map((c) => ({ ...c, count: countByStatus(c.status) })),
)

const toggleStatusFilter = (status) => {
  statusFilter.value = statusFilter.value === status ? ALL_STATUS : status
}

// Hidden at zero, shown once there's something behind it — same rule
// Service Requests applies to its own status tabs via visibleStatusTabs.
const terminalTiles = computed(() =>
  columns
    .filter((c) => c.terminal)
    .map((c) => ({ ...c, count: countByStatus(c.status) }))
    .filter((c) => c.count > 0),
)

// Returned/Denied rows never appear in this table — they're terminal, so the
// tile jumps to History pre-filtered to that outcome rather than pretending
// to filter a table that structurally excludes them.
const goToOutcome = (status) => {
  activeTab.value = 'history'
  outcomeFilter.value = status
}

// Overdue-first, then pipeline stage, then oldest-waiting first: the request
// that has sat longest is the one due for a decision, not the newest one to
// arrive.
const STAGE_RANK = { Pending: 0, Approved: 1, Released: 2 }

const activeItems = computed(() => {
  const rows = borrowings.value.filter(
    (b) => STAGE_RANK[b.status] !== undefined && matchesFilters(b),
  )
  return rows
    .filter((b) => statusFilter.value === ALL_STATUS || b.status === statusFilter.value)
    .filter((b) => !overdueOnly.value || isOverdue(b))
    .sort((a, b) => {
      const byOverdue = Number(isOverdue(b)) - Number(isOverdue(a))
      if (byOverdue) return byOverdue
      const byStage = STAGE_RANK[a.status] - STAGE_RANK[b.status]
      if (byStage) return byStage
      return new Date(a.created_at) - new Date(b.created_at)
    })
})

// Terminal records, same shared filters as the board so a search spans both
// tabs rather than quietly applying to one of them.
const outcomeOptions = [ALL_OUTCOMES, ...terminalStatuses]

const historyItems = computed(() =>
  borrowings.value
    .filter((b) => terminalStatuses.includes(b.status) && matchesFilters(b))
    .filter((b) => outcomeFilter.value === ALL_OUTCOMES || b.status === outcomeFilter.value)
    .sort((a, b) => new Date(b.created_at) - new Date(a.created_at)),
)

// Two tables, two independent counts — a borrowing is numbered within the
// list it actually appears in, and the same record never shows in both.
const activeRowNumber = useRowNumbers(activeItems, 'borrow_id')
const historyRowNumber = useRowNumbers(historyItems, 'borrow_id')

// What the "Overdue" tile would leave if it were the only thing active — the
// other filters still apply, or the number would not describe the tile that
// carries it.
const overdueCount = computed(
  () => borrowings.value.filter((b) => matchesFilters(b) && isOverdue(b)).length,
)

const activeFilters = computed(() => {
  const out = []
  const q = (search.value || '').trim()
  if (q) out.push({ key: 'search', label: `Search: "${q}"` })
  if (itemFilter.value !== ALL_ITEMS) out.push({ key: 'item', label: `Equipment: ${itemFilter.value}` })
  if (barangayFilter.value !== ALL_BARANGAYS) out.push({ key: 'barangay', label: `Barangay: ${barangayFilter.value}` })
  // Status and overdue only act on the board, outcome only on History — each
  // chip only appears on the tab it actually filters.
  if (activeTab.value === 'board') {
    if (statusFilter.value !== ALL_STATUS) out.push({ key: 'status', label: `Status: ${statusFilter.value}` })
    if (overdueOnly.value) out.push({ key: 'overdue', label: 'Overdue only' })
  } else if (outcomeFilter.value !== ALL_OUTCOMES) {
    out.push({ key: 'outcome', label: `Outcome: ${outcomeFilter.value}` })
  }
  return out
})

const clearFilter = (key) => {
  if (key === 'search') search.value = ''
  else if (key === 'item') itemFilter.value = ALL_ITEMS
  else if (key === 'barangay') barangayFilter.value = ALL_BARANGAYS
  else if (key === 'status') statusFilter.value = ALL_STATUS
  else if (key === 'overdue') overdueOnly.value = false
  else if (key === 'outcome') outcomeFilter.value = ALL_OUTCOMES
}

const clearAllFilters = () => {
  search.value = ''
  itemFilter.value = ALL_ITEMS
  barangayFilter.value = ALL_BARANGAYS
  statusFilter.value = ALL_STATUS
  outcomeFilter.value = ALL_OUTCOMES
  overdueOnly.value = false
}

const totalActive = computed(
  () => borrowings.value.filter((b) => !terminalStatuses.includes(b.status)).length,
)
const totalHistory = computed(
  () => borrowings.value.filter((b) => terminalStatuses.includes(b.status)).length,
)

// Counts the tab actually showing, against that tab's unfiltered total. Says
// "of" only when something is being hidden, so the line is not a permanent
// accusation that a filter is on.
const resultSummary = computed(() => {
  const history = activeTab.value === 'history'
  const total = history ? totalHistory.value : totalActive.value
  const shown = history ? historyItems.value.length : activeItems.value.length
  const noun = history ? 'completed' : 'active'
  if (shown === total) return `${total} ${noun} request${total === 1 ? '' : 's'}`
  return `Showing ${shown} of ${total} ${noun} requests`
})

const initials = (r) => `${r?.first_name?.charAt(0) || ''}${r?.last_name?.charAt(0) || ''}`
// Names a record for an accessible label: who and what, which is what tells
// two otherwise identical "Approve" buttons apart.
const cardLabel = (item) =>
  `${item.equipment?.item_name || 'equipment'} for ${item.resident?.first_name || ''} ${item.resident?.last_name || ''}`.trim()
const shortStock = (item) => (item.equipment?.available_quantity ?? 0) < item.quantity
const statusAccent = (status) => columns.find((c) => c.status === status)?.accent || '#64748B'
const statusIcon = (status) => columns.find((c) => c.status === status)?.icon || 'mdi-help-circle-outline'

const fmtDateTime = (iso) => iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''

// `due_date` is a calendar date, not an instant. `new Date('2026-08-10')` parses
// as UTC midnight, which is the 9th in any timezone west of Greenwich and shifts
// the whole overdue calculation by a day; building from the parts keeps it local.
// The slice tolerates a legacy row that serialised a time along with the date.
const parseDay = (value) => {
  if (!value) return null
  const [y, m, d] = String(value).slice(0, 10).split('-').map(Number)
  if (!y || !m || !d) return null
  return new Date(y, m - 1, d)
}
// Two kinds of value reach this. `due_date` is a bare calendar date and must be
// read as-is; `created_at` is a UTC instant and must be converted, or a record
// filed at 21:00 Manila time reports the previous day. Anything carrying a time
// is an instant.
const fmtDate = (value) => {
  if (!value) return ''
  const hasTime = /[T ]\d{2}:/.test(String(value))
  const d = hasTime ? new Date(value) : parseDay(value)
  return d ? d.toLocaleDateString(undefined, { dateStyle: 'medium' }) : ''
}

const pad = (n) => String(n).padStart(2, '0')
const toDateInput = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
const todayInput = () => toDateInput(new Date())
const daysFromToday = (n) => {
  const d = new Date()
  d.setDate(d.getDate() + n)
  return toDateInput(d)
}

// Whole days between today and the due date, both taken at local midnight so a
// request due later today reads 0 rather than a fraction of a day.
const dueDelta = (item) => {
  const due = parseDay(item?.due_date)
  if (!due) return null
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return Math.round((due - today) / 86400000)
}

// A returned or denied record cannot be overdue, however far past its date it
// sits — the item is back, or it never left.
const isOverdue = (item) => {
  if (!item || terminalStatuses.includes(item.status)) return false
  const delta = dueDelta(item)
  return delta !== null && delta < 0
}

const dueLabel = (item) => {
  const delta = dueDelta(item)
  if (delta === null) return ''
  if (delta < 0) return `${-delta} day${delta === -1 ? '' : 's'} overdue`
  if (delta === 0) return 'Due today'
  if (delta === 1) return 'Due tomorrow'
  return `Due in ${delta} days`
}

// Aging measures from the moment the record entered its current stage, so a
// released item reports how long it has been out rather than how long ago the
// resident first asked. `released_at` is null on rows released before it was
// recorded, hence the fallback.
const agingLabel = (item) => {
  const released = item.status === 'Released'
  const anchor = (released && item.released_at) || item.created_at
  const days = Math.floor((Date.now() - new Date(anchor).getTime()) / 86400000)
  if (days <= 0) return released ? 'Out today' : 'Today'
  return `${days}d ${released ? 'out' : 'waiting'}`
}

const getHeaders = () => ({
  Authorization: `Bearer ${getToken()}`,
  'Content-Type': 'application/json',
  Accept: 'application/json',
})

// A non-2xx used to fall straight through: `res.json()` on an error body assigns
// whatever came back to `borrowings`, so a 401 or a 500 rendered as an empty
// board rather than as a failure.
const fetchData = async () => {
  reloading.value = true
  try {
    const res = await fetch(`${API_BASE}/borrowings`, { headers: getHeaders() })
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      throw new Error(errData.message || `Request failed (${res.status})`)
    }
    const data = await res.json()
    const rows = data.data || data
    if (!Array.isArray(rows)) throw new Error('The server returned an unexpected response')
    borrowings.value = rows
    loadError.value = ''
  } catch (error) {
    console.error('Failed to fetch borrowings:', error)
    // Kept on the page, not only in a snackbar that clears itself after 3.5s.
    loadError.value = error.message || 'Could not reach the server'
    notify('Could not load borrowings', 'error')
  } finally {
    initialLoad.value = false
    reloading.value = false
  }
}

// Failures here are non-fatal to the page — the filters just fall back to
// showing only "All items"/"All barangays" until they load, same as any
// other list this app fetches for a picker rather than for primary content.
const fetchMasterLists = async () => {
  try {
    const [equipRes, barangayRes] = await Promise.all([
      fetch(`${API_BASE}/equipments`, { headers: getHeaders() }),
      fetch(`${API_BASE}/barangays`, { headers: getHeaders() }),
    ])
    if (equipRes.ok) {
      const data = await equipRes.json()
      equipmentMaster.value = data.data || data
    }
    if (barangayRes.ok) {
      const data = await barangayRes.json()
      barangayMaster.value = data.data || data
    }
  } catch (error) {
    console.error('Failed to fetch equipment/barangay master lists:', error)
  }
}

const openDetail = (item) => {
  apiError.value = ''
  selectedRecord.value = item
  modal.value.isOpen = true
}

// `@click:row` alone is mouse-only — a `<tr>` handler is unreachable by
// keyboard. Rows are focusable and open on Enter/Space, and arrows walk the
// list the way a native listbox would, matching User Management's table.
const onRowKeydown = (event, item) => {
  const row = event.currentTarget
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault()
    openDetail(item)
    return
  }
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    const next = event.key === 'ArrowDown' ? row.nextElementSibling : row.previousElementSibling
    if (next && next.tagName === 'TR') next.focus()
  }
}

const rowProps = ({ item }) => ({
  tabindex: 0,
  'aria-label': `Open details for ${item.resident?.last_name}, ${item.resident?.first_name}`,
  onKeydown: (e) => onRowKeydown(e, item),
})

const closeModal = () => {
  modal.value.isOpen = false
  selectedRecord.value = null
}

// Default fortnight-minus-a-week: a week is the office's usual loan and the
// operator can move it in the dialog. It is a default, never a silent write —
// the date is always shown before the request goes out.
const DEFAULT_LOAN_DAYS = 7

const requestAction = (record, newStatus) => {
  const next = { ...emptyAction(), open: true, status: newStatus, record }
  if (newStatus === 'Denied') {
    next.mode = 'deny'
  } else if (newStatus === 'Approved') {
    next.mode = 'due'
    next.dueDate = record.due_date ? String(record.due_date).slice(0, 10) : daysFromToday(DEFAULT_LOAN_DAYS)
  } else if (newStatus === 'Released') {
    // A request approved through this panel already carries a date, so releasing
    // it asks nothing. Rows approved before due dates existed still need one —
    // otherwise they leave the shelf with no date and can never be overdue.
    if (record.due_date) return updateStatus(record, newStatus)
    next.mode = 'due'
    next.dueDate = daysFromToday(DEFAULT_LOAN_DAYS)
  } else {
    next.mode = 'confirm'
  }
  actionDialog.value = next
}

const actionCopy = computed(() => {
  const record = actionDialog.value.record
  const who = `${record?.resident?.first_name || ''} ${record?.resident?.last_name || ''}`.trim() || 'this resident'
  const what = record?.equipment?.item_name || 'the equipment'
  switch (actionDialog.value.mode) {
    case 'deny':
      return { title: 'Deny this request', body: `${who} asked for ${what}.`, confirm: 'Deny request' }
    case 'due':
      return {
        title: actionDialog.value.status === 'Approved' ? 'Approve this request' : 'Release to resident',
        body: `Set the date ${who} is expected to bring ${what} back.`,
        confirm: actionDialog.value.status === 'Approved' ? 'Approve request' : 'Release',
      }
    default:
      return {
        title: 'Confirm the return',
        body: `This puts ${record?.quantity ?? ''}× ${what} back into stock. There is no undo.`,
        confirm: 'Confirm return',
      }
  }
})

const clearActionDialog = () => { actionDialog.value = emptyAction() }

const confirmAction = () => {
  const { mode, status, record, dueDate, reason } = actionDialog.value
  if (mode === 'due' && !dueDate) {
    actionDialog.value.error = 'Pick a due date'
    return
  }
  if (mode === 'deny' && !reason.trim()) {
    actionDialog.value.error = 'Give a reason — the resident is shown this'
    return
  }
  const extra = {}
  if (mode === 'due') extra.due_date = dueDate
  if (mode === 'deny') extra.denial_reason = reason.trim()
  return updateStatus(record, status, extra)
}

const updateStatus = async (record, newStatus, extra = {}) => {
  const id = record.borrow_id || record.id
  loading.value = true
  processingId.value = id
  apiError.value = ''
  try {
    const res = await fetch(`${API_BASE}/borrowings/${id}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({ status: newStatus, ...extra }),
    })
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      throw new Error(errData.message || 'Failed to update status')
    }
    await fetchData()
    notify(`Request marked ${newStatus}`)
    actionDialog.value.open = false
    if (modal.value.isOpen) closeModal()
  } catch (error) {
    // The dialog stays open on failure. Closing it would drop a typed denial
    // reason on the floor and leave the snackbar as the only trace of the error.
    apiError.value = error.message
    actionDialog.value.error = error.message
    notify(error.message, 'error')
  } finally {
    loading.value = false
    processingId.value = null
  }
}

onMounted(() => {
  fetchData()
  fetchMasterLists()
})
</script>

<style scoped>
.gap-1 { gap: 4px; }
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.min-w-0 { min-width: 0; }

/* Vertical rhythm for the page's bands. They used to run mb-6, mb-4, mb-4,
   mb-4, mb-4, mb-5 from the heading down -- one value repeated four times,
   with a 20px that is not tellable from a 16px. Everything therefore read as
   an equal sibling of everything else, and nothing marked where the controls
   stopped and the data began.

   Two intervals now. 8px holds the two halves of the filter block together --
   the selects and the chips showing what those selects did are one thought
   split across two rows. 28px is the region break. The status strip sits 16px
   above the table because it counts the rows in it; it is a caption for that
   table, not a band of its own. */
.page-header { margin-bottom: 28px; }
.page-tabs { margin-bottom: 24px; }
.filter-active { margin-bottom: 28px; }
.status-strip { margin-bottom: 16px; }

/* Filter bar. Fixed-width fields that wrap rather than a grid: field count
   can vary and a fixed column count would leave gaps on narrow screens. */
.filter-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  margin-bottom: 8px;
}
.filter-field { width: 220px; max-width: 100%; }

@media (max-width: 599px) {
  .filter-field { flex: 1 1 100%; width: 100%; }
}

/* primary-strong again, not primary. Vuetify's tonal chip draws the label on a
   12% tint of the same colour, which measures 4.40:1 and fails AA at this
   weight. Outlined puts the label on the page surface instead. */
.filter-chip {
  color: rgb(var(--v-theme-primary-strong));
  border-color: rgba(var(--v-theme-primary), 0.45);
}

/* Status strip. Same mechanic as Fleet Management's readiness tiles: a dot,
   a count, a label, click to filter the table to that stage. */
.stat-tile {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 16px;
  border-radius: 12px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.1);
  background: rgba(var(--v-theme-on-surface), 0.02);
  cursor: pointer;
  transition: border-color 0.15s ease, background-color 0.15s ease;
}
.stat-tile--active {
  border-color: var(--tile-accent);
  background: color-mix(in srgb, var(--tile-accent) 10%, transparent);
}
/* Returned and Denied do not filter the table underneath them -- they switch
   to the History tab. Three tiles that look identical while one group leaves
   the surface is the whole confusion, so these carry a departure arrow and a
   dashed edge: still a tile, visibly not the same kind of tile. */
.stat-tile--link {
  border-style: dashed;
}
.stat-tile__go {
  margin-left: 2px;
  opacity: 0.5;
}

.stat-tile .dot { width: 9px; height: 9px; border-radius: 50%; flex: none; }
.stat-tile .stat-value { font-size: 1.15rem; font-weight: 800; line-height: 1; }
.stat-tile .stat-label { font-size: 0.8rem; font-weight: 600; }

/* Table. Fixed layout keeps the truncating cells stable; matches the
   elegant-table pattern used across User Management and Fleet Management.
   The 720px min-width is load-bearing: without it, `width: 100%` on a fixed
   table lets a narrow wrapper crush every column instead of scrolling —
   "waiting" wraps to one letter per line rather than the table scrolling
   sideways. The wrapper's own overflow-x (Vuetify's default) does the rest. */
.borrow-table :deep(table) { table-layout: fixed !important; width: 100% !important; min-width: 784px; }
.row-number {
  font-size: 0.95rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.borrow-table :deep(thead th) {
  font-size: 0.72rem !important;
  font-weight: 700 !important;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  white-space: nowrap;
}
.borrow-table :deep(tbody tr) { cursor: pointer; }
.borrow-table :deep(td) { white-space: nowrap; }
.borrow-table :deep(tbody tr:focus-visible) {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}
.cell-truncate {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Avatar — the old blue-on-light-blue pairing measured 3.28:1 elsewhere in
   this app; tinting the primary token keeps the same soft look and passes AA
   in both themes. Shared naming with User Management's identical fix. */
.avatar-tint {
  background: rgba(var(--v-theme-primary), 0.14) !important;
}
.avatar-initials {
  /* Not primary: primary itself on a 14% tint is 4.25:1 and fails AA at this
     size. See the token comment in plugins/vuetify.ts. */
  color: rgb(var(--v-theme-primary-strong));
  font-weight: 800;
  letter-spacing: 0.02em;
}

/* Visible to a screen reader, to nothing else. clip-path rather than
   display:none or visibility:hidden, both of which remove the node from the
   accessibility tree and would silence the live region entirely. */
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

@media (prefers-reduced-motion: reduce) {
  .stat-tile { transition: none; }
}
</style>
