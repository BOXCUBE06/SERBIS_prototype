import assert from 'node:assert/strict'
import { test } from 'node:test'
import { filters, rules, sentenceCase } from './inputRules.ts'

test('a name filter drops digits and symbols but keeps ñ, hyphen, apostrophe and period', () => {
  assert.equal(filters.name("Ma. Niña O'Brien-Cruz 3@"), "Ma. Niña O'Brien-Cruz ")
})

test('name rule: letters only, 2 to 50, with a plain message', () => {
  assert.equal(rules.name('Name')('Niña'), true)
  assert.equal(rules.name('Name')('Juan3'), 'Name can only contain letters, spaces, hyphens, apostrophes and periods.')
  assert.equal(rules.name('Name')('J'), 'Name must be at least 2 letters.')
  assert.equal(rules.name('Name')('a'.repeat(51)), 'Name must be 50 characters or fewer.')
  assert.equal(rules.name('Name')(''), true)
})

test('phone: digits only, 11, starts 09', () => {
  assert.equal(filters.phone('0917-123 4567x9'), '09171234567')
  assert.equal(rules.phone()('09171234567'), true)
  assert.equal(typeof rules.phone()('+639171234567'), 'string')
  assert.equal(typeof rules.phone()('19171234567'), 'string')
})

test('age: digits only, at most 3, 0 to 120', () => {
  assert.equal(filters.age('12ab34'), '123')
  assert.equal(rules.age()('0'), true)
  assert.equal(rules.age()('120'), true)
  assert.equal(typeof rules.age()('121'), 'string')
  assert.equal(typeof rules.age()('1.5'), 'string')
})

test('vehicle identifiers refuse @ and other symbols', () => {
  assert.equal(rules.identifier('Unit')('AMB-01 (Type I)'), true)
  assert.equal(typeof rules.identifier('Unit')('AMB@01'), 'string')
  assert.equal(filters.identifier('AMB@01!'), 'AMB01')
})

test('addresses allow letters, numbers, spaces and , . - # /', () => {
  assert.equal(rules.address()('Purok 3, #12/B Rizal St.'), true)
  assert.equal(typeof rules.address()('Purok 3@'), 'string')
})

test('notes lose tags and control characters, and are capped by the rule', () => {
  assert.equal(filters.note('Fall <b>x</b>\u0007'), 'Fall bx/b')
  assert.equal(typeof rules.note('Condition')('a'.repeat(501)), 'string')
})

test('sentenceCase raises only the first letter', () => {
  assert.equal(sentenceCase('flooded road'), 'Flooded road')
  assert.equal(sentenceCase('ñandú'), 'Ñandú')
  assert.equal(sentenceCase(''), '')
  assert.equal(sentenceCase(null), '')
})
