// "Requests by month and service": which services get a colour of their own.
//
// Colours go by volume, not by name or id. A fixed colour per service cannot
// work once there are more services than colours: two services sharing one
// would both show whenever both are busy. Ranking guarantees every visible
// segment its own colour. A service with no requests in the range has no
// series at all, so adding a service changes nothing until it is used.

/** Requests filed with no service. Never a colour of its own: it is not a service. */
export const OTHERS = 'Others'

const total = (series) => series.data.reduce((a, b) => a + b, 0)

/**
 * @param {{ label: string, data: number[] }[]} series one per service, plus Others
 * @param {string[]} palette the categorical colours, best first
 * @param {string} grey the neutral colour for everything folded together
 * @returns {{ chart: { label: string, data: number[], color: string }[], colorOf: (label: string) => string }}
 *   `chart` is what the stacked bar draws; `colorOf` gives the dot for any
 *   series, folded ones included, so the table can keep a row per service.
 */
export function foldVolume(series, palette, grey) {
  const services = series.filter((s) => s.label !== OTHERS)
  const others = series.find((s) => s.label === OTHERS)

  // Busiest first; a tie goes by name so the order never depends on the API's.
  const ranked = services.slice().sort((a, b) => total(b) - total(a) || a.label.localeCompare(b.label))

  // Every service gets a colour while they fit; past that the last colour is
  // given up so the rest can share one grey segment.
  const fits = ranked.length <= palette.length
  const shown = fits ? ranked : ranked.slice(0, palette.length - 1)
  const rest = fits ? [] : ranked.slice(palette.length - 1)

  const colors = new Map(shown.map((s, i) => [s.label, palette[i]]))
  const chart = shown.map((s, i) => ({ label: s.label, data: s.data, color: palette[i] }))

  const grouped = others ? [...rest, others] : rest
  if (grouped.length > 0) {
    const more = `${rest.length} more service${rest.length === 1 ? '' : 's'}`
    let label = more
    if (rest.length === 0) { label = OTHERS } else if (others) { label = `${OTHERS} + ${more}` }
    const months = grouped[0].data.length
    chart.push({
      label,
      data: Array.from({ length: months }, (_, i) => grouped.reduce((sum, s) => sum + (s.data[i] ?? 0), 0)),
      color: grey,
    })
    for (const s of grouped) { colors.set(s.label, grey) }
  }

  return { chart, colorOf: (label) => colors.get(label) ?? grey }
}
