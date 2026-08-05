<template>
  <v-container fluid class="align-start pa-6 bg-background" style="min-height: 100vh;">

    <!-- Header -->
    <div class="mb-6">
      <h2 class="text-h5 font-weight-bold text-high-emphasis">Equipment Borrowing</h2>
      <div class="text-subtitle-2 text-medium-emphasis">
        Move each request through the pipeline — approve, release, then confirm its return
      </div>
    </div>

    <!-- Returned and Denied are finished work. They were taking 40% of the
         board's width from the three states that still need a decision, and
         they are the two that grow without bound as the office keeps operating,
         so they are the two that cannot stay on a fixed-width board. -->
    <v-tabs v-model="activeTab" color="primary" class="mb-4 border-b">
      <v-tab value="board" class="text-none font-weight-bold">
        <v-icon start>mdi-view-column-outline</v-icon>
        Active pipeline
        <v-chip size="x-small" variant="tonal" class="ml-2 font-weight-bold">{{ activeCount }}</v-chip>
      </v-tab>
      <v-tab value="history" class="text-none font-weight-bold">
        <v-icon start>mdi-archive-outline</v-icon>
        History
        <v-chip size="x-small" variant="tonal" class="ml-2 font-weight-bold">{{ historyItems.length }}</v-chip>
      </v-tab>
    </v-tabs>

    <!-- The filters sit under the tabs, not in the header, because they apply to
         whichever surface is showing and reading them second makes that order
         explicit. Every control now carries a visible label: the old bar was
         placeholder-only, so a chosen item filter became invisible the moment it
         was applied and a board emptied by a stale filter read as an empty
         database. -->
    <div v-if="!loadError" class="filter-bar mb-4">
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

      <!-- Board only, and hidden rather than disabled on History: a returned or
           denied record can never be overdue, so there the switch would filter
           every row away and read as a broken page. The count rides on the label
           so the number is available without turning the filter on. -->
      <v-switch
        v-if="activeTab === 'board'"
        v-model="overdueOnly"
        :label="`Overdue only (${overdueCount})`"
        color="error"
        density="compact"
        hide-details
        inset
        class="overdue-switch"
      ></v-switch>
    </div>

    <!-- Active filters, each removable on its own, plus a clear-all. The count
         line is a live region: filtering changes the whole page silently
         otherwise, and it is the only feedback that says a filter — rather than
         an empty queue — is why three columns are bare. -->
    <div v-if="!initialLoad && !loadError" class="d-flex align-center flex-wrap gap-2 mb-4">
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

    <!-- An action that failed from the board used to leave no trace once the
         snackbar timed out, 3.5 seconds later. It is the same error the modal
         has always shown in place; the board simply had nowhere to put it. -->
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

    <!-- A failed load used to render as three columns of "Nothing here", which
         is indistinguishable from an empty database — the operator would read a
         dead API as a quiet morning. -->
    <v-card v-else-if="loadError" elevation="0" border rounded="xl" class="bg-surface">
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

    <!-- Column membership is the entire meaning of this board and was invisible
         to a screen reader: an unlabelled div of unlabelled sections. Each
         column now names itself and its count, and the cards are a real list, so
         "Pending, 4 requests, list, 4 items" arrives before the first card. -->
    <div
      v-else-if="activeTab === 'board'"
      class="kanban-board"
      role="region"
      aria-label="Active borrowing pipeline"
    >
      <section
        v-for="col in boardColumns"
        :key="col.status"
        class="kanban-column subtle-surface"
        :class="{ 'kanban-column--muted': col.muted }"
        :aria-label="`${col.label}, ${grouped[col.status].length} ${grouped[col.status].length === 1 ? 'request' : 'requests'}`"
      >
        <!-- The icon and the count badge are both decorative here: the label
             above already carries the status word and the number in text, and
             announcing either twice is worse than not announcing it. The badge
             was also the page's last piece of colour-only meaning — it is a
             coloured pill holding a bare number, and the section label is what
             now supplies its text alternative. -->
        <header class="kanban-header" :style="{ '--accent': col.accent }">
          <div class="d-flex align-center gap-2">
            <v-icon size="18" aria-hidden="true" :style="{ color: col.accent }">{{ col.icon }}</v-icon>
            <span class="text-subtitle-2 font-weight-bold text-high-emphasis">{{ col.label }}</span>
          </div>
          <span class="count-badge" aria-hidden="true" :style="{ backgroundColor: col.accent }">{{ grouped[col.status].length }}</span>
        </header>

        <ul class="kanban-body">
          <!-- The card is no longer itself a button. It used to be a
               role="button" div wrapping action buttons neutralised with
               @click.stop, so four pixels of misclick between "Approve" and
               card background produced a modal instead of a state change.
               Identity and actions are siblings now, and the hover lift went
               with it: columns of liftable cards are the visual grammar of
               drag-and-drop, which this board has never implemented. -->
          <li
            v-for="item in grouped[col.status]"
            :key="item.borrow_id || item.id"
            class="kanban-card"
            :class="{ 'kanban-card--overdue': isOverdue(item) }"
          >
            <button
              type="button"
              class="card-identity"
              :aria-label="`Open details for ${item.resident?.last_name}, ${item.resident?.first_name}`"
              @click="openDetail(item)"
            >
              <v-avatar size="34" color="rgba(var(--v-theme-primary), 0.14)">
                <span class="avatar-initials">{{ initials(item.resident) }}</span>
              </v-avatar>
              <span class="min-w-0 flex-grow-1">
                <span class="d-block resident-name text-truncate">
                  {{ item.resident?.last_name }}, {{ item.resident?.first_name }}
                </span>
                <span class="d-block text-caption text-medium-emphasis text-truncate">
                  {{ item.resident?.barangay?.barangay_name || 'N/A' }}
                </span>
              </span>
            </button>

            <div class="equip-line">
              <v-icon size="16" class="text-medium-emphasis mr-1">mdi-package-variant-closed</v-icon>
              <span class="text-body-2 font-weight-medium text-high-emphasis text-truncate">
                {{ item.equipment?.item_name || 'Unknown' }}
              </span>
              <span class="qty-pill">{{ item.quantity }}×</span>
            </div>

            <!-- Stock warning for still-actionable stages -->
            <div
              v-if="!col.terminal && shortStock(item)"
              class="stock-warn"
            >
              <v-icon size="14" aria-hidden="true" class="mr-1">mdi-alert-outline</v-icon>
              Only {{ item.equipment?.available_quantity ?? 0 }} in stock
            </div>

            <!-- Both chips carry their own words. Overdue is never the red
                 alone: a card that has simply been waiting a while and one that
                 is a week late must read differently in greyscale. -->
            <div class="card-meta">
              <span class="meta-chip">
                <v-icon size="13" aria-hidden="true" class="mr-1">mdi-clock-outline</v-icon>{{ agingLabel(item) }}
              </span>
              <span
                v-if="dueLabel(item)"
                class="meta-chip"
                :class="{ 'meta-chip--alert': isOverdue(item) }"
              >
                <v-icon size="13" aria-hidden="true" class="mr-1">
                  {{ isOverdue(item) ? 'mdi-alert-circle-outline' : 'mdi-calendar-arrow-right' }}
                </v-icon>{{ dueLabel(item) }}
              </span>
            </div>

            <!-- Deny is outlined rather than tonal: Vuetify's tonal variant
                 draws the label on a 12% tint of the same colour, which is the
                 pairing that already failed AA three times on this page. On the
                 card surface the red measures 4.98:1, and the border carries
                 the weight that makes it the equal of Approve. -->
            <!-- Every action names its record. Read out of context — which is
                 how a screen reader reaches them, one list item at a time —
                 "Deny" alone does not say what is being denied, and there are
                 four of them on screen. -->
            <div class="card-actions">
              <template v-if="item.status === 'Pending'">
                <v-btn
                  size="small" variant="outlined" color="error" class="text-none font-weight-bold flex-grow-1"
                  :loading="processingId === (item.borrow_id || item.id)"
                  :aria-label="`Deny ${cardLabel(item)}`"
                  @click="requestAction(item, 'Denied')"
                >Deny</v-btn>
                <v-btn
                  size="small" variant="flat" color="primary" class="text-none font-weight-bold flex-grow-1"
                  :loading="processingId === (item.borrow_id || item.id)"
                  :aria-label="`Approve ${cardLabel(item)}`"
                  @click="requestAction(item, 'Approved')"
                >Approve</v-btn>
              </template>
              <v-btn
                v-else-if="item.status === 'Approved'"
                block size="small" variant="flat" color="primary" class="text-none font-weight-bold"
                :loading="processingId === (item.borrow_id || item.id)"
                :aria-label="`Release ${cardLabel(item)}`"
                @click="requestAction(item, 'Released')"
              >Release to resident</v-btn>
              <v-btn
                v-else-if="item.status === 'Released'"
                block size="small" variant="flat" color="primary" class="text-none font-weight-bold"
                :loading="processingId === (item.borrow_id || item.id)"
                :aria-label="`Confirm return of ${cardLabel(item)}`"
                @click="requestAction(item, 'Returned')"
              >Confirm return</v-btn>
            </div>
          </li>

          <!-- A column empty because the work is done and one empty because a
               filter excluded everything are different facts and must not read
               the same. It is an <li> rather than a div because a <ul> may only
               contain list items, and each column says what would put a card
               here — "Nothing here" three times told the operator nothing. -->
          <li v-if="!grouped[col.status].length" class="kanban-empty">
            <v-icon size="20" aria-hidden="true" class="text-medium-emphasis mb-1">
              {{ activeFilters.length ? 'mdi-filter-remove-outline' : col.emptyIcon }}
            </v-icon>
            <div class="text-caption text-medium-emphasis">
              {{ activeFilters.length ? 'No matches here' : col.emptyText }}
            </div>
          </li>
        </ul>
      </section>
    </div>

    <!-- History. A table rather than columns: this list only ever grows, and
         the questions asked of it are lookups ("did the Cruz family return the
         generator?") rather than the glance the board exists to serve. -->
    <v-card v-else elevation="0" border rounded="xl" class="bg-surface">
      <!-- No :search prop. `historyItems` has already applied the same search
           the board uses; the table's own filter would run a second pass over
           `resident` and `equipment`, whose values are objects rather than
           text, and drop rows that in fact matched. -->
      <!-- Rows open the same detail modal the board does. Denied records live
           only here, and the denial reason is only rendered in that modal, so
           without this the reason would be written and never read. -->
      <v-data-table
        :headers="historyHeaders"
        :items="historyItems"
        density="comfortable"
        class="bg-transparent history-table"
        hover
        @click:row="(_event, { item }) => openDetail(item)"
      >
        <template v-slot:item.status="{ item }">
          <!-- Icon + text, never colour alone: Returned and Denied are the one
               pair on this page a red/green-blind user must still tell apart. -->
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

        <!-- The row click is mouse-only — a <tr> handler is unreachable by
             keyboard — so the name is a real button, the same arrangement the
             board card uses. Without it the denial reason is readable with a
             mouse and by no other means. -->
        <template v-slot:item.resident="{ item }">
          <button
            type="button"
            class="row-identity font-weight-bold text-high-emphasis"
            :aria-label="`Open details for ${cardLabel(item)}`"
            @click.stop="openDetail(item)"
          >
            {{ item.resident?.last_name }}, {{ item.resident?.first_name }}
          </button>
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
                Returned and denied requests are kept here once they leave the board.
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
                <v-avatar color="rgba(var(--v-theme-primary), 0.14)" size="80" class="mb-3">
                  <span class="text-h4 font-weight-black text-primary">{{ initials(selectedRecord?.resident) }}</span>
                </v-avatar>
                <div class="text-h6 font-weight-bold text-center text-high-emphasis">
                  {{ selectedRecord?.resident?.first_name }} {{ selectedRecord?.resident?.last_name }}
                </div>
                <div class="text-caption text-medium-emphasis text-uppercase font-weight-bold mt-1">Resident Profile</div>
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
         operator before they fire. Approve and Release need a due date, or
         "overdue" has no definition and the column stays empty forever. Deny
         needs a reason, which is the only thing the resident is ever told.
         Confirm-return needs neither, but it increments stock with no undo, so
         it asks before it moves. Release is the one that can skip: a request
         approved through this panel already carries its date. -->
    <v-dialog v-model="actionDialog.open" max-width="440" @after-leave="clearActionDialog">
      <v-card rounded="lg">
        <v-card-title class="text-subtitle-1 font-weight-bold pa-5 pb-2 text-high-emphasis">
          {{ actionCopy.title }}
        </v-card-title>
        <v-card-text class="px-5 pt-2">
          <div class="text-body-2 text-medium-emphasis mb-4">{{ actionCopy.body }}</div>

          <!-- The other two modes render the failure under their own field. -->
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
import { getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'

// Status colours: saturated 700-level ramp, each AA with white text as a badge,
// legible on both light and dark surfaces. Semantic (data-viz), not brand tokens —
// except Returned, which uses the system primary green (success tracks primary).
// `emptyText` says what would put a card in this column, which is a different
// sentence per column — an empty Pending queue is good news, an empty Released
// column means nothing is out on loan.
const columns = [
  { status: 'Pending',  label: 'Pending',  accent: '#B45309', icon: 'mdi-clock-outline',
    emptyIcon: 'mdi-inbox-outline', emptyText: 'No new requests waiting' },
  { status: 'Approved', label: 'Approved', accent: '#1D4ED8', icon: 'mdi-check-decagram-outline',
    emptyIcon: 'mdi-check-decagram-outline', emptyText: 'Nothing approved and waiting for pickup' },
  { status: 'Released', label: 'Released', accent: '#0E7490', icon: 'mdi-hand-extended-outline',
    emptyIcon: 'mdi-hand-extended-outline', emptyText: 'Nothing is out on loan' },
  { status: 'Returned', label: 'Returned', accent: '#297A67', icon: 'mdi-check-circle-outline', terminal: true },
  { status: 'Denied',   label: 'Denied',   accent: '#B91C1C', icon: 'mdi-close-circle-outline', terminal: true, muted: true },
]

// The "no filter" sentinel for each select. Named rather than repeated as a
// string literal: it is compared in four places and rendered in one.
const ALL_ITEMS = 'All items'
const ALL_BARANGAYS = 'All barangays'

const borrowings = ref([])
const activeTab = ref('board')
const search = ref('')
const itemFilter = ref(ALL_ITEMS)
const barangayFilter = ref(ALL_BARANGAYS)
const overdueOnly = ref(false)
const initialLoad = ref(true)
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

// The board carries only what still needs a decision. Three columns fit the
// available width at 1366px; five never did — the fifth started at x=1184 in a
// 1058px space, so Denied was off-screen on every laptop and only the 1920px
// development machine ever showed the whole pipeline.
const boardColumns = computed(() => columns.filter((c) => !c.terminal))
const terminalStatuses = columns.filter((c) => c.terminal).map((c) => c.status)

const historyHeaders = [
  { title: 'Resident', key: 'resident', width: '22%' },
  { title: 'Barangay', key: 'barangay', width: '16%' },
  { title: 'Equipment', key: 'equipment', width: '26%' },
  { title: 'Requested', key: 'created_at', width: '18%' },
  { title: 'Outcome', key: 'status', align: 'center', width: '18%' },
]

// Option lists come from the records actually loaded, so a barangay with no
// borrowings never appears as a filter that can only ever return nothing. They
// are deliberately not narrowed by each other: an equipment list that shrinks
// when a barangay is picked makes the two controls feel broken.
const distinct = (pick) => {
  const values = new Set()
  for (const b of borrowings.value) {
    const v = pick(b)
    if (v) values.add(v)
  }
  return [...values].sort((a, b) => a.localeCompare(b))
}

const itemOptions = computed(() => [ALL_ITEMS, ...distinct((b) => b.equipment?.item_name)])
const barangayOptions = computed(() => [
  ALL_BARANGAYS,
  ...distinct((b) => b.resident?.barangay?.barangay_name),
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

// The three filters that mean the same thing on both surfaces. Overdue is not
// one of them — see `grouped`.
const matchesFilters = (b) => matchesItem(b) && matchesBarangay(b) && matchesSearch(b)

// Bucket records by status, newest first. Overdue is applied here and only here:
// `isOverdue` is false for every terminal record by definition, so folding it
// into `matchesFilters` would empty the History tab whenever the switch was left
// on rather than filtering it.
const grouped = computed(() => {
  const out = Object.fromEntries(columns.map((c) => [c.status, []]))
  for (const b of borrowings.value) {
    if (!out[b.status] || !matchesFilters(b)) continue
    if (overdueOnly.value && !isOverdue(b)) continue
    out[b.status].push(b)
  }
  for (const k in out) out[k].sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
  return out
})

// Terminal records, same shared filters as the board so a search spans both tabs
// rather than quietly applying to one of them.
const historyItems = computed(() =>
  borrowings.value
    .filter((b) => terminalStatuses.includes(b.status) && matchesFilters(b))
    .sort((a, b) => new Date(b.created_at) - new Date(a.created_at)),
)

// What the switch would leave if it were turned on — the other filters still
// apply, or the number would not describe the button that carries it.
const overdueCount = computed(
  () => borrowings.value.filter((b) => matchesFilters(b) && isOverdue(b)).length,
)

const activeFilters = computed(() => {
  const out = []
  const q = (search.value || '').trim()
  if (q) out.push({ key: 'search', label: `Search: "${q}"` })
  if (itemFilter.value !== ALL_ITEMS) out.push({ key: 'item', label: `Equipment: ${itemFilter.value}` })
  if (barangayFilter.value !== ALL_BARANGAYS) out.push({ key: 'barangay', label: `Barangay: ${barangayFilter.value}` })
  // Only claimed on the board, because that is the only tab it acts on.
  if (overdueOnly.value && activeTab.value === 'board') out.push({ key: 'overdue', label: 'Overdue only' })
  return out
})

const clearFilter = (key) => {
  if (key === 'search') search.value = ''
  else if (key === 'item') itemFilter.value = ALL_ITEMS
  else if (key === 'barangay') barangayFilter.value = ALL_BARANGAYS
  else if (key === 'overdue') overdueOnly.value = false
}

const clearAllFilters = () => {
  search.value = ''
  itemFilter.value = ALL_ITEMS
  barangayFilter.value = ALL_BARANGAYS
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
  const shown = history ? historyItems.value.length : activeCount.value
  const noun = history ? 'completed' : 'active'
  if (shown === total) return `${total} ${noun} request${total === 1 ? '' : 's'}`
  return `Showing ${shown} of ${total} ${noun} requests`
})

// Counts the tab badges show. The board count is what is left to act on, which
// is the number the operator actually needs.
const activeCount = computed(() =>
  boardColumns.value.reduce((n, col) => n + grouped.value[col.status].length, 0),
)

const initials = (r) => `${r?.first_name?.charAt(0) || ''}${r?.last_name?.charAt(0) || ''}`
// Names a record for an accessible label: who and what, which is what tells two
// otherwise identical "Approve" buttons apart.
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

const openDetail = (item) => {
  apiError.value = ''
  selectedRecord.value = item
  modal.value.isOpen = true
}
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

onMounted(fetchData)
</script>

<style scoped>
.gap-1 { gap: 4px; }
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.min-w-0 { min-width: 0; }

/* Filter bar. Fixed-width fields that wrap rather than a grid: the switch is
   conditional, so a fixed column count would leave a hole on the History tab. */
.filter-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}
.filter-field { width: 220px; max-width: 100%; }
.overdue-switch { flex: 0 0 auto; }

/* Below 600px three 220px fields wrap to three ragged rows. Full width each. */
@media (max-width: 599px) {
  .filter-field { flex: 1 1 100%; width: 100%; }
}

/* primary-strong again, not primary. Vuetify's tonal chip draws the label on a
   12% tint of the same colour, which measures 4.40:1 and is the exact pairing
   that already failed AA three times on this page. Outlined puts the label on
   the page surface instead — primary would pass there at 5.16:1, but the
   stronger token is what the rest of this view uses, at 6.61:1. */
.filter-chip {
  color: rgb(var(--v-theme-primary-strong));
  border-color: rgba(var(--v-theme-primary), 0.45);
}

/* Board.
   Grid with minmax(0, 1fr) rather than flex with a min-width: a flex item's
   min-width is a floor the container cannot go below, so five 280px columns
   forced a 1464px track and the board scrolled sideways out of view. Grid
   tracks that bottom out at 0 shrink instead, which makes horizontal overflow
   structurally impossible rather than merely unlikely at tested widths. */
/* The offset is everything stacked above the board: app bar, page header, tabs,
   filter bar, chip row and their margins. It was 260px when the filter controls
   still lived beside the title; the filter bar and the chip row added ~98px
   below the tabs, so a board sized to the old number now runs past the fold and
   the column scrollbars never appear. Declared once and inherited by the
   columns, so the two cannot drift apart again. */
.kanban-board {
  --board-offset: 360px;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  align-items: stretch;
  padding-bottom: 8px;
  min-height: calc(100vh - var(--board-offset));
}
.kanban-column {
  border-radius: 16px;
  display: flex;
  flex-direction: column;
  min-width: 0;
  max-height: calc(100vh - var(--board-offset));
}
.kanban-column--muted { opacity: 0.85; }

/* Below Vuetify's md breakpoint the sidebar still takes its permanent 260px,
   leaving under 500px for three tracks. Stack instead of squeezing. */
@media (max-width: 959px) {
  .kanban-board {
    grid-template-columns: 1fr;
    min-height: 0;
  }
  .kanban-column { max-height: none; }
}

.kanban-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px 12px;
  border-top: 3px solid var(--accent);
  border-radius: 16px 16px 0 0;
}
.count-badge {
  color: #fff;
  font-size: 0.75rem;
  font-weight: 700;
  min-width: 22px;
  height: 22px;
  padding: 0 7px;
  border-radius: 11px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

/* A <ul> now, for the list semantics. The reset is not cosmetic tidying: a
   browser's default marker and padding would indent every card. */
.kanban-body {
  padding: 0 12px 12px;
  margin: 0;
  list-style: none;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.kanban-card {
  background-color: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-radius: 12px;
  padding: 12px;
  transition: border-color 0.15s ease;
}

/* An overdue card is marked three ways — a red left edge, a red border, and a
   chip that says how many days — because the edge alone is colour carrying
   meaning, which is the finding this page already had three of. */
.kanban-card--overdue {
  border-color: rgba(var(--v-theme-error), 0.55);
  box-shadow: inset 3px 0 0 0 rgb(var(--v-theme-error));
}

/* Only the identity block opens the record. It is a real <button>, so Enter and
   Space both work and the accessible name comes from aria-label rather than
   from whatever the concatenated card text happened to say. */
.card-identity {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  margin-bottom: 8px;
  padding: 2px;
  border-radius: 8px;
  text-align: left;
  cursor: pointer;
  background: none;
  border: 0;
  font: inherit;
  color: inherit;
}
.card-identity:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}
.resident-name {
  font-size: 1rem;
  font-weight: 700;
  line-height: 1.3;
  color: rgba(var(--v-theme-on-surface), 0.92);
}

/* primary-strong, not primary: the initials sit on a 14% tint of primary, where
   primary itself measures 4.25:1 and fails AA at this size. 6.61:1 here. */
.avatar-initials {
  font-size: 0.75rem;
  font-weight: 700;
  color: rgb(var(--v-theme-primary-strong));
}

.equip-line { display: flex; align-items: center; min-width: 0; }
/* Same tint problem as the initials, measured on 12%: 4.40:1 before, 6.78:1 now. */
.qty-pill {
  margin-left: auto;
  font-size: 0.75rem;
  font-weight: 700;
  color: rgb(var(--v-theme-primary-strong));
  background-color: rgba(var(--v-theme-primary), 0.12);
  padding: 1px 8px;
  border-radius: 8px;
  white-space: nowrap;
}

/* Was 0.72rem — 11.5px, under the 12px floor — in error on an error tint, which
   measured 4.28:1. error-strong on the same tint is 5.62:1. */
.stock-warn {
  display: flex;
  align-items: center;
  margin-top: 8px;
  font-size: 0.75rem;
  font-weight: 600;
  color: rgb(var(--v-theme-error-strong));
  background-color: rgba(var(--v-theme-error), 0.1);
  padding: 3px 8px;
  border-radius: 8px;
}

.card-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 8px;
}
.meta-chip {
  display: inline-flex;
  align-items: center;
  font-size: 0.75rem;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), 0.68);
  background-color: rgba(var(--v-theme-on-surface), 0.06);
  padding: 2px 8px;
  border-radius: 8px;
  white-space: nowrap;
}
.meta-chip--alert {
  color: rgb(var(--v-theme-error-strong));
  background-color: rgba(var(--v-theme-error), 0.1);
}

.card-actions {
  display: flex;
  gap: 8px;
  margin-top: 10px;
}

.history-table :deep(tbody tr) { cursor: pointer; }

/* The keyboard path into a history record. Inherits the cell's type so it reads
   as the name it replaced, not as a link. */
.row-identity {
  background: none;
  border: 0;
  padding: 2px 4px;
  margin: -2px -4px;
  border-radius: 6px;
  font: inherit;
  color: inherit;
  text-align: left;
  cursor: pointer;
}
.row-identity:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 1px;
}

.kanban-empty {
  text-align: center;
  padding: 24px 8px;
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.14);
  border-radius: 12px;
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
  .kanban-card { transition: none; }
}
</style>
