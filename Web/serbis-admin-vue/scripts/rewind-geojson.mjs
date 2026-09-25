// One-off: rewinds src/assets/echague-barangays.json for d3-geo.
// RFC 7946 puts exterior rings counterclockwise; d3-geo reads that as the whole
// globe minus the polygon, so fitting the collection fits the world. A feature
// with geoArea above 2*PI (half the sphere) is the inverted one: reverse its rings.
// Run: node scripts/rewind-geojson.mjs
import { readFileSync, writeFileSync } from 'node:fs'
import { geoArea } from 'd3-geo'

const file = new URL('../src/assets/echague-barangays.json', import.meta.url)
const collection = JSON.parse(readFileSync(file, 'utf8'))

const reverseRings = (geometry) => {
  const rewind = (rings) => rings.map((ring) => ring.toReversed())
  geometry.coordinates = geometry.type === 'Polygon'
    ? rewind(geometry.coordinates)
    : geometry.coordinates.map((polygon) => rewind(polygon))
}

let fixed = 0
for (const feature of collection.features) {
  if (geoArea(feature) > 2 * Math.PI) {
    reverseRings(feature.geometry)
    fixed++
  }
}

writeFileSync(file, JSON.stringify(collection))
console.log(`Reversed ${fixed} of ${collection.features.length} features.`)
