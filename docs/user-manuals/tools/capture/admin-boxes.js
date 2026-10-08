// Annotation boxes for the ADM shots, measured from the DOM at capture time
// rather than read off the image. Each entry lists, in caption order, a
// function returning one locator or an array of locators (boxed together).
// admin.js converts CSS pixels to raw-image pixels using the shot's origin.
const fs = require('fs')
const path = require('path')
const { REPO } = require('./common')

const ANNOTATIONS = path.join(REPO, 'docs/user-manuals/screenshots/annotations.json')
const SCALE = 2
const PAD = 4

const ov = (p) => p.locator('.v-overlay--active .v-overlay__content').last()
// A form field by its visible label, its aria-label or its placeholder (the
// panel's dialogs put the label above the field, outside .v-input).
const field = (scope, label) => {
  const byText = scope.locator('.v-input', { hasText: label })
  if (label instanceof RegExp) return byText.first()
  const sel = ['input', 'textarea'].flatMap((t) => [`${t}[aria-label*="${label}" i]`, `${t}[placeholder*="${label}" i]`]).join(', ')
  return scope.locator('.v-input').filter({ has: (typeof scope.page === 'function' ? scope.page() : scope).locator(sel) }).or(byText).first()
}
const button = (scope, name) => scope.getByRole('button', { name, exact: typeof name === 'string' }).last()
const text = (scope, t) => scope.getByText(t, { exact: true }).last()
// The section under an h3 heading in a detail panel.
const section = (p, heading) => p.locator('h3', { hasText: heading }).filter({ visible: true }).last().locator('xpath=..')
const visibleRow = (p, ...texts) => texts.reduce((l, t) => l.filter({ hasText: t }), p.locator('tr:visible')).first()

