/**
 * What the Ambulance schedule popup works out from GET /ambulance-schedule: which
 * days to ask for, where a trip sits on a day, and the words under each unit.
 *
 * Days are 'YYYY-MM-DD' keys in the browser's local time, like the rest of the
 * panel; the server reads the same keys as Manila days.
 */

const pad = (n) => String(n).padStart(2, '0')

export const dateKey = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`

export const parseKey = (key) => {
  const [y, m, d] = key.split('-').map(Number)
  return new Date(y, m - 1, d)
}

export const addDays = (key, days) => {
  const date = parseKey(key)
  date.setDate(date.getDate() + days)
  return dateKey(date)
}

/** One month on, keeping the day of the month where the new month has it. */
export const addMonths = (key, months) => {
  const date = parseKey(key)
  const target = new Date(date.getFullYear(), date.getMonth() + months, 1)
  const last = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate()
  target.setDate(Math.min(date.getDate(), last))
  return dateKey(target)
}

/** The month containing `key`, Sunday to Saturday, with the neighbouring days that fill the first and last week. */
export const monthGrid = (key) => {
  const date = parseKey(key)
  const first = new Date(date.getFullYear(), date.getMonth(), 1)
  const lead = first.getDay()
  const length = new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate()
  const weeks = Math.ceil((lead + length) / 7)
  const days = Array.from({ length: weeks * 7 }, (_, i) => dateKey(new Date(first.getFullYear(), first.getMonth(), 1 - lead + i)))

  return { days, weeks, month: date.getMonth() }
}

/** The days to ask the server for: the whole grid, and one more each side for trips that cross midnight. */
export const fetchRange = (key) => {
  const { days } = monthGrid(key)

  return { from: addDays(days[0], -1), to: addDays(days.at(-1), 1) }
}

export const toTrips = (raw) => raw.map((trip) => ({
  ...trip,
  startMs: new Date(trip.starts_at).getTime(),
  endMs: new Date(trip.ends_at).getTime(),
}))

const dayBounds = (key) => [parseKey(key).getTime(), parseKey(addDays(key, 1)).getTime()]

/**
 * Trips on the road at any point of the day, earliest first. A request still
 * waiting (no time, no unit) belongs to today, however old, and to no other day.
 */
export const tripsOnDay = (trips, key, todayKey) => {
  const [from, until] = dayBounds(key)

  return trips
    .filter((trip) => (trip.waiting && todayKey ? key === todayKey : trip.startMs < until && trip.endMs > from))
    .sort((a, b) => a.startMs - b.startMs)
}

/** Whole days since a waiting request was filed. */
export const waitingDays = (trip, todayKey) => Math.round((parseKey(todayKey) - parseKey(dateKey(new Date(trip.startMs)))) / 86_400_000)

/** Waiting since before today: carried forward rather than drawn at its time. */
export const isCarried = (trip, todayKey) => Boolean(trip.waiting) && waitingDays(trip, todayKey) > 0

export const waitingLabel = (trip, todayKey) => `Waiting ${waitingDays(trip, todayKey)}d`

/** Where a trip sits on one day's 24 hours, in percent of the width; overlapping trips in one row go in separate lanes. */
export const layoutRow = (trips, key) => {
  const [from] = dayBounds(key)
  const lanes = []

  const placed = trips.map((trip) => {
    const startMin = Math.max(0, (trip.startMs - from) / 60_000)
    const endMin = Math.min(1440, (trip.endMs - from) / 60_000)
    let lane = lanes.findIndex((laneEnd) => laneEnd <= startMin)
    if (lane === -1) {
      lane = lanes.length
      lanes.push(0)
    }
    lanes[lane] = endMin

    return { trip, lane, leftPct: (startMin / 1440) * 100, widthPct: Math.max(0.75, ((endMin - startMin) / 1440) * 100) }
  })

  return { placed, laneCount: Math.max(1, lanes.length) }
}

const clock = (ms, short) => {
  const date = new Date(ms)
  const hours = date.getHours()
  const minutes = pad(date.getMinutes())
  const base = `${hours % 12 || 12}:${minutes}`

  return short ? `${base}${hours < 12 ? 'a' : 'p'}` : `${base} ${hours < 12 ? 'AM' : 'PM'}`
}

/** 1:30 PM */
export const fmtTime = (ms) => clock(ms, false)

/** 1:30p */
export const shortTime = (ms) => clock(ms, true)

/** 'Rosa Pascual' is 'R. Pascual'. */
export const shortName = (name) => {
  const parts = (name || '').trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) {return 'Unknown'}

  return parts.length === 1 ? parts[0] : `${parts[0][0]}. ${parts.slice(1).join(' ')}`
}

export const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`

