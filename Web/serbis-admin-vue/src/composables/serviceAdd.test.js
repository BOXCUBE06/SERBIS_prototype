import test from 'node:test'
import assert from 'node:assert/strict'
import { nextSteps } from './serviceAdd.js'

const all = () => true
const none = () => false

test('a response service asks for the audience, the vehicles, then switching it on', () => {
  const steps = nextSteps({ category: 'relief' }, all)
  assert.deepEqual(steps.map((s) => s.key), ['audience', 'vehicles', 'enable'])
  assert.equal(steps[0].link.to, '/service-audience')
  assert.equal(steps[1].link.to, '/service-vehicles')
})

test('a program has no vehicle step', () => {
  assert.deepEqual(nextSteps({ category: 'programs' }, all).map((s) => s.key), ['audience', 'enable'])
})

test('a page this account cannot open is named, not linked', () => {
  const steps = nextSteps({ category: 'rescue' }, none)
  assert.equal(steps[0].link, null)
  assert.match(steps[0].fallback, /Service Audience/)
  assert.equal(steps[1].link, null)
  assert.match(steps[1].fallback, /Service Vehicles/)
})

test('links follow each section on its own', () => {
  const steps = nextSteps({ category: 'rescue' }, (s) => s === 'service_vehicles')
  assert.equal(steps[0].link, null)
  assert.equal(steps[1].link.to, '/service-vehicles')
})
