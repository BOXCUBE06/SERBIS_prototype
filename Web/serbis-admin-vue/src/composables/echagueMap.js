// Projects the Echague boundary collection to SVG paths. Pure, so a node test
// can run it on the same file the map draws.
import { geoMercator, geoPath } from 'd3-geo'

const W = 600
const PAD = 8

// d3-geo wants clockwise exterior rings; scripts/rewind-geojson.mjs fixed the
// file, and echague-barangays.test.js fails if it ever regresses.
export const buildEchagueMap = (collection) => {
  const projection = geoMercator().fitWidth(W - PAD * 2, collection)
  const [tx, ty] = projection.translate()
  projection.translate([tx + PAD, ty + PAD])
  const toPath = geoPath(projection)
  const [[, y0], [, y1]] = toPath.bounds(collection)

  return {
    W,
    H: Math.ceil(y1 - y0) + PAD * 2,
    toPath,
    shapes: collection.features.map((f) => ({
      code: f.properties.psgc_code,
      name: f.properties.name,
      d: toPath(f),
    })),
  }
}
