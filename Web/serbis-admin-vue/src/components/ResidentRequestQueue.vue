<template>
  <v-container fluid class="dashboard-bg" :class="{ 'pa-0': !standalone }">
    <div class="d-flex flex-column w-100">

      <PageHeader v-if="standalone" title="Resident Requests" />

      <DataTablePage
        :loading="initialLoad"
        class="request-table"
        v-model:search="search"
        search-placeholder="Search by transaction number, name, service, barangay..."
        :tabs="statusTabItems"
        :status="filters.status"
        @update:status="filters.status = $event"
        :headers="tableHeaders"
        :items="filteredAndSortedRequests"
        item-value="request_id"
        :no-data-text="emptyListMessage"
        :page="page"
        @update:page="page = $event"
        :items-per-page="itemsPerPage"
        @update:items-per-page="itemsPerPage = $event"
        result-noun="requests"
        :row-props="(ctx) => ({
          class: [`row-${(ctx.item.status || 'Pending').toLowerCase()}`, isSelected(ctx.item) ? 'row-selected' : ''],
          role: 'button',
          tabindex: 0,
          'aria-current': isSelected(ctx.item) ? 'true' : undefined,
          'aria-label': `${ctx.item._requesterName}, ${ctx.item._secondary}, ${ctx.item.status || 'Pending'}`,
        })"
        :active-filters="activeFilters"
        @clear-filter="clearFilter"
        @clear-all="clearAllFilters"
        @click:row="(_event, { item }) => selectRequest(item)"
      >
        <template v-slot:filters>
          <RequestFiltersBar
            v-model:barangay="filters.barangay"
            v-model:unit="filters.unit"
            v-model:status="filters.statusPick"
            :status-options="filters.status === 'All' ? statusTabs : null"
            :barangay-options="barangayOptions"
            :unit-options="unitOptions"
          />
        </template>

        <template v-slot:actions>
          <ExportMenu type="request" :rows="filteredAndSortedRequests" :selected-ids="selectedIds" />
          <v-btn
            color="secondary"
            variant="flat"
            class="text-none font-weight-bold text-white"
            height="40"
            @click="openCreateDialog"
          >
            <v-icon start size="small">mdi-account-plus-outline</v-icon>
            Log Service Request
          </v-btn>
        </template>

        <template v-if="selectedIds.size > 0" v-slot:before-table>
          <div class="d-flex align-center justify-space-between px-4 py-2 subtle-surface rounded-lg mb-3">
            <span class="text-caption font-weight-bold" aria-live="polite">{{ selectedIds.size }} selected</span>
            <div class="d-flex align-center gap-2">
              <v-btn size="small" height="36" variant="outlined" color="primary" class="text-none" @click="selectedIds.clear()">Clear</v-btn>
              <v-btn
                color="error"
                variant="flat"
                size="small"
                height="36"
                class="text-none font-weight-bold"
                :loading="bulkLoading"
                @click="openReason('bulk')"
              >
                Disapprove {{ selectedIds.size }}
                <span class="d-sr-only">selected requests</span>
              </v-btn>
            </div>
          </div>
        </template>

        <template v-slot:item.select="{ item }">
          <v-checkbox-btn
            :model-value="selectedIds.has(itemId(item))"
            density="compact"
            :aria-label="`Select ${item._requesterName}'s request`"
            @click.stop="toggleSelect(item)"
          ></v-checkbox-btn>
        </template>

        <template v-slot:item.request_id="{ item }">
          <span class="d-block row-date mono">{{ transactionNo(item.request_id) }}</span>
        </template>

        <template v-slot:item._firstName="{ item }">
          <PersonCell
            :name="item._firstName"
            :secondary="item._barangay"
            :initials="requesterInitials(item)"
            :title="item._requesterName"
          >
            <template v-if="requesterAccountType(item)" #badge>
              <v-chip size="x-small" variant="tonal" label class="ml-2 flex-shrink-0">{{ requesterAccountType(item) }}</v-chip>
            </template>
          </PersonCell>
        </template>

        <template v-slot:item._lastName="{ item }">
          <span class="text-truncate d-block font-weight-bold" :title="item._lastName">{{ item._lastName || '—' }}</span>
        </template>

        <template v-slot:item._secondary="{ item }">
          <span class="text-truncate d-block" :title="item._secondary">{{ item._secondary }}</span>
        </template>

        <template v-slot:item.status="{ item }">
          <span class="status-col-pill"><StatusPill small :status="outcomeLabel(item.status || 'Pending')" /></span>
        </template>

        <template v-slot:item._unit="{ item }">
          <template v-if="item._unit || item.responders?.length">
            <div class="text-truncate">{{ item._unit || 'No unit' }}</div>
            <div v-if="item.responders?.length" class="text-caption text-medium-emphasis">
              {{ item.responders.length }} {{ item.responders.length === 1 ? 'responder' : 'responders' }}
            </div>
          </template>
          <span v-else class="text-medium-emphasis">—</span>
        </template>

        <template v-slot:item.created_at="{ item }">
          <div class="text-truncate row-date">{{ formatDate(item.created_at) }}</div>
          <div v-if="item._waitDays !== null" class="text-caption" :class="WAIT_CLASS[waitTone(item._waitDays)]">
            {{ item._waitDays }}d waiting
          </div>
        </template>

        <template v-slot:item._closedTs="{ item }">
          <span class="text-truncate d-block row-date" :class="item._closedTs ? '' : 'text-medium-emphasis'">{{ item._closedTs ? formatDate(item._closedTs) : '—' }}</span>
        </template>
      </DataTablePage>

      <v-dialog
        :model-value="!!selectedRequest"
        @update:model-value="(v) => { if (!v) selectedRequest = null }"
        max-width="min(820px, 95vw)"
        class="detail-modal"
      >
        <v-card v-if="selectedRequest" rounded="lg" elevation="6" class="d-flex flex-column detail-modal-card">
          <DetailDialogHeader
            :name="requesterName(selectedRequest)"
            :initials="requesterInitials(selectedRequest)"
            :secondary="requesterBarangay(selectedRequest)"
            :wait-days="waitDays"
            @close="selectedRequest = null"
          >
            <template v-slot:status><StatusPill :status="outcomeLabel(selectedRequest.status || 'Pending')" /></template>
            <template v-slot:actions><ExportMenu type="request" :row="selectedRequest" /></template>
          </DetailDialogHeader>

          <v-divider></v-divider>

          <div class="pa-6 overflow-y-auto flex-grow-1">
            <v-alert v-if="apiError" type="error" variant="tonal" class="mb-4" density="compact">{{ apiError }}</v-alert>

            <div class="detail-cols">
            <section class="detail-section">
              <h3 class="sect-label">Request</h3>
              <dl class="kv">
                <dt>Service</dt><dd class="font-weight-bold">{{ selectedRequest.service?.service_name || 'Other' }}</dd>
                <dt>Transaction</dt><dd class="mono">{{ transactionNo(selectedRequest.request_id) }}</dd>
                <dt>Submitted</dt><dd>{{ formatDateTime(selectedRequest.created_at) }}</dd>
                <template v-if="selectedRequest.preferred_date">
                  <dt>Preferred date</dt><dd>{{ formatPreferredDate(selectedRequest.preferred_date) }}</dd>
                </template>
              </dl>
            </section>

            <section class="detail-section">
              <h3 class="sect-label">Assignment</h3>
              <dl class="kv">
                <dt>Unit</dt>
                <dd :class="{ 'text-medium-emphasis': !selectedRequest.vehicle }">{{ selectedRequest.vehicle ? vehicleName(selectedRequest.vehicle) : 'None assigned' }}</dd>
                <dt>Responders</dt>
                <dd>
                  <template v-if="selectedRequest.responders?.length">
                    <v-chip v-for="r in selectedRequest.responders" :key="r.responder_id" size="small" class="mr-1 mb-1">{{ r.name }}</v-chip>
                  </template>
                  <span v-else class="text-medium-emphasis">None assigned</span>
                </dd>
              </dl>
            </section>
            </div>

            <section class="detail-section">
              <h3 class="sect-label">Description</h3>
              <v-card variant="outlined" class="pa-4 text-body-2 rounded-lg subtle-surface" style="border-color: rgba(var(--v-theme-on-surface), 0.08);">
                <template v-if="descriptionLines.length > 0">
                  <div v-for="(line, i) in descriptionLines" :key="i" class="description-line">{{ line }}</div>
                </template>
                <template v-else>No description provided by the Head of the Family.</template>
              </v-card>
              <dl v-if="selectedRequest.landmark" class="kv mt-3">
                <dt>Location</dt><dd>{{ selectedRequest.landmark }}</dd>
              </dl>
            </section>

            <section class="detail-section">
              <h3 class="sect-label">Contact</h3>
              <dl class="kv"><dt>Phone</dt><dd>{{ requesterPhone(selectedRequest) }}</dd></dl>
            </section>

            <section v-if="closedLabel" class="detail-section">
              <h3 class="sect-label">Outcome</h3>
              <dl class="kv">
                <dt>{{ closedLabel }}</dt><dd>{{ formatDateTime(selectedRequest.resolved_at) }}</dd>
                <template v-if="selectedRequest.status === 'Disapproved' && selectedRequest.remarks">
                  <dt>Reason</dt><dd>{{ selectedRequest.remarks }}</dd>
                </template>
              </dl>
            </section>

            <template v-if="attachments.length > 0">
            <h3 class="sect-label">Attachments</h3>

            <div class="detail-section">
              <div class="d-flex flex-wrap gap-3 attachments-row">
                <div v-for="a in attachments" :key="a.key">
                  <v-skeleton-loader
                    v-if="a.state.loading"
                    type="image"
                    height="140"
                    width="180"
                    class="rounded-lg"
                  ></v-skeleton-loader>
                  <v-alert
                    v-else-if="a.state.error"
                    type="error"
                    variant="tonal"
                    density="compact"
                    class="attachment-error"
                  >{{ a.state.error }}</v-alert>
                  <a
                    v-else-if="a.state.url && a.state.type === 'application/pdf'"
                    :href="a.state.url"
                    target="_blank"
                    rel="noopener"
                    class="attachment-tile attachment-tile--file rounded-lg"
                    :aria-label="`Open the ${a.label.toLowerCase()} (PDF) in a new tab`"
                  >
                    <v-icon size="40" color="primary">mdi-file-pdf-box</v-icon>
                    <span class="text-body-2 font-weight-bold mt-1">Open PDF</span>
                  </a>
                  <button
                    v-else-if="a.state.url"
                    type="button"
                    class="attachment-tile rounded-lg"
                    :aria-label="`View the ${a.label.toLowerCase()} full size`"
                    @click="openLightbox(a)"
                  >
                    <v-img :src="a.state.url" :alt="a.alt" cover height="140" width="180"></v-img>
                    <span class="attachment-badge" aria-hidden="true">
                      <v-icon size="16">mdi-magnify-plus-outline</v-icon>
                    </span>
                    <span class="attachment-scrim" aria-hidden="true">
                      <v-icon size="18">mdi-magnify-plus-outline</v-icon>
                      View
                    </span>
                  </button>
                  <div class="text-caption text-medium-emphasis mt-1">{{ a.label }}</div>
                </div>
              </div>
            </div>
            </template>
          </div>

          <div v-if="showActions" class="detail-footer d-flex align-center flex-wrap gap-3 px-6 py-4">
            <template v-if="selectedRequest.status === 'Pending' || !selectedRequest.status">
              <v-btn color="error" variant="outlined" class="text-none font-weight-bold" height="40" :loading="loading" @click="openReason('disapprove')">
                Disapprove
              </v-btn>
              <v-spacer></v-spacer>
              <v-btn
                v-if="!isProgramRequest(selectedRequest)"
                color="primary"
                variant="outlined"
                class="text-none font-weight-bold"
                height="40"
                @click="vehicleModal.isOpen = true"
              >
                {{ formData.vehicle_id ? selectedVehicle?.unit_identifier : 'Select Vehicle' }}
              </v-btn>
              <v-btn color="primary" variant="outlined" class="text-none font-weight-bold" height="40" @click="openResponderModal">
                {{ (selectedRequest.responders?.length || 0) > 0 ? `Responders (${selectedRequest.responders.length})` : 'Select Responders' }}
              </v-btn>
              <v-btn color="secondary" variant="flat" class="text-none font-weight-bold text-white" height="40" :loading="loading" @click="openReason('approve')">
                {{ isProgramRequest(selectedRequest) ? 'Approve' : 'Approve & Dispatch' }}
              </v-btn>
            </template>
            <template v-else>
              <v-spacer></v-spacer>
              <v-btn color="success" variant="flat" class="text-none font-weight-bold" height="40" :loading="loading" @click="openResolveConfirm">
                Mark as Resolved
              </v-btn>
            </template>
          </div>
        </v-card>
      </v-dialog>
    </div>

    <v-dialog v-model="lightbox.open" max-width="900">
      <v-card rounded="lg" elevation="6">
        <v-card-title class="pa-4 border-b d-flex justify-space-between align-center">
          <span class="text-h6 font-weight-bold">{{ lightboxAttachment?.label }}</span>
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" aria-label="Close" @click="lightbox.open = false"></v-btn>
        </v-card-title>
        <v-card-text class="pa-0 subtle-surface">
          <v-img
            v-if="lightboxAttachment?.state.url"
            :src="lightboxAttachment.state.url"
            :alt="lightboxAttachment.alt"
            max-height="70vh"
          ></v-img>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-dialog v-model="vehicleModal.isOpen" max-width="600">
      <v-card rounded="lg" elevation="6">
        <v-card-title class="pa-4 border-b d-flex justify-space-between align-center">
          <span class="text-h6 font-weight-bold">Available Vehicles</span>
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" @click="vehicleModal.isOpen = false"></v-btn>
        </v-card-title>

        <v-card-text class="pa-0 subtle-surface" style="max-height: 400px; overflow-y: auto;">
          <v-list v-if="availableVehicles.length > 0" bg-color="transparent" class="py-0">
            <v-list-item
              v-for="v in availableVehicles"
              :key="v.vehicle_id"
              class="vehicle-option px-4 py-3"
              :active="formData.vehicle_id === v.vehicle_id"
              @click="selectVehicle(v.vehicle_id)"
            >
              <template v-slot:prepend>
                <v-avatar
                  :color="formData.vehicle_id === v.vehicle_id ? 'success' : undefined"
                  :variant="formData.vehicle_id === v.vehicle_id ? 'flat' : 'tonal'"
                  size="42"
                  class="mr-3"
                >
                  <v-icon :color="formData.vehicle_id === v.vehicle_id ? 'white' : undefined">{{ vehicleIcon(v.type) }}</v-icon>
                </v-avatar>
              </template>

              <v-list-item-title class="font-weight-bold text-body-1">{{ vehicleName(v) }}</v-list-item-title>
              <v-list-item-subtitle class="text-caption text-uppercase font-weight-bold">
                {{ v.type }}<template v-if="v.specification"> &bull; {{ v.specification }}</template>
              </v-list-item-subtitle>

              <template v-slot:append>
                <v-icon v-if="formData.vehicle_id === v.vehicle_id" color="success">mdi-check-circle</v-icon>
              </template>
            </v-list-item>
          </v-list>
          <div v-else class="pa-6 text-center text-medium-emphasis">
            <v-icon size="48" class="mb-3">mdi-car-off</v-icon>
            <div class="text-h6 font-weight-bold">No Vehicles Available</div>
            <div class="text-body-2">
              No free unit of a type this service uses. Units are either dispatched, under maintenance, or of a type set aside for other services.
            </div>
          </div>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-dialog v-model="responderModal.isOpen" max-width="600">
      <v-card rounded="lg" elevation="6">
        <v-card-title class="pa-4 border-b d-flex justify-space-between align-center">
          <span class="text-h6 font-weight-bold">Select Responders</span>
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" @click="responderModal.isOpen = false"></v-btn>
        </v-card-title>

        <v-card-text class="pa-0 subtle-surface" style="max-height: 400px; overflow-y: auto;">
          <v-alert v-if="responderModal.error" type="error" variant="tonal" density="compact" class="ma-4">{{ responderModal.error }}</v-alert>

          <v-list v-if="responderPickerList.length > 0" bg-color="transparent" class="py-0">
            <v-list-item
              v-for="r in responderPickerList"
              :key="r.responder_id"
              class="vehicle-option px-4 py-3"
              :disabled="responderModal.loading || (r.status !== 'available' && !responderModal.selectedIds.has(r.responder_id))"
              @click="toggleResponder(r)"
            >
              <template v-slot:prepend>
                <v-checkbox-btn :model-value="responderModal.selectedIds.has(r.responder_id)" density="compact"></v-checkbox-btn>
              </template>

              <v-list-item-title class="font-weight-bold text-body-1">{{ r.name }}</v-list-item-title>
              <v-list-item-subtitle class="text-caption text-uppercase font-weight-bold">
                {{ r.position }}<template v-if="r.status !== 'available'"> &bull; {{ r.status }}</template>
              </v-list-item-subtitle>
            </v-list-item>
          </v-list>
          <div v-else class="pa-6 text-center text-medium-emphasis">
            <v-icon size="48" class="mb-3">mdi-account-off-outline</v-icon>
            <div class="text-h6 font-weight-bold">No Responders Available</div>
            <div class="text-body-2">Every responder is deployed elsewhere or off duty.</div>
          </div>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-dialog v-model="reasonDialog.open" max-width="440" persistent @after-leave="clearReason">
      <v-card rounded="lg">
        <v-card-title class="d-flex justify-space-between align-center text-subtitle-1 font-weight-bold pa-5 pb-2 text-high-emphasis">
          <span>{{ reasonCopy.title }}</span>
          <v-btn
            icon="mdi-close" variant="tonal" rounded="circle" size="small" aria-label="Close"
            :disabled="loading || bulkLoading" @click="reasonDialog.open = false"
          ></v-btn>
        </v-card-title>
        <v-card-text class="px-5 pt-2">
          <div class="text-body-2 text-medium-emphasis mb-4">{{ reasonCopy.body }}</div>

          <template v-if="reasonCopy.showField">
            <v-textarea
              v-if="reasonDialog.kind !== 'approve' || noteExpanded"
              v-model="reasonDialog.reason"
              :label="reasonCopy.label"
              :placeholder="reasonCopy.placeholder"
              :hint="reasonCopy.hint"
              persistent-hint
              variant="outlined"
              rows="3"
              counter="255"
              maxlength="255"
              autofocus
              :error-messages="reasonDialog.error"
              @update:model-value="reasonDialog.error = ''"
            ></v-textarea>
            <v-btn
              v-else
              variant="outlined" color="primary"
              size="small"
              density="compact"
              class="text-none"
              prepend-icon="mdi-plus"
              @click="noteExpanded = true"
            >
              Add a note
            </v-btn>
          </template>
          <div v-else class="text-caption text-medium-emphasis">
            No linked account — there is no mobile app to show a note in.
          </div>
        </v-card-text>
        <v-card-actions class="px-5 pb-5 pt-0 justify-end gap-3">
          <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" height="44" @click="reasonDialog.open = false">Cancel</v-btn>
          <v-btn
            :color="reasonDialog.kind === 'approve' ? 'secondary' : 'error'"
            variant="flat"
            class="px-6 text-none font-weight-bold"
            :class="reasonDialog.kind === 'approve' ? 'text-white' : ''"
            height="44"
            :loading="loading || bulkLoading"
            @click="confirmReason"
          >{{ reasonCopy.confirm }}</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="resolveDialog.open" max-width="440">
      <v-card rounded="lg">
        <v-card-title class="text-subtitle-1 font-weight-bold pa-5 pb-2 text-high-emphasis">
          Mark this request as resolved?
        </v-card-title>
        <v-card-text class="px-5 pt-2 text-body-2 text-medium-emphasis">
          <p class="mb-3">
            Resolving <strong class="text-high-emphasis">{{ resolveDialog.label }}</strong> closes it permanently.
            The status cannot be changed back from this panel.
          </p>
          <p class="mb-0">
            The trip log stays editable — return timestamps and odometer readings
            can still be filled in after this. It is the status that is permanent,
            not the record.
          </p>
        </v-card-text>
        <v-card-actions class="px-5 pb-5 pt-0 justify-end gap-3">
          <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" height="44" :disabled="loading" @click="resolveDialog.open = false">
            Cancel
          </v-btn>
          <v-btn
            color="success"
            variant="flat"
            class="px-6 text-none font-weight-bold"
            height="44"
            :loading="loading"
            @click="confirmResolve"
          >
            Resolve permanently
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="createDialog.open" max-width="640" scrollable persistent>
      <v-card rounded="lg">
        <v-card-title class="d-flex justify-space-between align-center pa-6 border-b bg-surface">
          <span class="text-h6 font-weight-bold">Log Service Request</span>
          <v-btn icon="mdi-close" variant="tonal" rounded="circle" size="small" aria-label="Close" @click="createDialog.open = false"></v-btn>
        </v-card-title>
        <v-card-text class="pa-6" style="max-height: 70vh;">
          <v-alert v-if="createDialog.error" type="error" variant="tonal" density="compact" class="mb-4">{{ createDialog.error }}</v-alert>

          <v-btn-toggle
            v-model="createDialog.requesterType"
            color="primary"
            variant="outlined"
            mandatory
            divided
            class="mb-4 d-flex"
          >
            <v-btn value="resident" class="text-none flex-grow-1">Registered Head of the Family</v-btn>
            <v-btn value="walkin" class="text-none flex-grow-1">No account</v-btn>
          </v-btn-toggle>

          <v-autocomplete
            v-if="createDialog.requesterType === 'resident'"
            v-model="createDialog.form.resident_id"
            :items="residentOptions"
            label="Head of the Family"
            placeholder="Search by name"
            variant="outlined"
            density="comfortable"
            class="mb-2"
            :rules="[required]"
          ></v-autocomplete>

          <template v-else>
            <v-row dense>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model="createDialog.form.walk_in_first_name"
                  label="First name"
                  placeholder="e.g. Juan"
                  variant="outlined"
                  density="comfortable"
                  class="mb-2"
                  :rules="[required, nameFormat]"
                ></v-text-field>
              </v-col>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model="createDialog.form.walk_in_last_name"
                  label="Last name"
                  placeholder="e.g. Dela Cruz"
                  variant="outlined"
                  density="comfortable"
                  class="mb-2"
                  :rules="[required, nameFormat]"
                ></v-text-field>
              </v-col>
            </v-row>
            <v-text-field
              v-model="createDialog.form.walk_in_contact_number"
              label="Contact number"
              placeholder="e.g. 09171234567"
              variant="outlined"
              density="comfortable"
              class="mb-2"
              :rules="[required, phoneFormat]"
            ></v-text-field>
          </template>

          <v-select
            v-model="createDialog.form.service_id"
            :items="serviceOptions"
            label="Service"
            variant="outlined"
            density="comfortable"
            class="mb-2"
            :rules="[required]"
          ></v-select>

          <v-textarea
            v-model="createDialog.form.description"
            label="Description"
            placeholder="e.g. Fallen tree blocking the road at Purok 3"
            variant="outlined"
            density="comfortable"
            rows="3"
            class="mb-2"
            :rules="[required]"
          ></v-textarea>
        </v-card-text>
        <v-card-actions class="pa-6 pt-0 d-flex justify-end gap-3 border-t">
          <v-btn variant="outlined" color="primary" class="text-none font-weight-bold" height="44" @click="createDialog.open = false">Cancel</v-btn>
          <v-btn
            color="secondary"
            variant="flat"
            class="px-6 text-none font-weight-bold text-white"
            height="44"
            :loading="createDialog.loading"
            @click="submitWalkIn"
          >File request</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { getToken } from '@/composables/authToken'