const BOXES = {
  'ADM-01': [(p) => p.locator('#login-username'), (p) => p.locator('#login-password'), (p) => p.locator('button[type=submit]').first()],
  'ADM-02': [(p) => p.locator('.v-otp-input').first(), (p) => button(p, /Resend code/), (p) => button(p, 'Verify')],
  // The field itself, not .v-input: the hint and message rows under it made the boxes overlap.
  'ADM-03': [(p) => p.locator('.v-field').nth(0), (p) => p.locator('.v-field').nth(1), (p) => p.locator('.v-field').nth(2), (p) => button(p, 'Save and continue')],
  'ADM-04': [
    (p) => p.locator('nav, .v-navigation-drawer').first(),
    (p) => p.getByRole('link', { name: 'Dashboard' }).first(),
    (p) => p.getByRole('button', { name: /notification/i }).first(),
    (p) => p.getByText('Echague Panel').first().locator('xpath=../..'),
  ],
  'ADM-05': [(p) => button(ov(p), 'Logout')],
  'ADM-06': [
    (p) => [p.getByText('Available units', { exact: false }).first(), p.getByText('Overdue borrowing', { exact: false }).first()].map((l) => l.locator('xpath=ancestor::*[contains(@class,"v-card")][1]')),
    (p) => ['Services', 'Borrowing'].map((t) => p.locator('button, [role=tab]').filter({ hasText: new RegExp(`^\s*${t}\s*\d`) }).last()),
    (p) => p.locator('tbody tr').first().locator('td').first(),
    (p) => p.locator('tbody tr').first(),
  ],
  'ADM-07': [
    (p) => ['This month', 'Custom'].map((t) => button(p, t)),
    (p) => field(p, 'Barangay'),
    (p) => field(p, 'Service'),
  ],
  'ADM-08': [
    (p) => p.getByText('Requests by month and service', { exact: false }).first(),
    (p) => [p.getByRole('button', { name: /chart$/i }).first(), p.getByRole('button', { name: /table$/i }).first()],
    (p) => p.getByText('Requests by month and service', { exact: false }).first().locator('xpath=following::table[1]'),
  ],
  'ADM-09': [
    (p) => ['All', 'Cancelled'].map((t) => p.getByRole('tab', { name: new RegExp(`^${t}`) }).first()),
    (p) => [p.getByPlaceholder(/Search name or transaction/), field(p, 'Unit')],
    (p) => p.locator('tbody tr').first(),
  ],
  'ADM-10': [
    (p) => [text(p, 'Ricardo Bumanglag'), p.getByText(/Head of the Family ·/).last()],
    (p) => [section(p, 'REQUEST'), section(p, 'ATTACHMENTS')],
    (p) => section(p, 'ASSIGNMENT'),
    (p) => [button(p, 'Disapprove'), button(p, 'Approve & assign')],
  ],
  'ADM-11': [
    (p) => p.getByText('Rescue Vehicle (Hilux) 2').last().locator('xpath=ancestor::*[contains(@class,"v-list-item") or self::label or self::li][1]'),
    (p) => p.getByText(/^Responders \d/).last(),
    (p) => button(p, 'Done'),
  ],
  'ADM-12': [(p) => button(ov(p), 'Approve')],
  'ADM-13': [(p) => field(ov(p), 'Reason for declining'), (p) => button(ov(p), 'Disapprove request')],
  'ADM-14': [(p) => p.locator('.v-chip, [class*=pill]').filter({ hasText: 'Responding' }).last(), (p) => button(p, 'Mark as resolved')],
  'ADM-15': [
    (p) => ['Registered Head of the Family', 'No account'].map((t) => button(ov(p), t)),
    (p) => field(ov(p), 'Service'),
    (p) => field(ov(p), 'Description'),
    (p) => button(ov(p), 'File request'),
  ],
  'ADM-16': [
    (p) => ['Print / PDF', 'XLSX'].map((t) => button(ov(p), t)),
    (p) => ['Transaction No.', 'Vehicle ID (internal)'].map((t) => ov(p).locator('label', { hasText: t }).first()),
    (p) => [ov(p).locator('label', { hasText: 'Sort order' }).first(), ov(p).getByRole('combobox').last()],
    (p) => [ov(p).getByText('From', { exact: true }).last(), ov(p).getByText('To', { exact: true }).last()].map((l) => l.locator('xpath=ancestor::*[contains(@class,"v-input")][1]')),
  ],
  // Top to bottom, so the numbers read down the panel.
  'ADM-17': [
    (p) => p.getByText('Submitted', { exact: true }).last().locator('xpath=following-sibling::dd[1]'),
    (p) => section(p, 'ROUTE'),
    (p) => section(p, 'PATIENT'),
    (p) => button(p, 'Approve & dispatch'),
  ],
  'ADM-18': [
    (p) => ['Ambulance 1', 'Ambulance 9'].map((t) => p.getByText(t, { exact: true }).last()),
    (p) => p.getByText('Available', { exact: true }).last(),
    (p) => button(p, 'Done'),
  ],
  'ADM-47': [
    (p) => p.locator('.pd-header .text-caption').filter({ visible: true }).last(),
    (p) => p.locator('.pd-row--on').filter({ visible: true }).last(),
    (p) => button(p, 'Done'),
  ],
  'ADM-48': [
    (p) => [section(p, 'ASSIGNMENT'), p.locator('.assign-box').filter({ visible: true }).last()],
    (p) => button(p, 'Dispatch'),
    (p) => button(p, 'Disapprove this booking'),
  ],
  'ADM-19': [(p) => field(ov(p), 'Starts'), (p) => field(ov(p), 'Ends'), (p) => field(ov(p), 'Reason for the change'), (p) => button(ov(p), 'Reschedule')],
  'ADM-20': [
    (p) => ['Day', 'Month'].map((t) => button(ov(p), t)),
    (p) => button(ov(p), 'Today'),
    (p) => ov(p).locator('[class*=trip], [class*=event], [class*=block]').first(),
    (p) => ov(p).locator('[class*=legend]').first(),
  ],
  'ADM-21': [
    (p) => [field(p, 'Left the office'), field(p, 'Arrived at destination')],
    (p) => [field(p, 'Odometer at departure'), field(p, 'Odometer on return')],
    (p) => field(p, 'Did not reach destination'),
    (p) => button(p, 'Save trip log'),
  ],
  'ADM-22': [(p) => visibleRow(p, 'TXN-000014'), (p) => button(p, 'Print')],
  'ADM-24': [(p) => field(p, 'Equipment'), (p) => field(p, 'Barangay'), (p) => visibleRow(p, 'Water Purifier', 'Ernesto')],
  'ADM-25': [
    (p) => text(p, 'Ernesto Dumlao'),
    (p) => section(p, 'EQUIPMENT'),
    (p) => section(p, 'HANDOVER'),
    (p) => button(p, 'Approve request'),
    (p) => button(p, 'Deny request'),
  ],
  'ADM-26': [(p) => field(ov(p), 'Reason for denial'), (p) => button(ov(p), 'Deny request')],
  'ADM-27': [(p) => field(ov(p), 'Due back on'), (p) => button(ov(p), 'Approve request')],
  'ADM-28': [(p) => section(p, 'CONDITION PHOTOS'), (p) => button(p, 'Confirm items returned')],
  'ADM-29': [(p) => field(ov(p), 'Unit identifier'), (p) => field(ov(p), 'Type'), (p) => field(ov(p), 'Specification'), (p) => field(ov(p), 'Status'), (p) => button(ov(p), 'Add unit')],
  'ADM-30': [(p) => ov(p).getByRole('textbox', { name: 'Name', exact: true }).locator('xpath=ancestor::*[contains(@class,"v-input")][1]'), (p) => field(ov(p), 'Position'), (p) => field(ov(p), 'Contact number'), (p) => button(ov(p), 'Add responder')],
  'ADM-31': [(p) => field(ov(p), 'Item name'), (p) => field(ov(p), 'Total owned'), (p) => field(ov(p), 'Status'), (p) => button(ov(p), 'Add')],
  'ADM-32': [(p) => field(p, 'Status'), (p) => button(p, 'Refresh')],
  'ADM-33': [(p) => p.getByPlaceholder(/Search name or mobile/), (p) => field(p, 'barangay'), (p) => field(p, 'Status'), (p) => p.locator('tbody tr').first()],
  'ADM-34': [(p) => ov(p).locator('.pending-note'), (p) => button(ov(p), /Approve organization/), (p) => button(ov(p), /Reject organization/)],
  'ADM-35': [(p) => field(p, 'Title shown to residents'), (p) => p.getByText(/request-letter\.pdf/).first(), (p) => button(p, 'Publish')],
  'ADM-36': [(p) => field(p, 'Start from a template'), (p) => p.locator('textarea').first(), (p) => p.locator('.v-input', { hasText: 'San Fabian' }).first(), (p) => button(p, 'Send blast')],
  'ADM-37': [(p) => field(ov(p), 'Text blast code'), (p) => button(ov(p), 'Send blast')],
  'ADM-38': [(p) => field(ov(p), 'Current code'), (p) => field(ov(p), 'New code'), (p) => button(ov(p), 'Set code')],
  'ADM-39': [(p) => field(ov(p), 'Filipino name'), (p) => field(ov(p), 'Description'), (p) => button(ov(p), 'Save changes')],
  'ADM-40': [(p) => p.locator('tbody tr').first(), (p) => p.locator('tbody tr').first().locator('input[type=checkbox]').first()],
  'ADM-41': [(p) => p.locator('tbody tr').first(), (p) => p.locator('tbody tr').first().locator('input[type=checkbox]').first()],
  'ADM-42': [
    (p) => field(ov(p), /^Name\s*\*/),
    (p) => field(ov(p), 'Name in Filipino'),
    (p) => [ov(p).getByRole('textbox', { name: 'Carrier or line' }).first(), ov(p).getByRole('textbox', { name: 'Number', exact: true }).first(), button(ov(p), 'Add number')],
    (p) => button(ov(p), 'Add hotline'),
  ],
  'ADM-43': [(p) => [field(ov(p), 'First name'), field(ov(p), 'Last name')], (p) => field(ov(p), 'Username'), (p) => field(ov(p), 'Mobile number'), (p) => button(ov(p), 'Create account')],
  'ADM-44': [
    (p) => ['Dashboard', 'Activity Logs'].map((t) => ov(p).locator('label', { hasText: t }).first()),
    (p) => ov(p).locator('.v-switch, [role=switch]').first(),
    (p) => button(ov(p), 'Save access'),
  ],
  'ADM-45': [(p) => ov(p), (p) => ov(p).getByText('Reset password', { exact: true }), (p) => button(p, 'Close accounts')],
  'ADM-46': [(p) => field(p, 'Search logs'), (p) => p.locator('tbody tr').first()],
}

