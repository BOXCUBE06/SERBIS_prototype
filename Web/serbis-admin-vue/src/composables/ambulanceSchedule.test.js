import test from 'node:test'
import assert from 'node:assert/strict'
import {
  addDays, addMonths, monthGrid, fetchRange, toTrips, tripsOnDay, layoutRow,
  fmtTime, shortTime, shortName, unitNote, daySummary, monthCells, monthSummary, isCarried, waitingLabel,
} from './ambulanceSchedule.js'

// Local-time instants, so the tests do not depend on the machine's zone.
const at = (y, m, d, h = 0, min = 0) => new Date(y, m - 1, d, h, min).toISOString()
const trip = (id, status, vehicleId, start, end, patient = 'Rosa Pascual') => ({
  request_id: id, status, vehicle_id: vehicleId, starts_at: start, ends_at: end, patient_name: patient,
})
const units = [
  { vehicle_id: 1, unit_identifier: 'AMB-01', is_maintenance: false },
  { vehicle_id: 2, unit_identifier: 'AMB-02', is_maintenance: false },
  { vehicle_id: 3, unit_identifier: 'AMB-03', is_maintenance: true },
]

test('a month grid is whole Sunday-to-Saturday weeks with the neighbouring days', () => {
  const grid = monthGrid('2026-10-15')
  assert.equal(grid.weeks, 5)
  assert.equal(grid.days.length, 35)
  assert.equal(grid.days[0], '2026-09-27')
  assert.equal(grid.days.at(-1), '2026-10-31')
  assert.equal(monthGrid('2026-02-10').weeks, 4)
  assert.equal(monthGrid('2026-08-10').weeks, 6)
})

test('the range asked for covers the grid and a day either side', () => {
  assert.deepEqual(fetchRange('2026-10-15'), { from: '2026-09-26', to: '2026-11-01' })
})

test('moving by a month keeps the day, or the last day of a shorter month', () => {
  assert.equal(addMonths('2026-01-31', 1), '2026-02-28')
  assert.equal(addMonths('2026-10-05', -1), '2026-09-05')
  assert.equal(addDays('2026-10-31', 1), '2026-11-01')
})

test('a trip over midnight is on both days, and a trip touching midnight is on one', () => {
  const trips = toTrips([
    trip(1, 'Booked', 1, at(2026, 10, 5, 23), at(2026, 10, 6, 1)),
    trip(2, 'Booked', 1, at(2026, 10, 5, 22), at(2026, 10, 6, 0)),
  ])
  assert.deepEqual(tripsOnDay(trips, '2026-10-05').map((t) => t.request_id), [2, 1])
  assert.deepEqual(tripsOnDay(trips, '2026-10-06').map((t) => t.request_id), [1])
})

test('a trip is placed by its start and length, and overlapping trips get their own lanes', () => {
  const trips = toTrips([
    trip(1, 'Booked', 1, at(2026, 10, 5, 6), at(2026, 10, 5, 12)),
    trip(2, 'Booked', 1, at(2026, 10, 5, 9), at(2026, 10, 5, 10)),
    trip(3, 'Booked', 1, at(2026, 10, 5, 13), at(2026, 10, 5, 14)),
  ])
  const { placed, laneCount } = layoutRow(trips, '2026-10-05')
  assert.equal(placed[0].leftPct, 25)
  assert.equal(placed[0].widthPct, 25)
  assert.deepEqual(placed.map((p) => p.lane), [0, 1, 0])
  assert.equal(laneCount, 2)
})

test('times and names read the way the board shows them', () => {
  const ms = new Date(2026, 9, 5, 13, 30).getTime()
  assert.equal(fmtTime(ms), '1:30 PM')
  assert.equal(shortTime(new Date(2026, 9, 5, 9, 0).getTime()), '9:00a')
  assert.equal(fmtTime(new Date(2026, 9, 5, 0, 5).getTime()), '12:05 AM')
  assert.equal(shortName('Rosa Pascual'), 'R. Pascual')
  assert.equal(shortName('Ana Maria dela Cruz'), 'A. Maria dela Cruz')
  assert.equal(shortName(null), 'Unknown')
})

test('a unit says whether it is out, free, or free until its next trip', () => {
  const now = new Date(2026, 9, 5, 12, 59).getTime()
  const out = toTrips([trip(1, 'Responding', 1, at(2026, 10, 5, 12), at(2026, 10, 5, 14))])
  const later = toTrips([trip(2, 'Booked', 2, at(2026, 10, 5, 16), at(2026, 10, 5, 18)), trip(3, 'Resolved', 2, at(2026, 10, 5, 8), at(2026, 10, 5, 10))])

  assert.equal(unitNote(units[0], out, true, now).text, 'On a trip until 2:00 PM')
  assert.equal(unitNote(units[1], later, true, now).text, 'Free · next at 4:00 PM')
  assert.equal(unitNote(units[1], [], true, now).text, 'Free now')
  // A resolved trip that still covers the clock does not hold the unit.
  const done = toTrips([trip(4, 'Resolved', 1, at(2026, 10, 5, 12), at(2026, 10, 5, 14))])
  assert.equal(unitNote(units[0], done, true, now).text, 'Free now')
  assert.equal(unitNote(units[0], out, false, now).text, '1 trip')
  assert.equal(unitNote(units[0], [], false, now).text, 'No trips')
  assert.equal(unitNote(units[2], [], true, now).text, 'Under maintenance')
})

