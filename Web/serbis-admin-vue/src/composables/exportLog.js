// Tells the server a print or export happened, so it lands in the Activity Log
// with who did it. The file itself never leaves the browser; only the type,
// format and count are sent (plus ids for a small selection).
import { API_BASE } from '@/config/api'
import { authHeaders } from '@/composables/adminUi'

const MAX_IDS = 200 // the server refuses more; a bigger run is logged as a count

/** Fire and forget: a failed log must not undo a print the user already has. */
export async function logExport(type, { action, format, scope, count, ids, from, to }) {
  try {
    const res = await fetch(`${API_BASE}/admin/export-logs/${type}`, {
      method: 'POST',
      headers: authHeaders(),
      body: JSON.stringify({
        action, format, scope, count,
        ids: ids.length <= MAX_IDS ? ids : undefined,
        from: from || undefined,
        to: to || undefined,
      }),
    })
    if (!res.ok) { console.warn('Export was not logged:', res.status) }
  } catch (error) {
    console.warn('Export was not logged:', error)
  }
}
