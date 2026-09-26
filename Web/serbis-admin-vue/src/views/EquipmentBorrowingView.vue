<template>
  <!-- fill-height like every other page, not min-height: 100vh. The shell's
       content box is 100vh minus its 12px padding top and bottom, so a
       viewport-tall child always overshoots it and adds a scroll for nothing. -->
  <v-container fluid class="fill-height align-start bg-background">
    <PageHeader
      title="Equipment Borrowing"
    />

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

    <!-- A failed load used to render as an empty table, indistinguishable
         from an empty database — the operator would read a dead API as a
         quiet morning. -->
    <v-card v-if="loadError" elevation="0" border rounded="lg" class="bg-surface">
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

    <!-- Active pipeline. Overdue is its own segment now (used to be a
         secondary toggle beside the pipeline-stage tiles) — a record can no
         longer be filtered to "Approved AND overdue" at once, only one or
         the other, matching the single-select segmented control every other
         table on this page now uses. -->
    <DataTablePage
      v-else-if="activeTab === 'board'"
      :loading="initialLoad"
      :refreshing="reloading"
      v-model:search="search"
      search-placeholder="Transaction No., resident, item or purpose"
      :tabs="activeStatusTabs"
      :status="statusFilter"
      @update:status="statusFilter = $event"
      :headers="activeHeaders"
      :items="activeItems"
      item-value="borrow_id"
      :row-props="rowProps"
      no-data-text="Nothing needs action right now"
      :page="activePage"
      @update:page="activePage = $event"
      :items-per-page="activeItemsPerPage"
      @update:items-per-page="activeItemsPerPage = $event"
      result-noun="active requests"
      class="borrow-table"
      :active-filters="activeFilters"
      @clear-filter="clearFilter"
      @clear-all="clearAllFilters"
      @click:row="(_event, { item }) => openDetail(item)"
    >
      <template v-slot:summary>{{ resultSummary }}</template>

      <template v-slot:actions>
        <ExportMenu type="borrowing" :rows="activeItems" :selected-ids="selectedIds" show-selection />
      </template>

      <template v-slot:item.select="{ item }">
        <v-checkbox-btn
          :model-value="selectedIds.has(item.borrow_id)"
          density="compact"
          :aria-label="`Select ${personName(item)}'s borrowing`"
          @click.stop="toggleSelect(item)"
        ></v-checkbox-btn>
      </template>

      <template v-slot:item.borrow_id="{ item }">
        <span class="text-truncate d-block mono">{{ borrowingTransactionNo(item.borrow_id) }}</span>
      </template>

      <template v-slot:filters>
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
      </template>

      <!-- Terminal outcomes are structurally excluded from this table (see
           activeItems' STAGE_RANK filter) — this is the only trace of them
           on the Active pipeline pane, a jump straight to the History row
           that already carries them. -->
      <template v-if="terminalOutcomeCounts.length" v-slot:before-table>
        <div class="text-caption text-medium-emphasis mb-3">
          <template v-for="(o, i) in terminalOutcomeCounts" :key="o.status">
            <a
              href="#"
              class="text-primary font-weight-bold text-decoration-none"
              @click.prevent="goToOutcome(o.status)"
            >{{ o.count }} {{ o.label }}</a><span v-if="i < terminalOutcomeCounts.length - 1"> &middot; </span>
          </template>
          — View in History
        </div>
      </template>

      <template v-slot:item.resident="{ item }">
        <PersonCell :name="personName(item)" :initials="initials(item.resident)" :secondary="personSecondary(item)" />
      </template>

      <template v-slot:item.barangay="{ item }">
        <span class="text-body-2 text-medium-emphasis cell-truncate">
          {{ item.resident?.barangay?.barangay_name || 'N/A' }}
        </span>
      </template>

      <template v-slot:item.equipment="{ item }">
        <div class="min-w-0">
          <v-tooltip :text="itemName(item)" location="top">
            <template v-slot:activator="{ props }">
              <div v-bind="props" class="text-body-2 font-weight-medium text-high-emphasis cell-truncate">
                {{ itemName(item) }} <span class="text-medium-emphasis">&times;{{ item.quantity }}</span>
              </div>
            </template>
          </v-tooltip>
          <!-- One secondary line — rows are a fixed height. A stock shortfall
               outranks the uncatalogued note, which outranks the purpose. -->
          <div v-if="shortStock(item)" class="text-caption font-weight-bold cell-truncate" style="color: rgb(var(--v-theme-error-strong));">
            Only {{ item.equipment?.available_quantity ?? 0 }} in stock
          </div>
          <div v-else-if="isUncatalogued(item)" class="text-caption text-medium-emphasis font-italic cell-truncate">
            Not in the inventory
          </div>
          <v-tooltip v-else-if="item.purpose" :text="item.purpose" location="bottom" max-width="360">
            <template v-slot:activator="{ props }">
              <div v-bind="props" class="text-caption text-medium-emphasis cell-truncate">{{ item.purpose }}</div>
            </template>
          </v-tooltip>
        </div>
      </template>

      <template v-slot:item.status="{ item }">
        <StatusPill small :status="item.status" :icon="statusIcon(item.status)" />
      </template>

      <template v-slot:item.timeline="{ item }">
        <!-- A chip, not plain text — the due countdown MDRRMO asked to be
             made prominent (feedback, 2026-09-17), same weight as the
             status chip in the column beside it. -->
        <template v-if="dueLabel(item)">
          <v-chip
            size="small" variant="flat" class="font-weight-bold"
            :color="isOverdue(item) ? 'error' : ['Due today', 'Due tomorrow'].includes(dueLabel(item)) ? 'warning' : undefined"
          >{{ dueLabel(item) }}</v-chip>
          <div class="text-caption text-medium-emphasis mt-1">{{ agingLabel(item) }}</div>
        </template>
        <div v-else class="text-body-2 text-high-emphasis">{{ agingLabel(item) }}</div>
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
    </DataTablePage>

    <!-- History. This list only ever grows, and the questions asked of it are
         lookups ("did the Cruz family return the generator?") rather than a
         pipeline glance. -->
    <DataTablePage
      v-else
      :loading="initialLoad"
      :refreshing="reloading"
      v-model:search="search"
      search-placeholder="Transaction No., resident, item or purpose"
      :tabs="historyStatusTabs"
      :status="outcomeFilter"
      @update:status="outcomeFilter = $event"
      :headers="historyHeaders"
      :items="historyItems"
      item-value="borrow_id"
      :row-props="rowProps"
      no-data-text="No completed requests yet"
      :page="historyPage"
      @update:page="historyPage = $event"
      :items-per-page="historyItemsPerPage"
      @update:items-per-page="historyItemsPerPage = $event"
      result-noun="completed requests"
      class="borrow-table"
      :active-filters="activeFilters"
      @clear-filter="clearFilter"
      @clear-all="clearAllFilters"
      @click:row="(_event, { item }) => openDetail(item)"
    >
      <template v-slot:summary>{{ resultSummary }}</template>

      <template v-slot:actions>
        <ExportMenu type="borrowing" :rows="historyItems" :selected-ids="selectedIds" show-selection />
      </template>

      <template v-slot:item.select="{ item }">
        <v-checkbox-btn
          :model-value="selectedIds.has(item.borrow_id)"
          density="compact"
          :aria-label="`Select ${personName(item)}'s borrowing`"
          @click.stop="toggleSelect(item)"
        ></v-checkbox-btn>
      </template>

      <template v-slot:item.borrow_id="{ item }">
        <span class="text-truncate d-block mono">{{ borrowingTransactionNo(item.borrow_id) }}</span>
      </template>

      <template v-slot:filters>
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
      </template>

      <template v-slot:item.status="{ item }">
        <StatusPill small :status="item.status" :icon="statusIcon(item.status)" />
      </template>

      <template v-slot:item.resident="{ item }">
        <PersonCell :name="personName(item)" :initials="initials(item.resident)" :secondary="personSecondary(item)" />
      </template>

      <template v-slot:item.barangay="{ item }">
        <span class="cell-truncate" :title="item.resident?.barangay?.barangay_name || 'N/A'">
          {{ item.resident?.barangay?.barangay_name || 'N/A' }}
        </span>
      </template>

      <template v-slot:item.equipment="{ item }">
        <span class="cell-truncate" :title="itemName(item)">
          {{ itemName(item) }}
          <span class="text-medium-emphasis">&times;{{ item.quantity }}</span>
        </span>
      </template>

      <template v-slot:item.created_at="{ item }">
        {{ fmtDate(item.created_at) }}
      </template>
    </DataTablePage>

    <!-- Detail modal (full record + fallback actions) -->
    <!-- Not persistent: this reads a record. The one input on it, the handover
         photo picker, uploads on pick, so there is no unsaved state to lose. -->
    <v-dialog v-model="modal.isOpen" max-width="min(820px, 95vw)" transition="dialog-fade-transition">
      <v-card rounded="lg" elevation="4">
        <DetailDialogHeader
          :name="[selectedRecord?.resident?.first_name, selectedRecord?.resident?.last_name].filter(Boolean).join(' ') || 'Unknown Head of the Family'"
          :initials="initials(selectedRecord?.resident)"
          :secondary="selectedRecord?.resident?.barangay?.barangay_name"
          @close="closeModal"
        >
          <template v-slot:status>
            <StatusPill :status="selectedRecord?.status" :icon="statusIcon(selectedRecord?.status)" />
          </template>
          <template v-slot:actions><ExportMenu v-if="selectedRecord" type="borrowing" :row="selectedRecord" /></template>
        </DetailDialogHeader>
        <v-divider></v-divider>

        <v-card-text class="pa-0">
          <v-row class="ma-0 h-100">
            <v-col cols="12" md="5" class="subtle-surface pa-6 border-e">
              <div class="mb-3">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Transaction No.</div>
                <div class="font-weight-medium text-body-1 text-high-emphasis mono">{{ borrowingTransactionNo(selectedRecord?.borrow_id) }}</div>
              </div>
              <div class="mb-3">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Phone Number</div>
                <div class="font-weight-medium text-body-1 text-high-emphasis">{{ displayPhone(selectedRecord?.resident?.phone_number) || 'N/A' }}</div>
              </div>
              <!-- The account holder above stays the contact either way — the
                   request was filed from their account and resident_id is
                   required. This says who the item is FOR, which is the part
                   that separates an institutional loan from a personal one. -->
              <div class="mb-3">
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Borrowing For</div>
                <div
                  class="font-weight-medium text-body-1"
                  :class="unnamedOrganization(selectedRecord) ? 'text-error font-italic' : 'text-high-emphasis'"
                >{{ borrowingForLabel(selectedRecord) }}</div>
                <div v-if="isOrganization(selectedRecord)" class="text-caption text-medium-emphasis">Organization</div>
              </div>
            </v-col>

            <v-col cols="12" md="7" class="pa-6 bg-surface">
              <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact">{{ apiError }}</v-alert>

              <template v-if="selectedRecord?.status === 'Denied'">
                <h3 class="text-subtitle-1 font-weight-bold mb-4 text-error text-uppercase">Reason for Denial</h3>
                <v-card variant="outlined" border class="pa-4 mb-6 rounded-lg subtle-surface">
                  <div class="text-body-1 text-high-emphasis">{{ selectedRecord?.denial_reason || 'No reason was recorded.' }}</div>
                  <div v-if="selectedRecord?.denial_reason_code === 'Unavailable'" class="text-caption text-medium-emphasis mt-2">
                    Marked as "not available" — the resident is texted and pushed automatically once this item is back in stock.
                  </div>
                </v-card>
              </template>

              <!-- Info, not error: the office refused nothing here. Saying
                   stock was untouched out loud because the obvious guess is
                   that a cancelled request handed something back — it did
                   not, since nothing is deducted before Released. -->
              <v-alert
                v-else-if="selectedRecord?.status === 'Cancelled'"
                type="info" variant="tonal" class="mb-4" density="compact"
                :title="'Withdrawn by the resident'"
              >The resident cancelled this request from the mobile app before the item was released. No stock was reserved or returned.</v-alert>

              <v-alert
                v-else-if="isOverdue(selectedRecord)"
                type="error" variant="tonal" class="mb-4" density="compact"
                :title="dueLabel(selectedRecord)"
              >This item was due back on {{ fmtDate(selectedRecord?.due_date) }} and has not been returned.</v-alert>

              <!-- Not overdue yet, but still a live loan with a due date —
                   the countdown MDRRMO asked to be made prominent (feedback,
                   2026-09-17), not just the small field further down. Amber
                   inside the 1-day reminder window (matches
                   SendReturnDueReminders' own window), blue otherwise. -->
              <v-alert
                v-else-if="selectedRecord?.due_date && !terminalStatuses.includes(selectedRecord.status)"
                :type="['Due today', 'Due tomorrow'].includes(dueLabel(selectedRecord)) ? 'warning' : 'info'"
                variant="tonal" class="mb-4" density="compact"
                :title="dueLabel(selectedRecord)"
              >Due back on {{ fmtDate(selectedRecord?.due_date) }}.</v-alert>

              <h3 class="text-subtitle-1 font-weight-bold mb-4 text-high-emphasis text-uppercase">Equipment Requested</h3>
              <v-card variant="outlined" border class="pa-6 mb-6 rounded-lg subtle-surface d-flex justify-space-between align-center">
                <div>
                  <div class="text-h5 font-weight-black text-high-emphasis">{{ itemName(selectedRecord) }}</div>
                  <!-- An uncatalogued item has no stock row to quote, and the
                       backend refuses to release one until staff add it to the
                       inventory and attach it. Saying so here is what makes
                       that 422 predictable instead of a surprise at the point
                       of release. -->
                  <div v-if="isUncatalogued(selectedRecord)" class="text-subtitle-2 font-weight-medium text-warning mt-1">
                    Not in the inventory — add this item to the equipment list and attach it before releasing.
                  </div>
                  <div v-else class="text-subtitle-2 font-weight-medium text-medium-emphasis mt-1">
                    Current Stock Available:
                    <span class="font-weight-bold" :class="selectedRecord?.equipment?.available_quantity > 0 ? 'text-primary' : 'text-error'">
                      {{ selectedRecord?.equipment?.available_quantity }}
                    </span>
                  </div>
                </div>
                <div class="text-h3 font-weight-black text-high-emphasis">{{ selectedRecord?.quantity }}<span class="text-h5 text-medium-emphasis ml-1">×</span></div>
              </v-card>

              <h3 class="text-subtitle-1 font-weight-bold mb-4 text-high-emphasis text-uppercase">Purpose</h3>
              <v-card variant="outlined" border class="pa-4 mb-6 rounded-lg subtle-surface">
                <!-- Requests filed before the field existed have no purpose, and
                     an empty box reads as a resident who left it blank. -->
                <div
                  class="text-body-1"
                  :class="selectedRecord?.purpose ? 'text-high-emphasis' : 'text-medium-emphasis font-italic'"
                  style="white-space: pre-wrap;"
                >{{ selectedRecord?.purpose || 'No purpose was recorded — this request predates the field.' }}</div>
              </v-card>

              <h3 class="text-subtitle-1 font-weight-bold mb-4 text-high-emphasis text-uppercase">Handover</h3>
              <v-card variant="outlined" border class="pa-4 mb-6 rounded-lg subtle-surface">
                <div class="d-flex align-center gap-2">
                  <v-icon
                    size="20"
                    :color="isDelivery(selectedRecord) ? 'primary' : 'medium-emphasis'"
                  >{{ isDelivery(selectedRecord) ? 'mdi-truck-outline' : 'mdi-storefront-outline' }}</v-icon>
                  <span class="text-body-1 font-weight-bold text-high-emphasis">
                    {{ isDelivery(selectedRecord) ? 'Deliver to the borrower' : 'Collect from the MDRRMO office' }}
                  </span>
                </div>
                <!-- Only a delivery has an address, and a delivery without one
                     is a run nobody can make — so this says so rather than
                     rendering an empty line. -->
                <div v-if="isDelivery(selectedRecord)" class="mt-3">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Delivery address</div>
                  <div
                    class="text-body-1"
                    :class="selectedRecord?.delivery_address ? 'text-high-emphasis' : 'text-error font-italic'"
                    style="white-space: pre-wrap;"
                  >{{ selectedRecord?.delivery_address || 'No address was recorded — ask the borrower before dispatching.' }}</div>
                </div>
              </v-card>

              <!-- Only shown once something has changed hands. Before that the
                   backend refuses the upload, so offering it would be a button
                   that always fails. -->
              <template v-if="photoStages(selectedRecord).length > 0">
                <h3 class="text-subtitle-1 font-weight-bold mb-4 text-high-emphasis text-uppercase">Condition Photos</h3>
                <v-row class="mb-6">
                  <v-col
                    v-for="stage in photoStages(selectedRecord)"
                    :key="stage"
                    cols="12"
                    sm="6"
                  >
                    <v-card variant="outlined" border class="pa-4 rounded-lg subtle-surface h-100">
                      <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-3">
                        {{ stage === 'release' ? 'At release' : 'At return' }}
                      </div>

                      <v-img
                        v-if="photoState(stage).url"
                        :src="photoState(stage).url"
                        :alt="`Condition of ${itemName(selectedRecord)} at ${stage}`"
                        height="160"
                        cover
                        class="rounded mb-3"
                      ></v-img>

                      <v-skeleton-loader
                        v-else-if="photoState(stage).loading"
                        type="image"
                        height="160"
                        class="mb-3"
                      ></v-skeleton-loader>

                      <v-alert
                        v-else-if="photoState(stage).error"
                        type="error"
                        variant="tonal"
                        density="compact"
                        class="mb-3"
                      >{{ photoState(stage).error }}</v-alert>

                      <div v-else class="text-body-2 text-medium-emphasis font-italic mb-3">
                        No photo was taken.
                      </div>

                      <!-- Uploads on pick rather than holding the file for a
                           later submit: there is nothing else on this card to
                           submit it with. -->
                      <v-file-input
                        :label="photoState(stage).url ? 'Replace photo' : 'Add photo'"
                        accept="image/jpeg,image/png"
                        density="compact"
                        variant="outlined"
                        hide-details
                        prepend-icon=""
                        prepend-inner-icon="mdi-camera-outline"
                        :loading="photoUploading === stage"
                        :disabled="!!photoUploading"
                        @update:model-value="(picked) => uploadHandoverPhoto(selectedRecord, stage, Array.isArray(picked) ? picked[0] : picked)"
                      ></v-file-input>

                      <!-- Only while the record is still in the stage this
                           photo belongs to. Past that the server refuses
                           (PHOTO_STAGES.removable_in), so drawing the button
                           would be offering an action that always fails —
                           replacing is what is left. -->
                      <v-btn
                        v-if="canRemovePhoto(selectedRecord, stage)"
                        variant="outlined"
                        color="error"
                        size="small"
                        rounded="lg"
                        class="text-none mt-2"
                        :loading="photoRemoving === stage"
                        :disabled="!!photoUploading || !!photoRemoving"
                        @click="askRemovePhoto(stage)"
                      >
                        <v-icon start size="18">mdi-delete-outline</v-icon> Remove photo
                      </v-btn>
                    </v-card>
                  </v-col>
                </v-row>
              </template>

              <!-- Optional, like the photo it accompanies — a row with
                   neither the flag nor a note draws nothing at all, same
                   reasoning as the photos above. -->
              <v-card
                v-if="selectedRecord?.return_condition || selectedRecord?.return_condition_note"
                variant="outlined" border class="pa-4 mb-6 rounded-lg subtle-surface"
              >
                <div class="d-flex align-center justify-space-between mb-2">
                  <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Condition at return</div>
                  <v-chip
                    v-if="selectedRecord?.return_condition"
                    size="small" variant="flat" class="font-weight-bold"
                    :color="selectedRecord.return_condition === 'Bad' ? 'error' : 'success'"
                  >{{ selectedRecord.return_condition }}</v-chip>
                </div>
                <div
                  v-if="selectedRecord?.return_condition_note"
                  class="text-body-2 text-high-emphasis"
                  style="white-space: pre-wrap;"
                >{{ selectedRecord.return_condition_note }}</div>
              </v-card>

              <!-- Display only — see borrowerHistory's own comment. Nothing
                   here narrows what the operator can do; it is context for a
                   decision they make themselves. -->
              <v-card
                v-if="borrowerHistory.length > 0"
                variant="outlined" border class="pa-4 mb-6 rounded-lg subtle-surface"
              >
                <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-3">
                  This resident's past returns ({{ borrowerHistory.length }})
                </div>
                <div
                  v-for="past in borrowerHistory" :key="past.borrow_id"
                  class="d-flex align-start gap-3 mb-3"
                  style="border-left: 3px solid rgb(var(--v-theme-outline)); padding-left: 12px;"
                >
                  <div class="flex-grow-1 min-width-0">
                    <div class="d-flex align-center gap-2 mb-1">
                      <v-chip
                        v-if="past.return_condition"
                        size="x-small" variant="flat" class="font-weight-bold"
                        :color="past.return_condition === 'Bad' ? 'error' : 'success'"
                      >{{ past.return_condition }}</v-chip>
                      <span class="text-caption text-medium-emphasis">
                        {{ past.equipment?.item_name || past.other_equipment_text }} · {{ fmtDate(past.returned_at || past.created_at) }}
                      </span>
                    </div>
                    <div
                      v-if="past.return_condition_note"
                      class="text-body-2 text-high-emphasis"
                      style="white-space: pre-wrap;"
                    >{{ past.return_condition_note }}</div>
                  </div>
                </div>
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

        <!-- Terminal-list check, not the two words it used to name: a
             Cancelled record matches none of the branches inside, so the old
             version rendered this footer empty. -->
        <v-card-actions
          v-if="selectedRecord && !terminalStatuses.includes(selectedRecord.status)"
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
    <!-- persistent: holds a due date and, on a denial, the reason the resident
         is shown. -->
    <v-dialog v-model="actionDialog.open" max-width="440" persistent @after-leave="clearActionDialog">
      <v-card rounded="lg">
        <v-card-title class="d-flex justify-space-between align-center text-subtitle-1 font-weight-bold pa-5 pb-2 text-high-emphasis">
          <span>{{ actionCopy.title }}</span>
          <v-btn
            icon="mdi-close" variant="tonal" rounded="circle" size="small" aria-label="Close"
            :disabled="loading" @click="actionDialog.open = false"
          ></v-btn>
        </v-card-title>
        <v-card-text class="px-5 pt-2">
          <div class="text-body-2 text-medium-emphasis mb-4">{{ actionCopy.body }}</div>

          <!-- Only where the purpose is still being weighed. A return has
               nothing left to decide, and repeating it there is noise. -->
          <v-alert
            v-if="actionDialog.mode !== 'confirm' && actionDialog.record?.purpose"
            variant="tonal" density="compact" class="mb-4" icon="mdi-note-text-outline"
          >
            <div class="text-caption font-weight-bold text-uppercase mb-1">Their stated purpose</div>
            <div class="text-body-2" style="white-space: pre-wrap;">{{ actionDialog.record.purpose }}</div>
          </v-alert>

          <v-alert
            v-if="(actionDialog.mode === 'confirm' || actionDialog.mode === 'return') && actionDialog.error"
            type="error" variant="tonal" density="compact" class="mb-4"
          >{{ actionDialog.error }}</v-alert>

          <DateTimePickerField
            v-if="actionDialog.mode === 'due'"
            v-model="actionDialog.dueDate"
            type="date"
            :min="daysFromToday(MIN_LOAN_DAYS)"
            :max="daysFromToday(DEFAULT_LOAN_DAYS)"
            label="Due back on"
            variant="outlined"
            density="comfortable"
            :error-messages="actionDialog.error"
            @update:model-value="actionDialog.error = ''"
          ></DateTimePickerField>

          <template v-else-if="actionDialog.mode === 'deny'">
            <!-- Unavailable specifically, not denial in general: this is
                 what keys the "still needed?" reconfirm notification once
                 the item is back in stock (MDRRMO feedback, 2026-09-18) —
                 denial_reason alone is free text with nothing to check that
                 automatically. -->
            <v-btn-toggle
              v-model="actionDialog.denialReasonCode"
              mandatory
              density="comfortable"
              class="mb-4"
            >
              <v-btn value="Unavailable" class="text-none">Not available</v-btn>
              <v-btn value="Other" class="text-none">Other reason</v-btn>
            </v-btn-toggle>

            <v-textarea
              v-model="actionDialog.reason"
              label="Reason for denial"
              placeholder="e.g. All units are committed to the flood drill that week"
              hint="The resident is shown this. Say what would make a future request succeed."
              persistent-hint
              variant="outlined"
              rows="3"
              counter="255"
              maxlength="255"
              :error-messages="actionDialog.error"
              @update:model-value="actionDialog.error = ''"
            ></v-textarea>
          </template>

          <template v-else-if="actionDialog.mode === 'return'">
            <v-btn-toggle
              v-model="actionDialog.condition"
              mandatory
              density="comfortable"
              class="mb-4"
              @update:model-value="actionDialog.error = ''"
            >
              <v-btn value="Good" class="text-none">Good condition</v-btn>
              <v-btn value="Bad" class="text-none">Bad condition</v-btn>
            </v-btn-toggle>

            <v-textarea
              v-model="actionDialog.conditionNote"
              :label="actionDialog.condition === 'Bad' ? 'What\'s wrong with it' : 'Condition note'"
              placeholder="e.g. Life jacket strap frayed, otherwise usable"
              hint="Required on every return — this is what the next person deciding whether to lend again reads."
              persistent-hint
              variant="outlined"
              rows="3"
              counter="500"
              maxlength="500"
              @update:model-value="actionDialog.error = ''"
            ></v-textarea>
          </template>
        </v-card-text>
        <v-card-actions class="px-5 pb-5 pt-0 justify-end gap-3">
          <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" height="44" @click="actionDialog.open = false">Cancel</v-btn>
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

    <!-- Removal is permanent and the file goes with the row, so it is asked
         for rather than assumed — the same treatment the record delete gets. -->
    <v-dialog v-model="removePhotoDialog.open" max-width="440">
      <v-card rounded="xl" class="pa-2">
        <v-card-title class="pa-6 pb-2 text-h6 font-weight-bold text-high-emphasis">Remove this photo?</v-card-title>
        <v-card-text class="px-6 py-4 text-body-2 text-medium-emphasis">
          The
          <strong class="text-high-emphasis">{{ removePhotoDialog.stage === 'release' ? 'at release' : 'at return' }}</strong>
          photo is deleted from storage and cannot be recovered. Staff can take a new one while this
          borrowing is still {{ removePhotoDialog.stage === 'release' ? 'Released' : 'Returned' }}.
        </v-card-text>
        <v-card-actions class="pa-6 pt-2 justify-end gap-3">
          <v-btn variant="outlined" color="primary" rounded="lg" class="text-none" :disabled="!!photoRemoving" @click="removePhotoDialog.open = false">Cancel</v-btn>
          <v-btn color="error" variant="flat" rounded="lg" class="px-6 text-none font-weight-bold" :loading="!!photoRemoving" @click="confirmRemovePhoto">Remove</v-btn>
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
import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute } from 'vue-router'
import { initials as computeInitials } from '@/composables/adminUi'
import { getToken } from '@/composables/authToken'
import { displayPhone } from '@/composables/phoneNumber'
import { useBorrowingsList } from '@/composables/borrowingsList'
import { API_BASE } from '@/config/api'
import DateTimePickerField from '@/components/DateTimePickerField.vue'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import StatusPill from '@/components/StatusPill.vue'
import PersonCell from '@/components/PersonCell.vue'
import ExportMenu from '@/components/ExportMenu.vue'
import DetailDialogHeader from '@/components/DetailDialogHeader.vue'
import { useSelection, borrowingTransactionNo } from '@/composables/requestDisplay'
import { BORROWING_STATUSES, statusIcon } from '@/composables/borrowingStatus'

const route = useRoute()

// Status colours, icons and labels: one definition in borrowingStatus.ts,
// shared with ProcurementReferenceView (same rows, read-only there). Used
// to be two verbatim-identical arrays that only agreed by accident.
const columns = BORROWING_STATUSES

// The "no filter" sentinel for each select. Named rather than repeated as a
// string literal: it is compared in four places and rendered in one.
const ALL_ITEMS = 'All items'
const ALL_BARANGAYS = 'All barangays'
const ALL_STATUS = 'All'
const ALL_OUTCOMES = 'All'

// Module-level state, not this component's: ProcurementReferenceView reads the
// same rows, and `GET /borrowings` returns the whole table unpaginated — two
// copies of that is twice the heaviest read in the panel for the same data.
const { rows: borrowings, loadError, initialLoad, reloading, load } = useBorrowingsList()
const activeTab = ref('board')
const search = ref('')
const itemFilter = ref(ALL_ITEMS)
const barangayFilter = ref(ALL_BARANGAYS)
// 'Overdue' joins the three real statuses as a fourth, mutually-exclusive
// segment (see activeStatusTabs) — no longer a separate toggle a pipeline
// stage could be combined with.
const statusFilter = ref(ALL_STATUS)
const outcomeFilter = ref(ALL_OUTCOMES)
const activePage = ref(1)
const activeItemsPerPage = ref(10)
const historyPage = ref(1)
const historyItemsPerPage = ref(10)

watch([search, itemFilter, barangayFilter, statusFilter], () => { activePage.value = 1 })
watch([search, itemFilter, barangayFilter, outcomeFilter], () => { historyPage.value = 1 })

// Dashboard KPI cards deep-link here with ?status=... / ?overdue=1 — honor
// them once on arrival so the operator lands on the filtered view.
if (columns.some((c) => c.status === route.query.status)) statusFilter.value = route.query.status
if (route.query.overdue === '1') statusFilter.value = 'Overdue'
const loading = ref(false)
const processingId = ref(null)
const apiError = ref('')
const liveMessage = ref('')
const modal = ref({ isOpen: false })
const selectedRecord = ref(null)
// Ticked rows, shared by both tables (borrow_ids do not repeat across them).
const selectedIds = reactive(new Set())
const { toggleSelect } = useSelection(selectedRecord, selectedIds, (b) => b.borrow_id)
const snackbar = ref({ show: false, text: '', color: 'success' })

const emptyAction = () => ({
  open: false,
  mode: null,
  status: null,
  record: null,
  dueDate: '',
  reason: '',
  denialReasonCode: 'Unavailable',
  condition: 'Good',
  conditionNote: '',
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

// `value` gives each composite column something to sort on; the key still
// names the cell slot.
const residentSortValue = (b) => `${b.resident?.last_name || ''} ${b.resident?.first_name || ''}`
const barangaySortValue = (b) => b.resident?.barangay?.barangay_name || ''

// Barangay keeps its own column (it is filterable); the person cell's second
// line is the contact number, falling back to barangay only when there is none.
const personName = (b) => `${b.resident?.last_name || ''}, ${b.resident?.first_name || ''}`
const personSecondary = (b) => displayPhone(b.resident?.phone_number) || b.resident?.barangay?.barangay_name || null

const activeHeaders = [
  { title: '', key: 'select', sortable: false, width: '48px' },
  { title: 'Transaction No.', key: 'borrow_id', width: '11%' },
  { title: 'Head of the Family', key: 'resident', value: residentSortValue, width: '21%' },
  { title: 'Barangay', key: 'barangay', value: barangaySortValue, width: '12%' },
  { title: 'Equipment', key: 'equipment', value: (b) => itemName(b), width: '20%' },
  { title: 'Status', key: 'status', width: '12%' },
  { title: 'Timeline', key: 'timeline', value: 'due_date', width: '12%' },
  { title: '', key: 'actions', sortable: false, align: 'end', width: '16%' },
]

const historyHeaders = [
  { title: '', key: 'select', sortable: false, width: '48px' },
  { title: 'Transaction No.', key: 'borrow_id', width: '13%' },
  { title: 'Head of the Family', key: 'resident', value: residentSortValue, width: '21%' },
  { title: 'Barangay', key: 'barangay', value: barangaySortValue, width: '15%' },
  { title: 'Equipment', key: 'equipment', value: (b) => itemName(b), width: '22%' },
  { title: 'Requested', key: 'created_at', width: '16%' },
  { title: 'Outcome', key: 'status', width: '13%' },
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
  // Both item sources, not just the catalogued one — typing what a resident
  // wrote in the free-text box has to find their request. Read off the fields
  // rather than through itemName(), whose 'Unknown' fallback would otherwise
  // make every uncatalogued row a hit for the word "unknown".
  const item = `${b.equipment?.item_name || ''} ${b.other_equipment_text || ''}`.toLowerCase()
  const purpose = (b.purpose || '').toLowerCase()
  // Same lookup path as the request queues (d2018bad): a borrower reading
  // "BOR-000042" off the panel over the phone has to find that row too.
  const txn = borrowingTransactionNo(b.borrow_id).toLowerCase()
  return name.includes(q) || item.includes(q) || purpose.includes(q) || txn.includes(q)
}

const matchesItem = (b) =>
  itemFilter.value === ALL_ITEMS || b.equipment?.item_name === itemFilter.value

const matchesBarangay = (b) =>
  barangayFilter.value === ALL_BARANGAYS ||
  b.resident?.barangay?.barangay_name === barangayFilter.value

// The three filters shared by both tabs and both segmented controls' own
// counts. Status/outcome are not among them — each segment needs the count
// as if it, specifically, were the only one applied.
const matchesFilters = (b) => matchesItem(b) && matchesBarangay(b) && matchesSearch(b)

const countByStatus = (status) =>
  borrowings.value.filter((b) => b.status === status && matchesFilters(b)).length

// Returned/Denied/Cancelled rows never appear in the Active pipeline table —
// they're terminal, so a link here jumps to History pre-filtered to that
// outcome rather than pretending to filter a table that structurally
// excludes them. Hidden at zero, same rule the segmented tabs already
// follow, so a shortcut never points at an empty History view.
const terminalOutcomeCounts = computed(() =>
  columns
    .filter((c) => c.terminal)
    .map((c) => ({ status: c.status, label: c.label, count: countByStatus(c.status) }))
    .filter((c) => c.count > 0),
)

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
    .filter((b) => {
      if (statusFilter.value === ALL_STATUS) return true
      if (statusFilter.value === 'Overdue') return isOverdue(b)
      return b.status === statusFilter.value
    })
    .sort((a, b) => {
      const byOverdue = Number(isOverdue(b)) - Number(isOverdue(a))
      if (byOverdue) return byOverdue
      const byStage = STAGE_RANK[a.status] - STAGE_RANK[b.status]
      if (byStage) return byStage
      return new Date(a.created_at) - new Date(b.created_at)
    })
})

// SegmentedTabs' {value, label, count} shape for the Active pipeline —
// Pending/Approved/Released plus Overdue as its own segment (used to be a
// secondary toggle beside these). Counts are off the shared item/barangay/
// search filters only, same reasoning statusTiles used to follow: a
// segment's own count should not move because a different segment is
// selected.
const activeStatusTabs = computed(() => {
  const rows = borrowings.value.filter((b) => STAGE_RANK[b.status] !== undefined && matchesFilters(b))
  return [
    { value: ALL_STATUS, label: 'All', count: rows.length },
    ...columns.filter((c) => !c.terminal).map((c) => ({
      value: c.status,
      label: c.label,
      count: rows.filter((b) => b.status === c.status).length,
    })),
    { value: 'Overdue', label: 'Overdue', count: rows.filter((b) => isOverdue(b)).length },
  ]
})

const historyItems = computed(() =>
  borrowings.value
    .filter((b) => terminalStatuses.includes(b.status) && matchesFilters(b))
    .filter((b) => outcomeFilter.value === ALL_OUTCOMES || b.status === outcomeFilter.value)
    .sort((a, b) => new Date(b.created_at) - new Date(a.created_at)),
)

// Same shape as activeStatusTabs, for History's terminal outcomes —
// replaces the old "Outcome" v-select.
const historyStatusTabs = computed(() => {
  const rows = borrowings.value.filter((b) => terminalStatuses.includes(b.status) && matchesFilters(b))
  return [
    { value: ALL_OUTCOMES, label: 'All', count: rows.length },
    ...columns.filter((c) => c.terminal).map((c) => ({
      value: c.status,
      label: c.label,
      count: rows.filter((b) => b.status === c.status).length,
    })),
  ]
})

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

// Search's own chip is DataTablePage's job (it already owns that prop); this
// only covers the two selects plus whichever tab is active on the current
// pipeline. Status and outcome are mutually exclusive with each other (one
// tab strip per pane, never both), so at most one of them ever appears.
const activeFilters = computed(() => {
  const out = []
  if (itemFilter.value !== ALL_ITEMS) out.push({ key: 'item', label: `Equipment: ${itemFilter.value}` })
  if (barangayFilter.value !== ALL_BARANGAYS) out.push({ key: 'barangay', label: `Barangay: ${barangayFilter.value}` })
  if (activeTab.value === 'board') {
    if (statusFilter.value !== ALL_STATUS) out.push({ key: 'status', label: `Status: ${statusFilter.value}` })
  } else if (outcomeFilter.value !== ALL_OUTCOMES) {
    out.push({ key: 'outcome', label: `Outcome: ${outcomeFilter.value}` })
  }
  return out
})

const clearFilter = (key) => {
  if (key === 'item') itemFilter.value = ALL_ITEMS
  else if (key === 'barangay') barangayFilter.value = ALL_BARANGAYS
  else if (key === 'status') statusFilter.value = ALL_STATUS
  else if (key === 'outcome') outcomeFilter.value = ALL_OUTCOMES
}

const clearAllFilters = () => {
  itemFilter.value = ALL_ITEMS
  barangayFilter.value = ALL_BARANGAYS
  statusFilter.value = ALL_STATUS
  outcomeFilter.value = ALL_OUTCOMES
}

// Now uppercased, matching every other avatar in the panel — the same
// resident used to read "MS" on Residents and "mS" here. See
// audits/code-duplication.md Finding 6.
const initials = (r) => computeInitials(r)
// Names a record for an accessible label: who and what, which is what tells
// two otherwise identical "Approve" buttons apart.
// A borrowing names its item one of two ways and never both: an equipment row
// the office has catalogued, or free text for something it has not. The table
// enforces exactly-one with a CHECK constraint, so `equipment` being absent is
// a normal state here and not a loading failure.
const isUncatalogued = (item) => !item?.equipment_id
const itemName = (item) =>
  item?.equipment?.item_name || item?.other_equipment_text || 'Unknown'

const cardLabel = (item) =>
  `${itemName(item) === 'Unknown' ? 'equipment' : itemName(item)} for ${item.resident?.first_name || ''} ${item.resident?.last_name || ''}`.trim()

// An uncatalogued item has no stock figure at all, so it is never short. The
// old `?? 0` read a missing equipment row as zero available, which would have
// flagged every one of these red for a shortage that is not a shortage.
const shortStock = (item) =>
  !isUncatalogued(item) && (item.equipment?.available_quantity ?? 0) < item.quantity

// Anything that is not explicitly a delivery is a pickup, which is also what
// every request filed before the column existed was.
const isDelivery = (item) => item?.fulfillment_method === 'Delivery'

// Same shape as isDelivery: anything not explicitly an organisation is a
// resident borrowing for themselves, which every pre-column row was.
const isOrganization = (item) => item?.borrower_type === 'Organization'

// store() makes the name required for an organisation, so this is a data-drift
// guard rather than an expected state — but a record that claims to be
// institutional and cannot say which institution is worth flagging in red
// rather than rendering as a blank line.
const unnamedOrganization = (item) => isOrganization(item) && !item?.organization_name

// Symmetric on purpose: a name on both sides. This line separates an
// individual loan from an institutional one, so it names who or what the item
// is for — not the account holder's role. "Head of the family" would assert a
// household position the schema does not record and that is simply wrong for a
// borrower who is not one; the seven strings that phrase belongs to are all
// generic headings, never a value beside one named record.
const borrowingForLabel = (item) => {
  if (isOrganization(item)) {
    return item?.organization_name || 'No organization was recorded — ask the borrower.'
  }
  // Falls back rather than rendering a bare space: `resident` is eager-loaded
  // on both show() and index(), but a resident deleted mid-session leaves the
  // relation null and this line would otherwise read as empty.
  return `${item?.resident?.first_name || ''} ${item?.resident?.last_name || ''}`.trim() || 'Unknown borrower'
}

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
  return Math.round((due - today) / 86_400_000)
}

