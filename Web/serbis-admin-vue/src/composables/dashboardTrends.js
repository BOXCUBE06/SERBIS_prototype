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
