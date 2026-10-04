<!--
  EditDialog.vue

  The one Add/Edit dialog for Vehicles, Responders, Resource Management and
  Accounts: title and close button, an optional avatar with "Change photo", the
  fields from a config list, an error banner, Cancel and Save. Values are the
  ResDialog canvas board's.

  Each field is `{ key, label, required, placeholder, items, itemTitle,
  itemValue, type, min, max, half, third, hint, rules, autocomplete, options,
  slot, note }`. `half` and `third` put two or three fields on a row; `hint`
  is the muted line under the control; `note` swaps the input for a muted line
  (Equipment's "Available starts equal to total"). `type: 'password'` adds the
  show/hide eye, `options` ([{ title, value }]) makes an inline radio group, and
  `slot` leaves the control to the caller's `field-<key>` slot. `rules`
  replaces the built-in required rule (the label still gets its asterisk).
  Values live in the caller's `form`; `fieldErrors` is the server's per-field
  422 messages. The caller's save still runs its own checks: `validate()` and
  `resetValidation()` are exposed for that.

  Slots: `avatar-actions` replaces the Change photo input, `before-<key>`
  sits above that field's control.
-->
<!-- eslint-disable vue/no-mutating-props -- the form is the caller's object by design: fields edit it in place -->
<template>
  <v-dialog :model-value="dialogShown" @update:model-value="$emit('update:modelValue', $event)" :max-width="width" persistent>
    <v-card rounded="xl" class="edit-dialog">
      <div class="d-flex justify-space-between align-center">
        <h2 class="edit-dialog__title">{{ title }}</h2>
        <v-btn icon="mdi-close" variant="flat" rounded="circle" size="small" class="edit-dialog__close" aria-label="Close" @click="$emit('update:modelValue', false)"></v-btn>
      </div>

      <p v-if="note" class="edit-dialog__avatar-note edit-dialog__note-line">{{ note }}</p>

      <v-alert v-if="error" type="error" variant="tonal" density="compact" rounded="lg" role="alert">{{ error }}</v-alert>

      <template v-if="avatar !== undefined">
        <div class="d-flex align-center ga-3">
          <v-avatar size="52" color="primary" variant="tonal">
            <v-img v-if="photo" :src="photo" alt="" cover></v-img>
            <span v-else class="text-body-2 font-weight-bold text-primary-strong">{{ avatar }}</span>
          </v-avatar>
          <slot name="avatar-actions">
            <v-file-input
              v-model="picked"
              accept="image/png,image/jpeg"
              :label="avatarAction"
              density="compact"
              variant="outlined"
              rounded="lg"
              hide-details
              prepend-icon=""
              prepend-inner-icon="mdi-camera-outline"
              :loading="photoLoading"
              @update:model-value="onPhoto"
            ></v-file-input>
          </slot>
        </div>
        <p v-if="avatarNote" class="edit-dialog__avatar-note">{{ avatarNote }}</p>
      </template>

      <v-form ref="formRef" class="edit-dialog__fields" @keydown.enter="onEnter">
        <template v-for="f in fields" :key="f.key">
          <div v-if="f.note" class="edit-dialog__note" :class="{ 'edit-dialog__half': f.half }">
            <v-icon size="16" class="mr-1">mdi-sync</v-icon>{{ f.note }}
          </div>
          <p v-else-if="f.text" class="edit-dialog__text" :class="{ 'edit-dialog__text--strong': f.strong }">{{ f.text }}</p>
          <div v-else-if="f.readonly" class="edit-dialog__readonly">
            <span class="edit-dialog__readonly-label">{{ f.label }}</span><b>{{ f.display ? f.display(form[f.key]) : form[f.key] }}</b>
          </div>
          <!-- Repeatable rows of { label, number }: the carrier/line and the number. -->
          <div v-else-if="f.numbers" class="edit-dialog__field edit-dialog__numbers">
            <div class="d-flex justify-space-between align-center">
              <span class="edit-dialog__numbers-title">{{ f.label }}</span>
              <button type="button" class="edit-dialog__add" :disabled="form[f.key].length >= (f.maxRows ?? 99)" @click="form[f.key].push({ label: '', number: '' })">
                <v-icon size="14">mdi-plus</v-icon>Add number
              </button>
            </div>
            <div v-for="(n, i) in form[f.key]" :key="i" class="edit-dialog__number-row">
              <v-text-field
                v-model="n.label"
                placeholder="Carrier / line"
                aria-label="Carrier or line"
                :error-messages="fieldErrors[`${f.key}.${i}.label`]"
                variant="outlined" density="compact" rounded="lg" hide-details="auto"
                class="edit-dialog__carrier"
              ></v-text-field>
              <v-text-field
                :model-value="n.number"
                @input="(e: Event) => { const el = e.target as HTMLInputElement; n.number = el.value = f.sanitize ? f.sanitize(el.value) : el.value }"
                placeholder="Number *"
                aria-label="Number"
                :inputmode="f.inputmode"
                :maxlength="f.maxlength"
                :rules="rulesFor(f)"
                :error-messages="fieldErrors[`${f.key}.${i}.number`]"
                variant="outlined" density="compact" rounded="lg" hide-details="auto"
              ></v-text-field>
              <!-- At least one number: the first row cannot be removed. -->
              <v-btn v-if="Number(i) > 0" icon="mdi-close" variant="text" size="small" class="edit-dialog__remove" aria-label="Remove number" @click="form[f.key].splice(i, 1)"></v-btn>
              <span v-else class="edit-dialog__remove"></span>
            </div>
          </div>
          <component
            :is="f.options || f.slot ? 'div' : 'label'"
            v-else
            class="edit-dialog__field"
            :class="{ 'edit-dialog__half': f.half, 'edit-dialog__third': f.third }"
          >
            <span v-if="f.label" class="edit-dialog__label">{{ f.label }}{{ f.required ? ' *' : '' }}</span>
            <slot :name="`before-${f.key}`" />
            <slot v-if="f.slot" :name="`field-${f.key}`" :field="f" />
            <v-radio-group
              v-else-if="f.options"
              :model-value="form[f.key]"
              @update:model-value="set(f, $event)"
              :aria-label="f.label"
              inline hide-details color="primary"
              class="edit-dialog__radios"
            >
              <v-radio v-for="o in f.options" :key="o.value" :label="o.title" :value="o.value"></v-radio>
            </v-radio-group>
            <v-select
              v-else-if="f.items"
              :model-value="form[f.key]"
              @update:model-value="set(f, $event)"
              :items="f.items"
              :item-title="f.itemTitle"
              :item-value="f.itemValue"
              :rules="rulesFor(f)"
              :error-messages="fieldErrors[f.key]"
              :aria-label="f.label"
              variant="outlined" density="compact" rounded="lg" hide-details="auto"
            ></v-select>
            <v-textarea
              v-else-if="f.type === 'textarea'"
              :model-value="form[f.key]"
              @update:model-value="set(f, $event)"
              :placeholder="f.placeholder"
              :rules="rulesFor(f)"
              :error-messages="fieldErrors[f.key]"
              :aria-label="f.label"
              rows="3" auto-grow
              variant="outlined" density="compact" rounded="lg" hide-details="auto"
            ></v-textarea>
            <v-text-field
              v-else
              :model-value="form[f.key]"
              @update:model-value="set(f, $event)"
              :type="f.type === 'password' ? (shown[f.key] ? 'text' : 'password') : f.type"
              :append-inner-icon="f.type === 'password' ? (shown[f.key] ? 'mdi-eye-off' : 'mdi-eye') : undefined"
              :min="f.min"
              :max="f.max"
              :placeholder="f.placeholder"
              :autocomplete="f.autocomplete"
              :maxlength="f.maxlength"
              :inputmode="f.inputmode"
              :rules="rulesFor(f)"
              :error-messages="fieldErrors[f.key]"
              :aria-label="f.label"
              variant="outlined" density="compact" rounded="lg" hide-details="auto"
              @click:append-inner="shown[f.key] = !shown[f.key]"
            ></v-text-field>
            <span v-if="f.hint" class="edit-dialog__hint">{{ f.hint }}</span>
          </component>
        </template>
      </v-form>

      <div class="d-flex justify-end ga-3">
        <v-btn variant="flat" rounded="lg" height="40" class="edit-dialog__btn edit-dialog__cancel" :disabled="loading" @click="$emit('update:modelValue', false)">Cancel</v-btn>
        <v-btn
          color="primary" variant="flat" rounded="lg" height="40"
          class="edit-dialog__btn edit-dialog__confirm" :class="{ 'is-busy': phase !== 'idle' }"
          :disabled="phase !== 'idle'" aria-live="polite"
          @click="$emit('save')"
        >
          <template v-if="phase === 'saving'"><span class="btn-spin" aria-hidden="true"></span>{{ busyLabel }}</template>
          <template v-else-if="phase === 'saved'"><v-icon size="16" aria-hidden="true">mdi-check</v-icon>{{ doneLabel }}</template>
          <template v-else>{{ confirmLabel }}</template>
        </v-btn>
      </div>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useSaveFeedback } from '@/composables/useSaveFeedback'