// A returned or denied record cannot be overdue, however far past its date it
// sits — the item is back, or it never left.
// Display only — MDRRMO feedback, 2026-09-18: an admin deciding whether to
// approve a new request sees how this same resident treated equipment
// before, but nothing here blocks or auto-denies anything. `borrowings` is
// already the whole unpaginated table (see useBorrowingsList's own comment),
// so no second fetch is needed — just filtered to rows this same resident
// has been the borrower on, that carry a recorded condition, excluding the
// record currently open.
const borrowerHistory = computed(() => {
  const residentId = selectedRecord.value?.resident_id
  if (!residentId) return []

  return borrowings.value
    .filter((b) => b.resident_id === residentId
      && b.borrow_id !== selectedRecord.value?.borrow_id
      && (b.return_condition || b.return_condition_note))
    .sort((a, b) => new Date(b.returned_at || b.created_at) - new Date(a.returned_at || a.created_at))
})

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
  const days = Math.floor((Date.now() - new Date(anchor).getTime()) / 86_400_000)
  if (days <= 0) return released ? 'Out today' : 'Today'
  return `${days}d ${released ? 'out' : 'waiting'}`
}

const getHeaders = () => ({
  Authorization: `Bearer ${getToken()}`,
  'Content-Type': 'application/json',
  Accept: 'application/json',
})