import { outcomeLabel, authHeaders, openWaitDays, waitTone } from '@/composables/adminUi'
import { API_BASE } from '@/config/api'
import PageHeader from '@/components/PageHeader.vue'
import DataTablePage from '@/components/DataTablePage.vue'
import StatusPill from '@/components/StatusPill.vue'
import PersonCell from '@/components/PersonCell.vue'
import DetailDialogHeader from '@/components/DetailDialogHeader.vue'
import '@/components/detail-dialog.css'
import RequestFiltersBar from '@/components/RequestFiltersBar.vue'
import ExportMenu from '@/components/ExportMenu.vue'
import { requesterName, requesterNameParts, requesterAccountType, isWalkIn, requesterInitials, requesterPhone, requesterBarangay, vehicleName, vehicleIcon, getVehicleNameById, useDescriptionLines, useSelection, transactionNo } from '@/composables/requestDisplay'
import { useRequestAttachments } from '@/composables/useRequestAttachments'
import { useRequestFetch, AMBULANCE_SERVICE_CODE, itemId } from '@/composables/useRequestFetch'
import { useFilteredRequestList } from '@/composables/useFilteredRequestList'
import { useUpdateStatus } from '@/composables/useUpdateStatus'
import { useResolveDialog } from '@/composables/useResolveDialog'
import { emptyReasonDialog, useReasonActions } from '@/composables/useReasonActions'