interface Field {
  key: string
  label: string
  required?: boolean
  placeholder?: string
  items?: unknown[]
  itemTitle?: string | ((item: any) => string)
  itemValue?: string
  type?: string
  min?: number
  max?: number
  half?: boolean
  third?: boolean
  hint?: string
  rules?: ((value: any) => true | string)[]
  autocomplete?: string
  maxlength?: number
  inputmode?: string
  options?: { title: string; value: string }[]
  /** A fixed "label  value" row; `display` turns the stored value into its text. */
  readonly?: boolean
  display?: (value: any) => string
  /** A plain muted line instead of a control (`strong`: in the body colour). */
  text?: string
  strong?: boolean
  /** Repeatable { label, number } rows (`form[key]` is the array). */
  numbers?: boolean
  maxRows?: number
  sanitize?: (value: string) => string
  slot?: boolean
  note?: string
}

const props = withDefaults(defineProps<{
  modelValue: boolean
  title: string
  confirmLabel: string
  /** The button's words while saving and once saved. */
  busyLabel?: string
  doneLabel?: string
  fields: Field[]
  form: Record<string, any>
  fieldErrors?: Record<string, any>
  error?: string
  loading?: boolean
  width?: number
  /** Initials; setting it (even '') shows the avatar row with Change photo. */
  avatar?: string
  photo?: string | null
  photoLoading?: boolean
  avatarAction?: string
  /** The muted line under the avatar row. */
  avatarNote?: string
  /** A muted line under the title. */
  note?: string
}>(), { busyLabel: 'Saving', doneLabel: 'Saved', fieldErrors: () => ({}), error: '', loading: false, width: 480, photo: null, photoLoading: false, avatarAction: 'Change photo', avatarNote: '', note: '' })

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'save'): void
  (e: 'photo', file: File): void
}>()

