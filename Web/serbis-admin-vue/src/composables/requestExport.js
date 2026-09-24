// CSV and XLSX export, built client-side from the same field catalog as the
// print layouts: human column headers, dates already in Asia/Manila, and no
// internal ids unless the column was ticked.
import { columnsFor, exportFilename } from '@/composables/requestFields'

// A leading = + - @ makes Excel run the cell as a formula, and these cells hold
// text people typed (descriptions, remarks); a ' keeps it text.
const csvCell = (value) => {
  const text = String(value ?? '')
  const safe = /^[=+\-@\t\r]/.test(text) && Number.isNaN(Number(text)) ? `'${text}` : text

  return `"${safe.replace(/"/g, '""')}"`
}

export function buildCsv(head, body) {
  // BOM so Excel reads the UTF-8 (Filipino names, accents) instead of guessing.
  return '\uFEFF' + [head, ...body].map((row) => row.map((cell) => csvCell(cell)).join(',')).join('\r\n')
}

export function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

/**
 * Writes `rows` (already filtered and sorted) as a .csv or .xlsx download.
 * `from`/`to` only name the file; the filtering happened before this.
 */
export async function exportRows(type, rows, { columns, format, from = '', to = '' }) {
  const cols = columnsFor(type, columns)
  const head = cols.map((c) => c.label)
  const body = rows.map((r) => cols.map((c) => c.get(r) ?? ''))
  const filename = exportFilename(type, { from, to }, format)

  if (format === 'csv') {
    downloadBlob(new Blob([buildCsv(head, body)], { type: 'text/csv;charset=utf-8;' }), filename)
    return
  }

  // Loaded on first XLSX export only, so the panel's main bundle does not carry the zip writer.
  const { default: writeExcelFile } = await import('write-excel-file/browser')
  const sheet = [head.map((value) => ({ value, fontWeight: 'bold' })), ...body]
  await writeExcelFile(sheet, { columns: head.map(() => ({ width: 22 })) }).toFile(filename)
}