defineProps({
  standalone: { type: Boolean, default: true },
})

const route = useRoute()
const getHeaders = authHeaders

const search = ref('')
const initialLoad = ref(true)
const loading = ref(false)
const bulkLoading = ref(false)
const apiError = ref('')
const page = ref(1)
const itemsPerPage = ref(10)

const isProgramRequest = (r) => r?.service?.category === 'programs'

// statusPick is the Status select on the All tab; the tabs own `status`.
const filters = reactive({ status: 'All', statusPick: 'All', barangay: 'All', unit: 'All' })
const vehicleModal = ref({ isOpen: false })
const responderModal = ref({ isOpen: false, loading: false, error: '', selectedIds: new Set() })
const selectedRequest = ref(null)
const selectedIds = reactive(new Set())

const formData = ref({ remarks: '', internal_notes: '', vehicle_id: null })

const required = (v) => (v !== null && v !== undefined && String(v).trim() !== '') || 'Required'
// Letters (incl. accented/Ñ), spaces, hyphens, apostrophes, periods — no digits.
const nameFormat = (v) => !v || /^[\p{L}.'-]+(?:\s[\p{L}.'-]+)*$/u.test(v.trim()) || 'Letters only'
// Same shapes ServiceRequestController's PhoneNumber::REGEX accepts.
const phoneFormat = (v) => !v || /^(?:09\d{9}|639\d{9}|\+639\d{9})$/.test(v.trim()) || 'Use 09XXXXXXXXX'