// The save button's spinner / "Saved" looks; `shown` holds the dialog open for the
// "Saved" moment after a successful save (see the composable).
const { shown: dialogShown, phase } = useSaveFeedback(() => props.modelValue, () => props.loading, () => props.error)

const formRef = ref<any>(null)
const picked = ref(null)
const shown = reactive<Record<string, boolean>>({})

const rulesFor = (f: Field) =>
  f.rules ?? (f.required ? [(v: unknown) => (v !== null && v !== undefined && String(v).trim() !== '') || `${f.label} is required.`] : [])

// Number fields keep numbers in the form (what `v-model.number` did before).
const set = (f: Field, value: any) => {
  const next = f.sanitize && typeof value === 'string' ? f.sanitize(value) : value
  // eslint-disable-next-line vue/no-mutating-props -- see the note at the top of the file
  props.form[f.key] = f.type === 'number' && next !== '' && next !== null ? Number(next) : next
}

// Enter saves from an editable text input only. A select's input is read-only,
// and radios, the photo button and a busy dialog keep their own Enter.
const TEXT_INPUTS = new Set(['text', 'tel', 'password', 'number', 'email', 'search', 'url'])
const onEnter = (event: KeyboardEvent) => {
  const el = event.target as HTMLInputElement
  if (el.tagName !== 'INPUT' || el.readOnly || !TEXT_INPUTS.has(el.type) || props.loading || phase.value !== 'idle') return
  event.preventDefault()
  emit('save')
}

// The upload starts on pick; the input is cleared so the same file can be picked again.
const onPhoto = (file: any) => {
  if (!file) return
  emit('photo', file)
  picked.value = null
}