test('the day summary counts trips, unassigned requests and units', () => {
  const now = new Date(2026, 9, 5, 12, 59).getTime()
  const day = toTrips([
    trip(1, 'Responding', 1, at(2026, 10, 5, 12), at(2026, 10, 5, 14)),
    trip(2, 'Pending', null, at(2026, 10, 5, 15), at(2026, 10, 5, 17)),
  ])
  assert.equal(daySummary(day, units, true, now), '2 trips · 1 unassigned · 1 of 3 units free now')
  assert.equal(daySummary(day, units, false, now), '2 trips · 1 unassigned · 1 of 3 units booked')
  assert.equal(daySummary([], units, false, now), 'No trips · all 3 units free')
})

test('the month grid shows counts, a bar per unit, two chips and the rest as more', () => {
  const trips = toTrips([
    trip(1, 'Booked', 1, at(2026, 10, 5, 9), at(2026, 10, 5, 11)),
    trip(2, 'Resolved', 2, at(2026, 10, 5, 10), at(2026, 10, 5, 12)),
    trip(3, 'Pending', null, at(2026, 10, 5, 15), at(2026, 10, 5, 17), 'Celia Gumaru'),
    trip(4, 'Booked', 1, at(2026, 9, 28, 9), at(2026, 9, 28, 11)),
  ])
  const cells = monthCells(trips, units, '2026-10-15', '2026-10-05')
  const fifth = cells.find((cell) => cell.key === '2026-10-05')

  assert.equal(cells.length, 35)
  assert.equal(fifth.isToday, true)
  assert.equal(fifth.trips.length, 3)
  assert.deepEqual(fifth.bars, [true, true, false])
  assert.deepEqual(fifth.chips.map((chip) => [chip.time, chip.name, chip.unit]), [['9:00a', 'R. Pascual', '01'], ['10:00a', 'R. Pascual', '02']])
  assert.equal(fifth.more, 1)
  assert.equal(cells[0].inMonth, false)
  // Only the month's own days count, and each trip once.
  assert.equal(monthSummary(cells), '3 trips this month · 1 still unassigned')
  assert.equal(monthSummary(monthCells([], units, '2026-10-15', '2026-10-05')), 'No trips this month')
})

// A request with no time and no unit, as GET /ambulance-schedule flags it.
const waiting = (id, filed) => ({ ...trip(id, 'Pending', null, filed, filed), waiting: true })

test('a request still waiting is carried onto today and leaves the day it was filed', () => {
  const trips = toTrips([waiting(1, at(2026, 10, 2, 7, 17)), waiting(2, at(2026, 10, 5, 9))])

  assert.deepEqual(tripsOnDay(trips, '2026-10-05', '2026-10-05').map((t) => t.request_id), [1, 2])
  assert.deepEqual(tripsOnDay(trips, '2026-10-02', '2026-10-05'), [])
  assert.equal(isCarried(trips[0], '2026-10-05'), true)
  assert.equal(isCarried(trips[1], '2026-10-05'), false)
  assert.equal(waitingLabel(trips[0], '2026-10-05'), 'Waiting 3d')
  // Looking at another day: it is nowhere but today.
  assert.deepEqual(tripsOnDay(trips, '2026-10-06', '2026-10-05'), [])
})

test('today counts the carried requests, in the Unassigned note and both summaries', () => {
  const now = new Date(2026, 9, 5, 12, 59).getTime()
  const trips = toTrips([
    waiting(1, at(2026, 10, 2, 7)),
    waiting(2, at(2026, 9, 28, 16)),
    trip(3, 'Booked', 1, at(2026, 10, 5, 15), at(2026, 10, 5, 17)),
  ])
  const today = tripsOnDay(trips, '2026-10-05', '2026-10-05')

  assert.equal(daySummary(today, units, true, now, trips), '3 trips · 2 unassigned · 2 of 3 units free now')

  const cells = monthCells(trips, units, '2026-10-15', '2026-10-05')
  const fifth = cells.find((cell) => cell.key === '2026-10-05')
  assert.equal(fifth.trips.length, 3)
  assert.equal(fifth.chips[0].time, '7d')
  assert.equal(cells.find((cell) => cell.key === '2026-10-02').trips.length, 0)
  assert.equal(monthSummary(cells), '3 trips this month · 2 still unassigned')
})

test('a unit on a Responding trip is out, whatever the clock says', () => {
  const now = new Date(2026, 9, 5, 13, 18).getTime()
  // Dispatched, though its block starts after now.
  const early = toTrips([trip(1, 'Responding', 1, at(2026, 10, 5, 13, 38), at(2026, 10, 5, 15, 38))])
  assert.equal(unitNote(units[0], early, true, now).text, 'On a trip until 3:38 PM')
  assert.equal(daySummary(early, units, true, now), '1 trip · 1 of 3 units free now')

  // Out since yesterday and past its estimated end: out, with no "until".
  const overrun = toTrips([trip(2, 'Responding', 1, at(2026, 10, 4, 20), at(2026, 10, 4, 22))])
  assert.equal(unitNote(units[0], [], true, now, overrun).text, 'On a trip')
  assert.equal(daySummary([], units, true, now, overrun), 'No trips · 1 of 3 units free now')

  // A Booked trip only holds the unit while its window covers now.
  const later = toTrips([trip(3, 'Booked', 1, at(2026, 10, 5, 13, 38), at(2026, 10, 5, 15, 38))])
  assert.equal(unitNote(units[0], later, true, now).text, 'Free · next at 1:38 PM')
  const during = toTrips([trip(4, 'Booked', 1, at(2026, 10, 5, 13), at(2026, 10, 5, 15))])
  assert.equal(unitNote(units[0], during, true, now).text, 'On a trip until 3:00 PM')
})
