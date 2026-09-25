<!--
  SkeletonRows.vue

  First-load placeholder for a v-data-table body (its #body slot). Real
  <tr>/<td>, so the rows take the table's own row height and column widths.
-->
<template>
  <tr v-for="r in rows" :key="r" aria-hidden="true">
    <td v-for="c in columns" :key="c">
      <span class="skel skel-line" :style="{ width: `${45 + ((r * 7 + c * 13) % 40)}%` }"></span>
    </td>
  </tr>
</template>

<script setup>
defineProps({
  rows: { type: Number, default: 5 },
  columns: { type: Number, required: true },
})
</script>