// The request, the non-2xx handling and the error text all live in
// `useBorrowingsList` now. Wrapped rather than bound straight to `load`,
// because this is also a @click handler and a click event would arrive where
// the loader takes its options.
//
// The full-pane loadError card below is the only notification for a failure —
// a snackbar on top of it duplicated the same message (ui-audit finding #3).
const fetchData = () => load()

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
  // Not awaited: the panel opens immediately and each photo fills in when it
  // arrives, the same way the service-request attachments do.
  releasePhoto.load(item)
  returnPhoto.load(item)
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

// ---------------------------------------------------------------- handover photos
//
// Both photos live on the private disk and are served only by an authenticated
// route, so neither can be addressed by plain URL from an image tag — the
// request has to carry the bearer token. Fetched as a blob with the same
// headers every other call uses, exactly like the valid-ID and site-photo
// attachments on ServiceRequestQueue. One factory rather than two hand-written
// copies -- the copy is where "revoke the previous object URL" gets forgotten
// and the tab leaks a blob per record opened.
const createHandoverPhoto = (stage) => {
  const state = reactive({ url: '', loading: false, error: '', for: null })

  const release = () => {
    if (state.url) URL.revokeObjectURL(state.url)
    state.url = ''
  }

  const load = async (record) => {
    const id = record ? (record.borrow_id || record.id) : null
    if (id === state.for) return

    release()
    state.for = id
    state.error = ''
    // The path columns are hidden on the model — a private-disk path is not
    // something a client is handed — so this keys off the appended boolean.
    if (!id || !record?.[`has_${stage}_photo`]) return

    state.loading = true
    try {
      const res = await fetch(`${API_BASE}/borrowings/${id}/photo/${stage}`, { headers: getHeaders() })
      if (!res.ok) throw new Error('Could not load the handover photo.')
      const blob = await res.blob()
      // The operator moved on while this was in flight; the blob belongs to a
      // record that is no longer on screen.
      if (state.for !== id) return
      state.url = URL.createObjectURL(blob)
    } catch (error) {
      if (state.for === id) state.error = error.message
    } finally {
      if (state.for === id) state.loading = false
    }
  }

  return { state, load, release }
}

