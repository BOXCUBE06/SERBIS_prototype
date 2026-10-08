import test from 'node:test'
import assert from 'node:assert/strict'
import { ACCESS_TEMPLATES, ASSIGNABLE_SECTIONS } from './adminSections.ts'

const assignable = new Set(ASSIGNABLE_SECTIONS.map((s) => s.key))

test('every template names only sections that can be given out', () => {
  for (const t of ACCESS_TEMPLATES) {
    for (const key of t.sections) assert.ok(assignable.has(key), `${t.title}: ${key}`)
    assert.equal(new Set(t.sections).size, t.sections.length, `${t.title} repeats a section`)
  }
})

test('no template hands out Staff Accounts', () => {
  for (const t of ACCESS_TEMPLATES) assert.ok(!t.sections.includes('staff'), t.title)
})

test('the two templates hold the agreed pages', () => {
  const byKey = Object.fromEntries(ACCESS_TEMPLATES.map((t) => [t.key, t.sections]))
  assert.equal(byKey.communications.length, 7)
  assert.equal(byKey.operations.length, 14)
  assert.ok(!byKey.communications.includes('requests'))
  assert.ok(byKey.operations.includes('responders'), 'Resident Requests needs Responders for its picker')
})
