<!--
  ChartDataTable.vue

  A Chart.js canvas carries no text a screen reader can read — the data is
  paint, not DOM. This renders the same numbers as a real, visually-hidden
  table (Vuetify's `d-sr-only`, not `display: none`, so it stays in the
  accessibility tree) placed next to the canvas it describes.

  `series` is the shared {label, data} shape every chart on the Analytics
  page already builds for Chart.js — pass the raw, non-normalised numbers
  even where the chart itself shows a percentage, so the table states the
  real counts rather than repeating a transform.
-->
<template>
  <table class="d-sr-only">
    <caption>{{ caption }}</caption>
    <thead>
      <tr>
        <th scope="col">{{ categoryLabel }}</th>
        <th v-for="s in series" :key="s.label" scope="col">{{ s.label }}</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="(label, i) in labels" :key="label">
        <th scope="row">{{ label }}</th>
        <td v-for="s in series" :key="s.label">{{ s.data[i] }}</td>
      </tr>
    </tbody>
  </table>
</template>

<script setup>
defineProps({
  caption: { type: String, required: true },
  categoryLabel: { type: String, required: true },
  labels: { type: Array, required: true },
  series: { type: Array, required: true },
})
</script>