const releasePhoto = createHandoverPhoto('release')
const returnPhoto = createHandoverPhoto('return')
const photoState = (stage) => (stage === 'release' ? releasePhoto.state : returnPhoto.state)

// Which stage the record can be photographed in right now, mirroring
// PHOTO_STAGES in EquipmentBorrowingController. Kept as one source here so the
// button and the backend cannot drift into offering an upload the server then
// refuses.
const photoStages = (record) => {
  const stages = []
  if (record?.status === 'Released' || record?.status === 'Returned') stages.push('release')
  if (record?.status === 'Returned') stages.push('return')
  return stages
}

const photoUploading = ref('')
const photoRemoving = ref('')
const removePhotoDialog = ref({ open: false, stage: '' })

// Mirrors PHOTO_STAGES.removable_in on the server, which is deliberately
// narrower than the window for adding one: the wrong file can be taken back off
// at the counter, but a photo on a record that has moved on is part of a
// finished handover. A stage with no photo has nothing to remove.
const canRemovePhoto = (record, stage) => {
  if (!record || !record[`has_${stage}_photo`]) return false
  return stage === 'release' ? record.status === 'Released' : record.status === 'Returned'
}

const askRemovePhoto = (stage) => {
  removePhotoDialog.value = { open: true, stage }
}

