<!--
  Replaces a native <input type="date"> / <input type="datetime-local">
  with Vuetify's own VDatePicker (+ VTimePicker for the datetime case),
  behind a readonly text field so the browser's own picker chrome never
  shows. Opens as a dialog in the middle of the screen.

  The one rule that matters more than anything else here: modelValue is
  the EXACT same string shape the native input produced — 'YYYY-MM-DD' for
  type="date", 'YYYY-MM-DDTHH:mm' for type="datetime-local" — because every
  caller's own submit function still does its own '.replace("T", " ") +
  ":00"' conversion downstream, and the backend still re-parses that as
  Asia/Manila. This component only swaps out how the string gets typed in,
  never what the string looks like once it's in the form.
-->
<template>
  <v-dialog
    v-model="menuOpen"
    :max-width="isDateTime ? 720 : 380"
    width="auto"
  >
    <template v-slot:activator="{ props: menuProps }">
      <v-text-field
        v-bind="{ ...$attrs, ...menuProps }"
        :model-value="displayValue"
        readonly
        :append-inner-icon="type === 'datetime-local' ? 'mdi-calendar-clock' : 'mdi-calendar'"
      ></v-text-field>
    </template>

    <v-card class="pa-3 dtp-card" rounded="lg">
      <div class="dtp-title">{{ isDateTime ? 'Pick a date and time' : 'Pick a date' }}</div>
      <div class="dtp-panes">
        <v-date-picker
          v-model="pickerDate"
          :min="dateMin"
          :max="dateMax"
          hide-header
          show-adjacent-months
          class="dtp-pane"
        ></v-date-picker>

        <!-- ampm, not 24hr: a trip checkpoint typed as "01:00" with no
             meridiem is ambiguous to whoever reads the log back. The bound
             value is unaffected — VTimePicker's genValue() always emits
             24-hour 'HH:mm' whichever format is displayed. -->
        <v-time-picker
          v-if="type === 'datetime-local'"
          v-model="pickerTime"
          format="ampm"
          class="dtp-pane"
        ></v-time-picker>
      </div>

      <v-alert v-if="rangeError" type="error" variant="tonal" density="compact" class="mx-2 mb-2">
        {{ rangeError }}
      </v-alert>

      <v-card-actions>
        <!-- Not in the original native-input parity spec, but a native
             date/datetime input was always clearable by backspacing it —
             a read-only picker has no other way to reach blank, and the
             trip log's four checkpoints are genuinely nullable. -->
        <v-btn variant="outlined" color="primary" size="small" class="text-none" @click="clear">Clear</v-btn>
        <v-spacer></v-spacer>
        <v-btn variant="outlined" color="primary" size="small" class="text-none" @click="cancel">Cancel</v-btn>
        <v-btn color="primary" variant="flat" size="small" class="text-none" @click="confirm">OK</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { fmtDate, fmtDateTime } from '@/composables/adminUi'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  modelValue: { type: String, default: '' },
  // Named after the native attribute it replaces, not Vuetify's own
  // vocabulary, so a call site reads the same as it did before.
  type: { type: String, default: 'date' },
  // Same shape as modelValue — 'YYYY-MM-DD' or 'YYYY-MM-DDTHH:mm'. Only
  // gates by calendar day; a datetime field's own minute-level floor (the
  // create dialog's "1 hour from now") is still enforced server-side,
  // untouched by this component.
  min: { type: String, default: '' },
  // Same shape and same day-only granularity as min, mirrored the same way.
  max: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