const emptyCreateForm = () => ({
  resident_id: null,
  walk_in_first_name: '',
  walk_in_last_name: '',
  walk_in_contact_number: '',
  service_id: null,
  description: '',
})
const createDialog = ref({
  open: false,
  loading: false,
  error: '',
  requesterType: 'resident',
  form: emptyCreateForm(),
})

const openCreateDialog = () => {
  createDialog.value = {
    open: true,
    loading: false,
    error: '',
    requesterType: 'resident',
    form: emptyCreateForm(),
  }
}

const { attachments, lightbox, lightboxAttachment, openLightbox, loadAttachments, releaseAttachments } =
  useRequestAttachments(selectedRequest, { itemId, getHeaders })

const { requests, vehicles, residents, services, responders, listAbortController, fetchData, fetchRequests, selectRequest } =
  useRequestFetch({ isAmbulance: false, getHeaders, initialLoad, apiError, formData, selectedRequest, loadAttachments })

const reasonDialog = ref(emptyReasonDialog())

const { updateStatus } = useUpdateStatus({ selectedRequest, loading, apiError, formData, itemId, getHeaders, fetchRequests, reasonDialog })

const { resolveDialog, openResolveConfirm, confirmResolve } = useResolveDialog(selectedRequest, { requesterName, updateStatus, apiError })

