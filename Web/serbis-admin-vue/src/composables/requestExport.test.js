// What a CSV export holds: the ticked columns as headers, one row per row of
// the filtered list handed in, and nothing that Excel would run as a formula.
import test from 'node:test'
import assert from 'node:assert/strict'

let blob
URL.createObjectURL = (b) => ((blob = b), 'blob:test')
URL.revokeObjectURL = () => {}
globalThis.document = { createElement: () => ({ click() {} }) }

const { buildCsv, exportRows } = await import('./requestExport.js')
const { defaultColumns, columnsFor, prepareRows } = await import('./requestFields.js')

const row = (id, over = {}) => ({
  request_id: id,
  status: 'Pending',
  description: `Need help ${id}`,
  service: { service_name: 'Road Clearing' },
  resident: { first_name: 'Maria', last_name: `Santos${id}`, phone_number: '+639171234567' },
  created_at: '2026-10-01T02:00:00Z',
  ...over,
})

// Splits on the CRLF between records; cells here hold no line breaks.
const records = async () => (await blob.text()).replace(/^﻿/, '').split('\r\n').map((l) => l.slice(1, -1).split('","'))

test('a CSV has the ticked columns as headers and one record per filtered row', async () => {
  const columns = defaultColumns('request')
  const rows = [row(1), row(2), row(3)]

  await exportRows('request', rows, { columns, format: 'csv' })
  const out = await records()

  assert.deepEqual(out[0], columnsFor('request', columns).map((c) => c.label))
  assert.equal(out.length, rows.length + 1)
})

test('only the chosen columns are written', async () => {
  await exportRows('request', [row(1)], { columns: ['status', 'description'], format: 'csv' })
  const out = await records()

  assert.deepEqual(out[0], ['Status', 'Description'])
  assert.deepEqual(out[1], ['Pending', 'Need help 1'])
})

test('the row count follows the filter and the status filter holds', async () => {
  const all = [row(1), row(2, { status: 'Resolved' }), row(3, { status: 'Resolved' }), row(4)]
  const resolved = all.filter((r) => r.status === 'Resolved')

  await exportRows('request', prepareRows('request', resolved), { columns: ['request_id', 'status'], format: 'csv' })
  const out = await records()

  assert.equal(out.length, 3)
  assert.ok(out.slice(1).every((r) => r[1] === 'Resolved'))
})

test('an empty list still writes the header row', async () => {
  await exportRows('request', [], { columns: ['status'], format: 'csv' })

  assert.deepEqual(await records(), [['Status']])
})

test('dates are written in Manila time', async () => {
  await exportRows('request', [row(1, { created_at: '2026-10-01T20:30:00Z' })], { columns: ['created_at'], format: 'csv' })
  const out = await records()

  assert.match(out[1][0], /Oct 2, 2026/)
})

test('a cell that starts like a formula is kept as text', () => {
  const csv = buildCsv(['A'], [['=SUM(A1)'], ['+1 call'], ['@home'], ['-5 items'], ['42'], ['-7']])
  const cells = csv.replace(/^﻿/, '').split('\r\n').slice(1)

  assert.deepEqual(cells, [`"'=SUM(A1)"`, `"'+1 call"`, `"'@home"`, `"'-5 items"`, '"42"', '"-7"'])
})

test('quotes inside a cell are doubled and the file starts with a BOM', () => {
  const csv = buildCsv(['Note'], [['say "hi"']])

  assert.ok(csv.startsWith('﻿'))
  assert.ok(csv.endsWith('"say ""hi"""'))
})

test('a date range keeps only the rows inside it, and the file has exactly those', async () => {
  const rows = [
    row(1, { created_at: '2026-09-30T02:00:00Z' }),
    row(2, { created_at: '2026-10-05T02:00:00Z' }),
    row(3, { created_at: '2026-10-06T02:00:00Z' }),
    row(4, { created_at: '2026-10-20T02:00:00Z' }),
  ]
  const kept = prepareRows('request', rows, { from: '2026-10-01', to: '2026-10-10' })

  await exportRows('request', kept, { columns: ['request_id'], format: 'csv', from: '2026-10-01', to: '2026-10-10' })

  assert.equal((await records()).length, 3)
})
