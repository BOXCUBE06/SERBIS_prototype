import test from 'node:test'
import assert from 'node:assert/strict'
import { foldVolume, OTHERS } from './volumeColors.js'

const PALETTE = ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8']
const GREY = 'grey'

// n services named S01..Sn; S01 is the busiest. Two months each.
const services = (n) => Array.from({ length: n }, (_, i) => ({
  label: `S${String(i + 1).padStart(2, '0')}`,
  data: [100 - i, 1],
}))

const distinct = (chart) => {
  const colours = chart.map((s) => s.color).filter((c) => c !== GREY)
  assert.equal(new Set(colours).size, colours.length, `a colour repeats: ${colours}`)
}

test('7 services: each has its own colour, no grey segment', () => {
  const { chart } = foldVolume(services(7), PALETTE, GREY)
  assert.equal(chart.length, 7)
  assert.ok(chart.every((s) => s.color !== GREY))
  distinct(chart)
})

test('8 services: all eight colours are used, no grey segment', () => {
  const { chart } = foldVolume(services(8), PALETTE, GREY)
  assert.deepEqual(chart.map((s) => s.color), PALETTE)
})

test('12 services: the 7 busiest get colours, the other 5 share one grey segment', () => {
  const { chart, colorOf } = foldVolume(services(12), PALETTE, GREY)
  assert.equal(chart.length, 8)
  assert.deepEqual(chart.slice(0, 7).map((s) => s.label), ['S01', 'S02', 'S03', 'S04', 'S05', 'S06', 'S07'])
  distinct(chart)

  const grey = chart.at(-1)
  assert.equal(grey.label, '5 more services')
  assert.equal(grey.color, GREY)
  // S08..S12 summed per month: 93+92+91+90+89, and 1 each.
  assert.deepEqual(grey.data, [455, 5])
  assert.equal(colorOf('S12'), GREY, 'a folded service keeps a grey dot in the table')
})

test('12 services and Others: the grey segment names both', () => {
  const series = [...services(12), { label: OTHERS, data: [500, 500] }]
  const { chart, colorOf } = foldVolume(series, PALETTE, GREY)
  assert.equal(chart.at(-1).label, 'Others + 5 more services')
  assert.deepEqual(chart.at(-1).data, [955, 505])
  assert.ok(!chart.slice(0, 7).some((s) => s.label === OTHERS), 'Others never takes a colour, however busy')
  assert.equal(colorOf(OTHERS), GREY)
})

test('Others alone is its own grey segment, still called Others', () => {
  const { chart } = foldVolume([...services(3), { label: OTHERS, data: [4, 0] }], PALETTE, GREY)
  assert.deepEqual(chart.map((s) => s.label), ['S01', 'S02', 'S03', OTHERS])
  assert.equal(chart.at(-1).color, GREY)
})

test('a quiet service does not take the colour of a busier one, whatever its name', () => {
  const series = [{ label: 'Aaa', data: [1, 0] }, { label: 'Zzz', data: [9, 9] }]
  const { colorOf } = foldVolume(series, PALETTE, GREY)
  assert.equal(colorOf('Zzz'), 'c1')
  assert.equal(colorOf('Aaa'), 'c2')
})

// The report has no series for a service nobody requested in the range, so a
// new service is invisible until used; once used it ranks by volume, and a name
// that sorts first moves nobody.
test('a new, lightly used service shifts no existing colour', () => {
  const before = foldVolume(services(7), PALETTE, GREY).colorOf
  const withNew = foldVolume([...services(7), { label: 'AAA New service', data: [0, 1] }], PALETTE, GREY).colorOf
  for (const s of services(7)) { assert.equal(withNew(s.label), before(s.label), s.label) }
})