const { noteExpanded, openReason, clearReason, confirmReason } =
  useReasonActions(reasonDialog, { formData, apiError, bulkLoading, requests, selectedIds, itemId, getHeaders, updateStatus, fetchRequests })

const { isSelected, toggleSelect } = useSelection(selectedRequest, selectedIds, itemId)

const descriptionLines = useDescriptionLines(selectedRequest)

const reasonCopy = computed(() => {
  const req = selectedRequest.value
  const who = req && requesterName(req) !== 'Unknown Head of the Family' && requesterName(req) !== 'Unknown requester' ? requesterName(req) : ''
  const what = selectedRequest.value?.service?.service_name || 'this service'
  const hasAccount = req ? !isWalkIn(req) : true
  const appHint = 'Shown to the Head of the Family in the mobile app.'
  const noAppHint = 'No linked account — kept as an internal record only, not shown to anyone.'
  switch (reasonDialog.value.kind) {
    case 'approve': {
      const unit = getSelectedVehicleName()
      const note = { label: 'Note for the Head of the Family (optional)', hint: appHint, showField: hasAccount }
      if (req && isProgramRequest(req)) {
        return {
          ...note,
          title: 'Approve this request',
          body: `This approves the request for ${what}. Nothing is dispatched.`,
          placeholder: 'e.g. We will confirm the schedule with your office',
          confirm: 'Approve',
        }
      }
      return unit
        ? {
            ...note,
            title: 'Approve and dispatch',
            body: `${unit} will be sent for ${what}.`,
            placeholder: 'e.g. Wait by the barangay hall, the unit is on its way',
            confirm: 'Approve & dispatch',
          }
        : {
            ...note,
            title: 'Approve without a vehicle',
            body: `This approves the request for ${what} and marks it Responding. No vehicle is assigned — cancel and use Select Vehicle first if one is going out.`,
            placeholder: 'e.g. Our team will visit your address this afternoon',
            confirm: 'Approve',
          }
    }
    case 'bulk':
      return {
        title: `Disapprove ${selectedIds.size} request${selectedIds.size === 1 ? '' : 's'}`,
        body: 'Every selected request is declined with this same reason.',
        label: 'Reason for declining',
        placeholder: 'e.g. No unit free for the requested window',
        hint: 'Shown to any Head of the Family in the selection with a linked account; kept as an internal record for a walk-in with none.',
        showField: true,
        confirm: 'Disapprove all',
      }
    default:
      return {
        title: 'Disapprove this request',
        body: who ? `${who} asked for ${what}.` : `A request for ${what}.`,
        label: 'Reason for declining',
        placeholder: 'e.g. No unit free for the requested window — file again for tomorrow',
        hint: hasAccount ? appHint : noAppHint,
        showField: true,
        confirm: 'Disapprove request',
      }
  }
})