const pad = (n) => String(n).padStart(2, '0')
const toYMD = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`

// 'YYYY-MM-DD' must NOT go through `new Date(str)` — a bare date-only ISO
// string parses as UTC midnight, which reads back as the previous local day
// west of Greenwich. Built from the parts instead, same as this codebase's
// other date helpers (toDateInput, toInputValue) already do.
function parseValue (value, wantsTime) {
  if (!value) return null
  if (wantsTime) {
    const d = new Date(value)
    return Number.isNaN(d.getTime()) ? null : d
  }
  const [y, mo, da] = value.split('-').map(Number)
  if (!y || !mo || !da) return null
  return new Date(y, mo - 1, da)
}

const isDateTime = computed(() => props.type === 'datetime-local')

const displayValue = computed(() => {
  if (!props.modelValue) return ''
  return isDateTime.value ? fmtDateTime(props.modelValue) : fmtDate(props.modelValue)
})

// The day-only floor VDatePicker enforces. min itself may carry a time
// component (minScheduleValue does); only the calendar day matters here.
const dateMin = computed(() => parseValue(props.min, false) || (isDateTime.value ? parseValue(props.min, true) : null))
// The day-only ceiling, mirroring dateMin.
const dateMax = computed(() => parseValue(props.max, false) || (isDateTime.value ? parseValue(props.max, true) : null))

const menuOpen = ref(false)
const pickerDate = ref(null)
const pickerTime = ref('00:00')
const rangeError = ref('')

// Freshly seeded every time the menu opens, from whatever the field
// currently holds — not a one-time init — so reopening after the value
// changed elsewhere (e.g. the reschedule dialog's own openReschedule)
// never shows stale picks.
watch(menuOpen, (open) => {
  if (!open) return

  rangeError.value = ''
  const existing = parseValue(props.modelValue, isDateTime.value)
  pickerDate.value = existing || new Date()
  pickerTime.value = props.modelValue && props.modelValue.length >= 16
    ? props.modelValue.slice(11, 16)
    : `${pad(new Date().getHours())}:${pad(new Date().getMinutes())}`
})

function confirm () {
  if (!pickerDate.value) {
    menuOpen.value = false
    return
  }

  if (props.min) {
    const minInstant = parseValue(props.min, isDateTime.value)
    const candidate = isDateTime.value
      ? (() => {
          const [h, mi] = pickerTime.value.split(':').map(Number)
          const d = new Date(pickerDate.value)
          d.setHours(h, mi, 0, 0)
          return d
        })()
      : pickerDate.value

    if (minInstant && candidate < minInstant) {
      rangeError.value = isDateTime.value
        ? 'That time is earlier than the earliest this can be scheduled.'
        : 'That date is earlier than the earliest allowed.'
      return
    }
  }

  if (props.max) {
    const maxInstant = parseValue(props.max, isDateTime.value)
    const candidate = isDateTime.value
      ? (() => {
          const [h, mi] = pickerTime.value.split(':').map(Number)
          const d = new Date(pickerDate.value)
          d.setHours(h, mi, 0, 0)
          return d
        })()
      : pickerDate.value

    if (maxInstant && candidate > maxInstant) {
      rangeError.value = isDateTime.value
        ? 'That time is later than the latest this can be scheduled.'
        : 'That date is later than the latest allowed.'
      return
    }
  }

  const value = isDateTime.value ? `${toYMD(pickerDate.value)}T${pickerTime.value}` : toYMD(pickerDate.value)
  emit('update:modelValue', value)
  menuOpen.value = false
}

function cancel () {
  menuOpen.value = false
}

function clear () {
  emit('update:modelValue', '')
  menuOpen.value = false
}
</script>

<style scoped>
/* Centred in a dialog so it never opens off-screen or over the form that
   launched it. Stacked, the date + time pair ran ~700px tall; side by side it
   fits. The card is capped at the viewport and scrolls the pickers internally,
   so Clear/Cancel/OK stay reachable. */
.dtp-card {
  display: flex;
  flex-direction: column;
  max-height: calc(100vh - 24px);
  max-height: calc(100dvh - 24px);
}

.dtp-title {
  padding: 4px 8px 8px;
  font-size: 17px;
  font-weight: 700;
}

.dtp-panes {
  display: flex;
  flex-direction: row;
  align-items: flex-start;
  justify-content: center;
  gap: 8px;
  overflow-y: auto;
  /* min-height:0 or the flex item refuses to shrink below its content and
     the max-height above does nothing. */
  min-height: 0;
  flex: 1 1 auto;
}

.dtp-pane {
  flex: 0 0 auto;
}

@media (max-width: 900px) {
  .dtp-panes {
    flex-direction: column;
  }
}
</style>