const confirmRemovePhoto = async () => {
  const record = selectedRecord.value
  const stage = removePhotoDialog.value.stage
  if (!record || !stage) return

  const id = record.borrow_id || record.id
  photoRemoving.value = stage
  apiError.value = ''

  try {
    const res = await fetch(`${API_BASE}/borrowings/${id}/photo/${stage}`, {
      method: 'DELETE',
      headers: getHeaders(),
    })
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      throw new Error(errData.message || 'Failed to remove the photo')
    }

    const updated = await res.json()

    // Same in-place patch the upload does, so the card redraws without closing
    // the panel: clear the cached blob's record id or the loader would keep
    // serving the image it already fetched.
    if (selectedRecord.value && (selectedRecord.value.borrow_id || selectedRecord.value.id) === id) {
      selectedRecord.value[`has_${stage}_photo`] = updated[`has_${stage}_photo`]
      const holder = stage === 'release' ? releasePhoto : returnPhoto
      holder.release()
      holder.state.for = null
      await holder.load(selectedRecord.value)
    }

    await fetchData()
    notify('Handover photo removed')
    removePhotoDialog.value = { open: false, stage: '' }
  } catch (error) {
    apiError.value = error.message
    notify(error.message, 'error')
  } finally {
    photoRemoving.value = ''
  }
}