defineExpose({
  validate: () => formRef.value.validate(),
  resetValidation: () => formRef.value?.resetValidation(),
})
</script>

<style scoped>
.edit-dialog {
  display: flex;
  flex-direction: column;
  gap: 20px;
  padding: 28px 32px 32px;
  box-shadow: 0 28px 64px rgba(2, 20, 16, 0.32) !important;
}
.edit-dialog__title { margin: 0; font-size: 1.17rem; line-height: 28px; font-weight: 600; }
.edit-dialog__close {
  width: 36px;
  height: 36px;
  background: rgba(var(--v-theme-on-surface), 0.06);
  color: rgb(var(--v-theme-on-surface));
}
.edit-dialog__avatar-note {
  margin: -8px 0 0;
  font-size: 12px;
  line-height: 16px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.edit-dialog__note-line { font-size: 14px; line-height: 20px; }
.edit-dialog__text { width: 100%; margin: 0; font-size: 14px; line-height: 20px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.edit-dialog__text--strong { color: rgb(var(--v-theme-on-surface)); }
.edit-dialog__readonly { display: flex; gap: 16px; width: 100%; font-size: 14px; line-height: 20px; }
.edit-dialog__readonly-label { flex: none; width: 88px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.edit-dialog__numbers { display: flex; flex-direction: column; gap: 12px; }
.edit-dialog__numbers-title { font-size: 14px; line-height: 20px; font-weight: 700; }
.edit-dialog__add {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 32px;
  padding: 0 10px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: rgb(var(--v-theme-primary-strong));
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
}
.edit-dialog__add:disabled { opacity: 0.4; cursor: default; }
.edit-dialog__number-row { display: flex; gap: 12px; align-items: flex-start; }
.edit-dialog__number-row > :nth-child(2) { flex: 1; min-width: 0; }
.edit-dialog__carrier { flex: 0 0 34%; }
.edit-dialog__remove { flex: none; width: 40px; height: 40px; border-radius: 10px; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.edit-dialog__fields :deep(textarea) { font-size: 14px; line-height: 20px; }
.edit-dialog__fields { display: flex; flex-wrap: wrap; gap: 16px; }
.edit-dialog__field { display: block; width: 100%; }
.edit-dialog__half { width: calc(50% - 8px); }
.edit-dialog__third { width: calc(33.333% - 10.67px); }
.edit-dialog__fields :deep(.v-field) { border-radius: 10px; font-size: 14px; }
.edit-dialog__label {
  display: block;
  margin-bottom: 6px;
  font-size: 12px;
  line-height: 16px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.edit-dialog__hint {
  display: block;
  margin-top: 6px;
  font-size: 12px;
  line-height: 16px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
/* The radio labels are body text, not the field label's uppercase. */
.edit-dialog__radios { min-height: 40px; }
.edit-dialog__radios :deep(.v-selection-control-group) { gap: 24px; }
.edit-dialog__radios :deep(.v-label) { font-size: 14px; font-weight: 600; opacity: 1; }
.edit-dialog__note {
  display: flex;
  align-items: center;
  align-self: flex-end;
  min-height: 40px;
  padding: 0 12px;
  border-radius: 10px;
  font-size: 0.78rem;
  background: rgba(var(--v-theme-on-surface), 0.05);
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.edit-dialog__btn {
  border-radius: 12px !important;
  font-size: 14px;
  font-weight: 700;
  letter-spacing: 0;
  text-transform: none;
}
.edit-dialog__cancel {
  padding: 0 16px;
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.14);
  color: rgb(var(--v-theme-primary-strong));
}
/* Saving / Saved keep the button's own green (disabled would grey it). */
.edit-dialog__confirm.is-busy { display: inline-flex; gap: 8px; min-width: 104px; opacity: 1 !important; background: rgb(var(--v-theme-primary)) !important; color: #fff !important; }
.edit-dialog__confirm {
  padding: 0 20px;
  box-shadow: 0 8px 16px -4px rgba(var(--v-theme-primary), 0.28);
}
@media (max-width: 599px) {
  .edit-dialog { padding: 20px; }
  .edit-dialog__half,
  .edit-dialog__third { width: 100%; }
}
</style>