// Measures the boxes for one shot. origin is the CSS-pixel top-left of the
// image; size its CSS width and height. Returns [boxes, notFound labels].
async function measure(id, page, origin, size) {
  const spec = BOXES[id]
  if (!spec) return [[], []]
  const boxes = []
  const missing = []
  for (let i = 0; i < spec.length; i++) {
    let rect = null
    try {
      const got = spec[i](page)
      const locs = Array.isArray(got) ? got : [got]
      for (const loc of locs) {
        const b = await loc.boundingBox({ timeout: 2000 })
        if (!b) continue
        rect = rect
          ? { x1: Math.min(rect.x1, b.x), y1: Math.min(rect.y1, b.y), x2: Math.max(rect.x2, b.x + b.width), y2: Math.max(rect.y2, b.y + b.height) }
          : { x1: b.x, y1: b.y, x2: b.x + b.width, y2: b.y + b.height }
      }
    } catch { rect = null }
    if (rect) {
      const x1 = Math.max(0, rect.x1 - PAD - origin.x)
      const y1 = Math.max(0, rect.y1 - PAD - origin.y)
      const x2 = Math.min(size.width, rect.x2 + PAD - origin.x)
      const y2 = Math.min(size.height, rect.y2 + PAD - origin.y)
      if (x2 > x1 + 2 && y2 > y1 + 2) {
        boxes.push({ x: Math.round(x1 * SCALE), y: Math.round(y1 * SCALE), w: Math.round((x2 - x1) * SCALE), h: Math.round((y2 - y1) * SCALE), label: i + 1 })
        continue
      }
    }
    missing.push(i + 1)
  }
  return [boxes, missing]
}

function writeBoxes(id, boxes) {
  const data = JSON.parse(fs.readFileSync(ANNOTATIONS, 'utf8'))
  data[id] = boxes
  fs.writeFileSync(ANNOTATIONS, JSON.stringify(data, null, 2) + '\n')
}

module.exports = { measure, writeBoxes, BOXES }
