// The last customize choice per list, so a desk that always prints the same
// six columns picks them once. Per browser only: nothing here is worth a server.
import { defaultColumns, EXPORT_TYPES } from '@/composables/requestFields'

const keyFor = (type) => `serbis.export.${type}`

export const defaultPrefs = (type) => ({
  columns: defaultColumns(type),
  format: 'print', // print | csv | xlsx
  layout: 'table', // table | detail (print only)
  photos: false,
  sort: 'newest',
})

export function loadPrefs(type) {
  const base = defaultPrefs(type)

  try {
    const saved = JSON.parse(localStorage.getItem(keyFor(type)) || 'null')
    if (!saved || typeof saved !== 'object') { return base }
    const known = new Set(EXPORT_TYPES[type].fields.map((x) => x.key))
    const columns = Array.isArray(saved.columns) ? saved.columns.filter((k) => known.has(k)) : []

    return { ...base, ...saved, columns: columns.length ? columns : base.columns }
  } catch {
    return base // storage blocked or a corrupt value: fall back to the defaults
  }
}

export function savePrefs(type, prefs) {
  try {
    localStorage.setItem(keyFor(type), JSON.stringify(prefs))
  } catch {
    // Remembering is a convenience; a full or blocked store must not stop an export.
  }
}