const formatPreferredDate = (value, month = 'long') => {
  const [y, m, d] = String(value).split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('en-PH', { year: 'numeric', month, day: 'numeric' })
}

const statusTabs = ['All', 'Pending', 'Responding', 'Resolved', 'Disapproved', 'Cancelled']

const statusTabItems = computed(() =>
  statusTabs.map((status) => ({ value: status, label: status, count: requestCounts.value[status] })),
)

const unitOptions = computed(() => {
  const pool = vehicles.value.filter(v => v.type !== 'Ambulance')
  return ['All', 'Unassigned', ...pool.map(v => v.unit_identifier)]
})

const HEADER_WIDTH_TOTAL = 96
const tableHeaders = computed(() => {
  const columns = [
    { title: 'TXN No.', key: 'request_id', width: 10 },
    { title: 'First Name', key: '_firstName', width: 12 },
    { title: 'Last Name', key: '_lastName', width: 12 },
    { title: 'Service', key: '_secondary', width: 14 },
    { title: 'Status', key: 'status', width: 10, sortable: false },
    { title: 'Assigned', key: '_unit', width: 14, sortable: false },
    { title: 'Submitted', key: 'created_at', width: 12 },
    // Nothing is closed on these tabs, so the column would be all dashes.
    ...(['Pending', 'Responding'].includes(filters.status) ? [] : [{ title: 'Closed', key: '_closedTs', width: 12 }]),
  ]
  const scale = HEADER_WIDTH_TOTAL / columns.reduce((sum, c) => sum + c.width, 0)
  return [
    { title: '', key: 'select', sortable: false, width: '48px' },
    ...columns.map(c => ({ ...c, width: `${Math.round(c.width * scale * 10) / 10}%` })),
  ]
})

