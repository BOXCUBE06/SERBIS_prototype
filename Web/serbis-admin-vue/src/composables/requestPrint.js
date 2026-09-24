// Print layouts. The browser's print dialog is the PDF writer ("Save as PDF"),
// so this only builds a self-contained document and hands it to a hidden
// iframe: nothing global is styled and no library is involved.
//
//   detail: one record per page, with every timestamp and actor and, on
//           request, the attached photos.
//   table:  the chosen columns, one row per record, landscape.
import { API_BASE } from '@/config/api'
import { getToken } from '@/composables/authToken'
import { columnsFor, EXPORT_TYPES, manilaDateTime } from '@/composables/requestFields'
import { adminName, loadCurrentAdmin } from '@/composables/useCurrentAdmin'

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ESCAPES[c])
const show = (v) => (v === '' || v == null ? '—' : esc(v))

const CSS = `
*{box-sizing:border-box}
body{font:12px/1.45 Arial,Helvetica,sans-serif;color:#111;margin:0}
.hdr{text-align:center;border-bottom:2px solid #111;padding-bottom:6px;margin-bottom:12px}
.org{font-size:19px;font-weight:700;letter-spacing:.04em}
.sub{font-size:11px;color:#444}
.page{break-after:page}
.page:last-child{break-after:auto}
h1{font-size:15px;margin:0 0 2px}
h2{font-size:12px;text-transform:uppercase;letter-spacing:.05em;margin:14px 0 4px;color:#333}
.badge{display:inline-block;border:1px solid #111;border-radius:3px;padding:1px 8px;font-weight:700;font-size:11px;margin:2px 0 8px}
.meta{color:#444;font-size:11px;margin-bottom:8px}
table{width:100%;border-collapse:collapse}
.kv th,.kv td{border-bottom:1px solid #ccc;padding:4px 6px;vertical-align:top;text-align:left}
.kv th{width:32%;font-weight:600;color:#333}
td{white-space:pre-wrap}
.list{font-size:10.5px}
.list th,.list td{border:1px solid #999;padding:3px 5px;vertical-align:top;text-align:left}
.list th{background:#eee}
thead{display:table-header-group}
tr{break-inside:avoid}
.photos{display:flex;flex-wrap:wrap;gap:10px}
figure{margin:0;break-inside:avoid}
figure img{max-width:250px;max-height:190px;border:1px solid #999}
figcaption{font-size:10px;color:#444}
.sigs{display:flex;gap:48px;margin-top:38px;break-inside:avoid}
.sig{flex:1;text-align:center;font-size:11px}
.line{border-top:1px solid #111;margin-bottom:4px;height:1px}
.foot{margin-top:14px;font-size:10px;color:#666}
`

const header = '<div class="hdr"><div class="org">MDRRMO Echague</div><div class="sub">Municipal Disaster Risk Reduction and Management Office &middot; Echague, Isabela</div></div>'

// Approved by is dropped, not left blank, for records nobody approves.
const signatures = (approverName) => `<div class="sigs">
<div class="sig"><div class="line"></div>Prepared by<br><strong>${esc(adminName.value)}</strong></div>
${approverName === false ? '' : `<div class="sig"><div class="line"></div>Approved by<br><strong>${esc(approverName)}</strong></div>`}
</div>`

const printedLine = () => `Printed ${esc(manilaDateTime(new Date()))} by ${esc(adminName.value || 'admin')}`

// Photos need the auth header, so they are fetched and embedded as blob URLs.
// One request per photo per record: fine for a desk print, slow for hundreds.
async function fetchPhoto({ label, path }) {
  try {
    const res = await fetch(`${API_BASE}${path}`, { headers: { Authorization: `Bearer ${getToken()}` } })
    if (!res.ok) { throw new Error('unavailable') }
    const blob = await res.blob()

    return blob.type.startsWith('image/')
      ? { label, url: URL.createObjectURL(blob) }
      : { label, note: 'PDF attachment; open it in the panel.' }
  } catch {
    return { label, note: 'Could not be loaded.' }
  }
}

