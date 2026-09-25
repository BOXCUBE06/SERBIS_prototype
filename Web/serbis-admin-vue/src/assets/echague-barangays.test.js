// Run: npm test
import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { geoArea } from 'd3-geo'
import { buildEchagueMap } from '../composables/echagueMap.js'

const collection = JSON.parse(readFileSync(new URL('echague-barangays.json', import.meta.url), 'utf8'))

test('64 barangays, each with a PSGC code', () => {
  assert.equal(collection.features.length, 64)
  assert.ok(collection.features.every((f) => /^\d{10}$/.test(f.properties.psgc_code)))
})

test('no feature is wound as the inverse polygon (whole globe minus the barangay)', () => {
  for (const feature of collection.features) {
    // A barangay is a few km across; the inverse of one is ~4*PI steradians.
    assert.ok(geoArea(feature) < 0.01, `${feature.properties.name} covers ${geoArea(feature)} sr`)
  }
})

test('the projected collection fits the viewBox and no barangay spans the frame', () => {
  const { W, H, toPath } = buildEchagueMap(collection)
  const [[x0, y0], [x1, y1]] = toPath.bounds(collection)
  assert.ok(x0 >= 0 && y0 >= 0 && x1 <= W && y1 <= H, `bounds ${x0},${y0} to ${x1},${y1} outside ${W}x${H}`)

  for (const feature of collection.features) {
    const [[fx0, fy0], [fx1, fy1]] = toPath.bounds(feature)
    // The whole of Echague fills the frame, so a single barangay must not do so too.
    assert.ok(!((fx1 - fx0) > W * 0.9 && (fy1 - fy0) > H * 0.9), `${feature.properties.name} spans the frame`)
  }
})