if (statusTabs.includes(route.query.status)) filters.status = route.query.status

const availableVehicles = computed(() => {
  const allowed = vehicleTypesByService.value[selectedRequest.value?.service?.code] ?? []
  return vehicles.value.filter(v =>
    v.status === 'Available' && v.type !== 'Ambulance' && (allowed.length === 0 || allowed.includes(v.type)),
  )
})

// Available responders, plus whichever are already on this request (so an
// admin removing one sees who they are removing, and toggling a different
// row's checkbox never silently drops one just by being absent from the list).
const responderPickerList = computed(() => {
  const assignedIds = responderModal.value.selectedIds
  return responders.value.filter(r => r.status === 'available' || assignedIds.has(r.responder_id))
})

const openResponderModal = () => {
  const assigned = selectedRequest.value?.responders || []
  responderModal.value = {
    isOpen: true,
    loading: false,
    error: '',
    selectedIds: new Set(assigned.map(r => r.responder_id)),
  }
}

const toggleResponder = async (responder) => {
  const { selectedIds } = responderModal.value
  const next = new Set(selectedIds)
  if (next.has(responder.responder_id)) next.delete(responder.responder_id)
  else next.add(responder.responder_id)

  responderModal.value.loading = true
  responderModal.value.error = ''
  try {
    const res = await fetch(`${API_BASE}/service-requests/${itemId(selectedRequest.value)}/responders`, {
      method: 'PATCH',
      headers: getHeaders(),
      body: JSON.stringify({ responder_ids: Array.from(next) }),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null
      throw new Error(firstError || data.message || 'Failed to update responders')
    }
    responderModal.value.selectedIds = next
    selectedRequest.value.responders = data.responders
    await fetchRequests()
  } catch (error) {
    responderModal.value.error = error.message
  } finally {
    responderModal.value.loading = false
  }
}

const vehicleTypesByService = ref({})
const fetchVehicleTypes = async () => {
  try {
    const res = await fetch(`${API_BASE}/service-vehicle-types`, { headers: getHeaders(), signal: listAbortController.signal })
    if (!res.ok) return
    const body = await res.json()
    vehicleTypesByService.value = Object.fromEntries((body.data || []).map(row => [row.code, row.vehicle_types]))
  } catch (error) {
    if (error.name !== 'AbortError') console.error('Failed to fetch vehicle types:', error)
  }
}

const residentOptions = computed(() => residents.value
  .map(r => ({
    title: `${r.last_name}, ${r.first_name}${r.barangay?.barangay_name ? ' — ' + r.barangay.barangay_name : ''}`,
    value: r.resident_id,
  }))
  .slice()
  .sort((a, b) => a.title.localeCompare(b.title)))

const serviceOptions = computed(() => services.value
  .filter(s => s.code !== AMBULANCE_SERVICE_CODE && s.is_active !== false)
  .map(s => ({ title: s.service_name, value: s.service_id })))

const selectedVehicle = computed(() =>
  vehicles.value.find(v => v.vehicle_id === formData.value.vehicle_id) || null
)

const { barangayOptions, requestCounts, filteredAndSortedRequests, emptyListMessage, activeFilters, clearFilter, clearAllFilters } =
  useFilteredRequestList(requests, filters, search, {
    requesterName,
    secondaryFn: (r) => r.service?.service_name || 'Other',
    decorate: (r) => ({
      _barangay: requesterBarangay(r),
      ...requesterNameParts(r),
      _waitDays: openWaitDays(r.status, r.created_at),
      // resolved_at is stamped for Resolved, Cancelled and Disapproved alike.
      _closedTs: r.resolved_at || null,
    }),
  })

const WAIT_CLASS = { muted: 'text-medium-emphasis', warning: 'text-warning-strong', error: 'text-error' }

const waitDays = computed(() => (selectedRequest.value?.status === 'Pending' ? openWaitDays(selectedRequest.value?.status, selectedRequest.value?.created_at) : null))

const CLOSED_LABELS = { Resolved: 'Resolved on', Disapproved: 'Disapproved on', Cancelled: 'Cancelled on' }
const closedLabel = computed(() => CLOSED_LABELS[selectedRequest.value?.status] || null)

const showActions = computed(() =>
  selectedRequest.value && (
    selectedRequest.value.status === 'Pending'
    || !selectedRequest.value.status
    || selectedRequest.value.status === 'Responding'
  )
)

const formatDate = (dateStr) => dateStr ? new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : ''
const formatDateTime = (dateStr) => dateStr ? new Date(dateStr).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''

const selectVehicle = (id) => {
  formData.value.vehicle_id = id
  vehicleModal.value.isOpen = false
}

const getSelectedVehicleName = () => getVehicleNameById(vehicles.value, formData.value.vehicle_id)

const submitWalkIn = async () => {
  const form = createDialog.value.form
  const isResident = createDialog.value.requesterType === 'resident'

  if (isResident && !form.resident_id) {
    createDialog.value.error = 'Pick a Head of the Family'
    return
  }
  if (!isResident) {
    if (!form.walk_in_first_name.trim() || !form.walk_in_last_name.trim() || !form.walk_in_contact_number.trim()) {
      createDialog.value.error = 'Name and contact number are required for someone with no account'
      return
    }
    if (phoneFormat(form.walk_in_contact_number) !== true) {
      createDialog.value.error = 'Enter a valid contact number (e.g. 09171234567)'
      return
    }
    if (nameFormat(form.walk_in_first_name) !== true || nameFormat(form.walk_in_last_name) !== true) {
      createDialog.value.error = 'Names may only contain letters'
      return
    }
  }
  if (!form.service_id) {
    createDialog.value.error = 'Pick a service'
    return
  }
  if (!form.description.trim()) {
    createDialog.value.error = 'Description is required'
    return
  }

  createDialog.value.loading = true
  createDialog.value.error = ''
  try {
    const body = new FormData()
    if (isResident) {
      body.append('resident_id', form.resident_id)
    } else {
      body.append('walk_in_name', `${form.walk_in_first_name.trim()} ${form.walk_in_last_name.trim()}`)
      body.append('walk_in_contact_number', form.walk_in_contact_number.trim())
    }
    body.append('service_id', form.service_id)
    body.append('description', form.description.trim())

    const res = await fetch(`${API_BASE}/admin/service-requests`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${getToken()}` },
      body,
    })
    if (!res.ok) {
      const errData = await res.json().catch(() => ({}))
      const firstError = errData.errors ? Object.values(errData.errors)[0]?.[0] : null
      throw new Error(firstError || errData.message || 'Failed to file the request')
    }
    createDialog.value.open = false
    await fetchRequests()
  } catch (error) {
    createDialog.value.error = error.message
  } finally {
    createDialog.value.loading = false
  }
}

watch(() => filters.status, () => { page.value = 1 })
watch(search, () => { page.value = 1 })
watch(() => filters.statusPick, () => { page.value = 1 })
watch(() => filters.barangay, () => { page.value = 1 })
watch(() => filters.unit, () => { page.value = 1 })

onMounted(async () => {
  await fetchData()
  // Dashboard rows deep-link here with ?request=<request_id>.
  const target = requests.value.find((r) => r.request_id === Number(route.query.request))
  if (target) selectRequest(target)
})
onMounted(fetchVehicleTypes)
onUnmounted(releaseAttachments)
onUnmounted(() => listAbortController.abort())
</script>

<style scoped src="@/styles/request-table.css"></style>

<style scoped>
.dashboard-bg {
  background-color: rgb(var(--v-theme-background));
}
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
.min-width-0 { min-width: 0; }

.detail-modal-card {
  max-height: 90vh;
}

.soft-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  box-shadow: 0 1px 2px rgba(var(--v-theme-on-surface), 0.04), 0 4px 14px rgba(var(--v-theme-on-surface), 0.08);
}

.subtle-surface {
  background-color: rgba(var(--v-theme-on-surface), 0.05);
}

.vehicle-option {
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.vehicle-option:last-child { border-bottom: none; }
.vehicle-option:hover { background-color: rgba(var(--v-theme-primary), 0.06); }

.cursor-pointer {
  cursor: pointer;
}

.description-line + .description-line {
  margin-top: 3px;
}

.attachment-tile {
  position: relative;
  display: block;
  padding: 0;
  overflow: hidden;
  cursor: pointer;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  background-color: rgba(var(--v-theme-on-surface), 0.05);
}

.attachment-tile:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

.attachment-badge {
  position: absolute;
  right: 6px;
  bottom: 6px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 6px;
  color: #fff;
  background-color: rgba(0, 0, 0, 0.6);
  transition: opacity var(--motion-fast) var(--ease-out);
}

.attachment-tile:hover .attachment-badge,
.attachment-tile:focus-visible .attachment-badge {
  opacity: 0;
}

.attachment-scrim {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 0.8rem;
  font-weight: 700;
  color: #fff;
  background-color: rgba(0, 0, 0, 0.55);
  opacity: 0;
  transition: opacity var(--motion-fast) var(--ease-out);
}

.attachment-tile:hover .attachment-scrim,
.attachment-tile:focus-visible .attachment-scrim {
  opacity: 1;
}

@media (prefers-reduced-motion: reduce) {
  .attachment-scrim,
  .attachment-badge { transition: none; }
}

.attachment-error {
  max-width: 320px;
}

.attachment-tile--file {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 180px;
  height: 140px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  text-decoration: none;
  color: inherit;
}
.attachments-row {
  width: fit-content;
  max-width: 100%;
}

.request-row {
  cursor: pointer;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06);
  border-left: 3px solid transparent;
  transition: background-color var(--motion-fast) var(--ease-out);
}
.request-row:hover {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}
.request-row.row-selected {
  background-color: rgba(var(--v-theme-primary), 0.08);
}
.request-row.row-pending { border-left-color: rgb(var(--v-theme-warning)); }
.request-row.row-responding { border-left-color: rgb(var(--v-theme-info)); }
.request-row.row-resolved { border-left-color: rgb(var(--v-theme-success)); }
.request-row.row-disapproved,
.request-row.row-cancelled { border-left-color: rgb(var(--v-theme-error)); }

.request-row:focus-visible {
  outline: none;
  box-shadow: inset 0 0 0 2px rgb(var(--v-theme-primary));
  background-color: rgba(var(--v-theme-primary), 0.06);
}

.row-date {
  flex: 0 0 auto;
  font-variant-numeric: tabular-nums;
  opacity: 0.85;
}
</style>
