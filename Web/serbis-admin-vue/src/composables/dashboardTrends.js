// Time-window helpers for the dashboard's trend figures. Pure, so the view only
// hands them timestamps (ms).

export const DAY_MS = 86_400_000

// Local midnights of the last n days, oldest first, today last.
export const lastDays = (n, now = new Date()) =>
  Array.from({ length: n }, (_, i) => new Date(now.getFullYear(), now.getMonth(), now.getDate() - (n - 1 - i)))

export const countByDay = (times, days) =>
  days.map((d) => times.filter((t) => t >= d.getTime() && t < d.getTime() + DAY_MS).length)