/** 'AMB-03' is '03'; a trip with no unit yet is '--'. */
export const unitNumber = (unit) => (unit ? unit.unit_identifier.split('-').pop() : '--')

/**
 * The trip a unit is on now. Responding means out, whatever the clock says; a
 * Booked trip counts only while its window covers now. Resolved never does.
 */
export const currentTrip = (unit, trips, nowMs) => {
  const mine = trips.filter((trip) => trip.vehicle_id === unit.vehicle_id)

  return mine.filter((trip) => trip.status === 'Responding').sort((a, b) => b.startMs - a.startMs)[0]
    ?? mine.find((trip) => trip.status === 'Booked' && trip.startMs <= nowMs && nowMs < trip.endMs)
    ?? null
}

/**
 * The small status line under a unit's name. `dayTrips` are its trips on the day
 * shown; `allTrips` every trip loaded, so a unit out since yesterday still reads as out.
 */
export const unitNote = (unit, dayTrips, isToday, nowMs, allTrips = dayTrips) => {
  if (unit.is_maintenance) {return { text: 'Under maintenance', tone: 'idle' }}

  if (!isToday) {
    return { text: dayTrips.length ? plural(dayTrips.length, 'trip') : 'No trips', tone: dayTrips.length ? 'busy-day' : 'idle' }
  }

  const current = currentTrip(unit, allTrips, nowMs)
  // An end already behind us is an overrun, not a promise: no "until".
  if (current) {return { text: current.endMs > nowMs ? `On a trip until ${fmtTime(current.endMs)}` : 'On a trip', tone: 'busy' }}

  const next = dayTrips.find((trip) => trip.startMs > nowMs)

  return { text: next ? `Free · next at ${fmtTime(next.startMs)}` : 'Free now', tone: 'free' }
}

export const unassignedNote = (unassigned) => {
  if (unassigned.length === 0) {return { text: 'Nothing waiting', tone: 'idle' }}

  return {
    text: unassigned.every((trip) => trip.status === 'Pending')
      ? plural(unassigned.length, 'pending request')
      : `${unassigned.length} awaiting a unit`,
    tone: 'pending',
  }
}

/** The toolbar line in Day view. */
export const daySummary = (dayTrips, units, isToday, nowMs, allTrips = dayTrips) => {
  const none = dayTrips.filter((trip) => !trip.vehicle_id).length
  const busy = (unit) => currentTrip(unit, allTrips, nowMs) !== null

  // Today always counts who is free now: a unit out since yesterday is out on an empty day too.
  const freeNow = `${units.filter((unit) => !unit.is_maintenance && !busy(unit)).length} of ${plural(units.length, 'unit')} free now`

  if (dayTrips.length === 0) {return isToday ? `No trips · ${freeNow}` : `No trips · all ${plural(units.length, 'unit')} free`}

  const bits = [plural(dayTrips.length, 'trip')]
  if (none) {bits.push(`${none} unassigned`)}
  if (isToday) {
    bits.push(freeNow)
  } else {
    bits.push(`${units.filter((unit) => dayTrips.some((trip) => trip.vehicle_id === unit.vehicle_id)).length} of ${plural(units.length, 'unit')} booked`)
  }

  return bits.join(' · ')
}

/** One cell per day of the grid. */
export const monthCells = (trips, units, key, todayKey) => {
  const { days, month } = monthGrid(key)

  return days.map((day) => {
    const dayTrips = tripsOnDay(trips, day, todayKey)
    const [from] = dayBounds(day)

    return {
      key: day,
      date: parseKey(day).getDate(),
      inMonth: parseKey(day).getMonth() === month,
      isToday: day === todayKey,
      trips: dayTrips,
      bars: units.map((unit) => dayTrips.some((trip) => trip.vehicle_id === unit.vehicle_id)),
      chips: dayTrips.slice(0, 2).map((trip) => ({
        trip,
        // Carried forward: how long it has waited, not a time of day.
        time: isCarried(trip, todayKey) ? `${waitingDays(trip, todayKey)}d` : shortTime(Math.max(trip.startMs, from)),
        name: shortName(trip.patient_name),
        unit: unitNumber(units.find((unit) => unit.vehicle_id === trip.vehicle_id)),
      })),
      more: Math.max(0, dayTrips.length - 2),
    }
  })
}

/** The toolbar line in Month view: counts only the days of the month itself. */
export const monthSummary = (cells) => {
  const seen = new Map()
  cells.filter((cell) => cell.inMonth).forEach((cell) => cell.trips.forEach((trip) => seen.set(trip.request_id, trip)))
  const trips = [...seen.values()]
  if (trips.length === 0) {return 'No trips this month'}

  const none = trips.filter((trip) => !trip.vehicle_id).length

  return `${plural(trips.length, 'trip')} this month${none ? ` · ${none} still unassigned` : ''}`
}