const uploadHandoverPhoto = async (record, stage, file) => {
  if (!file) return
  const id = record.borrow_id || record.id
  photoUploading.value = stage
  apiError.value = ''
  try {
    const payload = new FormData()
    payload.append('stage', stage)
    payload.append('photo', file)

    // getHeaders() sets Content-Type: application/json, which would stop the
    // browser writing the multipart boundary and make the body unparseable.
    // Authorization only, and let fetch set the type.
    const res = await fetch(`${API_BASE}/borrowings/${id}/photo`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${getToken()}`, Accept: 'application/json' },
      body: payload,
    })
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      throw new Error(errData.message || 'Failed to upload the photo')
    }
    const updated = await res.json()
    // Patch the open record in place so the photo appears without closing the
    // panel, then force a re-fetch of the blob by clearing the cached id.
    if (selectedRecord.value && (selectedRecord.value.borrow_id || selectedRecord.value.id) === id) {
      selectedRecord.value[`has_${stage}_photo`] = updated[`has_${stage}_photo`]
      const holder = stage === 'release' ? releasePhoto : returnPhoto
      holder.state.for = null
      await holder.load(selectedRecord.value)
    }
    await fetchData()
    notify('Handover photo saved')
  } catch (error) {
    apiError.value = error.message
    notify(error.message, 'error')
  } finally {
    photoUploading.value = ''
  }
}

const closeModal = () => {
  modal.value.isOpen = false
  selectedRecord.value = null
  releasePhoto.release()
  returnPhoto.release()
  // Cleared as well as released: `for` is what load() checks to skip a refetch,
  // so leaving it set would show the next record's panel with no photo.
  releasePhoto.state.for = null
  returnPhoto.state.for = null
}

// A blob URL outlives the component unless it is revoked by hand, and leaving
// the panel by route change never calls closeModal().
onUnmounted(() => {
  releasePhoto.release()
  returnPhoto.release()
})

// Also the policy cap (MDRRMO feedback, 2026-09-14): a loan runs 1-7 days,
// enforced server-side too (EquipmentBorrowingController::update). The
// operator can still move the date within that window in the dialog; the
// date is always shown before the request goes out.
const DEFAULT_LOAN_DAYS = 7
const MIN_LOAN_DAYS = 1

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
  } else if (newStatus === 'Returned') {
    next.mode = 'return'
  } else {
    next.mode = 'confirm'
  }
  actionDialog.value = next
}

const actionCopy = computed(() => {
  const record = actionDialog.value.record
  const who = `${record?.resident?.first_name || ''} ${record?.resident?.last_name || ''}`.trim() || 'this resident'
  const what = record?.equipment?.item_name || record?.other_equipment_text || 'the equipment'
  switch (actionDialog.value.mode) {
    case 'deny':
      return { title: 'Deny this request', body: `${who} asked for ${what}.`, confirm: 'Deny request' }
    case 'due':
      return {
        title: actionDialog.value.status === 'Approved' ? 'Approve this request' : 'Release to resident',
        body: `Set the date ${who} is expected to bring ${what} back.`,
        confirm: actionDialog.value.status === 'Approved' ? 'Approve request' : 'Release',
      }
    // 'return' falls through to here — same copy as the old unconditional
    // default, now named for the one status left that reaches it.
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
  const { mode, status, record, dueDate, reason, denialReasonCode, condition, conditionNote } = actionDialog.value
  if (mode === 'due' && !dueDate) {
    actionDialog.value.error = 'Pick a due date'
    return
  }
  if (mode === 'deny' && !reason.trim()) {
    actionDialog.value.error = 'Give a reason — the resident is shown this'
    return
  }
  // Required on every return now, Good or Bad — same rule the server
  // enforces (required_if:status,Returned). MDRRMO feedback, 2026-09-19.
  if (mode === 'return' && !conditionNote.trim()) {
    actionDialog.value.error = 'Say what condition it came back in — every return needs a note.'
    return
  }
  const extra = {}
  if (mode === 'due') extra.due_date = dueDate
  if (mode === 'deny') {
    extra.denial_reason = reason.trim()
    extra.denial_reason_code = denialReasonCode
  }
  if (mode === 'return') {
    extra.return_condition = condition
    extra.return_condition_note = conditionNote.trim()
  }
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
      // Field message first: Laravel's top-level `message` appends "(and 1
      // more error)" when more than one field failed.
      const firstError = errData.errors ? Object.values(errData.errors)[0]?.[0] : null
      throw new Error(firstError || errData.message || 'Failed to update status')
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

onMounted(async () => {
  fetchMasterLists()
  await fetchData()
  // Dashboard rows deep-link here with ?request=<borrow_id>.
  const target = borrowings.value.find((b) => b.borrow_id === Number(route.query.request))
  if (target) openDetail(target)
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
   table, not a band of its own. The header's own gap is mb-6 (24px) now,
   the same as every other page's header-to-content gap, not a third
   page-local value. */
.page-tabs { margin-bottom: 24px; }

/* DataTablePage's own .dtp-table rule already sets table-layout: fixed and
   the header/row styling; this only adds the page-specific min-width floor
   (720px was load-bearing: without it a fixed table's width:100% lets a
   narrow wrapper crush every column instead of scrolling — the wrapper's
   own overflow-x, Vuetify's default, does the rest). */
.borrow-table :deep(.dtp-table table) { min-width: 784px; }
.borrow-table :deep(.dtp-table td) { white-space: nowrap; }
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

</style>