const kv = (rows) => `<table class="kv">${rows.map(([label, value]) => `<tr><th>${esc(label)}</th><td>${show(value)}</td></tr>`).join('')}</table>`

async function detailPages(type, cols, rows, withPhotos, urls) {
  const t = EXPORT_TYPES[type]
  const [idField, statusField] = t.fields
  const timeline = t.fields.filter((x) => x.group === 'time' || x.group === 'actor')
  const details = cols.filter((x) => x !== idField && x !== statusField && !timeline.includes(x))
  const pages = []

  for (const row of rows) {
    const photos = withPhotos ? await Promise.all(t.photos(row).filter(Boolean).map((p) => fetchPhoto(p))) : []
    photos.forEach((p) => p.url && urls.push(p.url))
    const figures = photos.map((p) => (p.url
      ? `<figure><img src="${p.url}" alt="${esc(p.label)}"><figcaption>${esc(p.label)}</figcaption></figure>`
      : `<figure><figcaption>${esc(p.label)}: ${esc(p.note)}</figcaption></figure>`)).join('')

    pages.push(`<section class="page">${header}
<h1>${esc(idField.label)} ${esc(idField.get(row))}</h1>
<div class="badge">${esc(statusField.get(row))}</div>
${details.length ? `<h2>Details</h2>${kv(details.map((x) => [x.label, x.get(row)]))}` : ''}
<h2>Timeline and actors</h2>${kv(timeline.map((x) => [x.label, x.get(row)]))}
${figures ? `<h2>Photos</h2><div class="photos">${figures}</div>` : ''}
${signatures(t.approver === false ? false : t.approver(row))}
<div class="foot">${printedLine()}</div></section>`)
  }

  return pages.join('')
}

function tablePage(type, cols, rows) {
  const t = EXPORT_TYPES[type]

  return `<section class="page">${header}
<h1>${esc(t.title)}</h1>
<div class="meta">${rows.length} record${rows.length === 1 ? '' : 's'} &middot; ${printedLine()}</div>
<table class="list"><thead><tr>${cols.map((x) => `<th>${esc(x.label)}</th>`).join('')}</tr></thead>
<tbody>${rows.map((r) => `<tr>${cols.map((x) => `<td>${show(x.get(r))}</td>`).join('')}</tr>`).join('')}</tbody></table>
${signatures(t.approver === false ? false : '')}</section>`
}

async function printHtml(html, urls) {
  const frame = document.createElement('iframe')
  frame.setAttribute('aria-hidden', 'true')
  frame.style.cssText = 'position:fixed;width:0;height:0;border:0;visibility:hidden'
  document.body.append(frame)

  await new Promise((resolve) => { frame.addEventListener('load', resolve, { once: true }); frame.srcdoc = html })
  // Blank pages are what an early print() produces: wait for the images.
  await Promise.all([...frame.contentDocument.images].map((img) => (img.complete ? null : new Promise((r) => { img.addEventListener('load', r, { once: true }); img.addEventListener('error', r, { once: true }) }))))

  const done = () => { frame.remove(); urls.forEach((u) => URL.revokeObjectURL(u)) }
  frame.contentWindow.addEventListener('afterprint', done, { once: true })
  frame.contentWindow.focus()
  frame.contentWindow.print()
}

/**
 * Prints `rows` (already filtered and sorted) with the chosen columns.
 * `layout` is 'detail' (one per page) or 'table'; `photos` only applies to detail.
 */
export async function printRows(type, rows, { columns, layout = 'table', photos = false }) {
  await loadCurrentAdmin()
  const urls = []
  const cols = columnsFor(type, columns)
  const body = layout === 'detail' ? await detailPages(type, cols, rows, photos, urls) : tablePage(type, cols, rows)
  const size = layout === 'detail' ? 'A4 portrait' : 'A4 landscape'

  await printHtml(`<!doctype html><html><head><meta charset="utf-8"><title>SERBIS ${esc(EXPORT_TYPES[type].title)}</title>
<style>@page{size:${size};margin:14mm}${CSS}</style></head><body>${body}</body></html>`, urls)
}
