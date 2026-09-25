// Time-window helpers for the dashboard's trend figures. Pure, so the view only
// hands them timestamps (ms).

export const DAY_MS = 86_400_000

// Last 7 days minus the 7 before them. Future timestamps count for neither.
export const weekDelta = (times, now = Date.now()) => {
  let current = 0
  let previous = 0
  for (const t of times) {
    const age = now - t
    if (age < 0) {
      continue
    }
    if (age < 7 * DAY_MS) {
      current++
    } else if (age < 14 * DAY_MS) {
      previous++
    }
  }
  return current - previous
}

// Same four buckets and edges as AnalyticsReport::openRequestAging on the
// server, so the dashboard chart and the Analytics page cannot disagree.
export const WAIT_BUCKETS = ['Under a day', '1-3 days', '3-7 days', '7+ days']

export const waitBucket = (filedAt, now = Date.now()) => {
  const days = (now - filedAt) / DAY_MS
  if (days < 1) {
    return WAIT_BUCKETS[0]
  }
  if (days < 3) {
    return WAIT_BUCKETS[1]
  }
  return days < 7 ? WAIT_BUCKETS[2] : WAIT_BUCKETS[3]
}

// Local midnights of the last n days, oldest first, today last.
export const lastDays = (n, now = new Date()) =>
  Array.from({ length: n }, (_, i) => new Date(now.getFullYear(), now.getMonth(), now.getDate() - (n - 1 - i)))

// This month so far, and the same stretch of last month, as [start, end).
// Same stretch so a month nine days old is not measured against a whole one.
export const monthWindows = (now = new Date()) => {
  const thisStart = new Date(now.getFullYear(), now.getMonth(), 1).getTime()
  const lastStart = new Date(now.getFullYear(), now.getMonth() - 1, 1).getTime()
  return {
    current: [thisStart, Infinity],
    previous: [lastStart, Math.min(lastStart + (now.getTime() - thisStart), thisStart)],
  }
}

export const within = (t, [start, end]) => t >= start && t < end

export const countByDay = (times, days) =>
  days.map((d) => times.filter((t) => t >= d.getTime() && t < d.getTime() + DAY_MS).length)
