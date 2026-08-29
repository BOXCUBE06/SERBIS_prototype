<template>
  <!-- Everything ambulance moved to the Ambulance Dispatch Requests page —
       this is now every other service, sharing the same board component so
       approve/reject/CSV export/etc. cannot drift between the two lists.
       See ServiceRequestQueue.vue. -->
  <ServiceRequestQueue scope="other" />
</template>

<script setup>
import ServiceRequestQueue from '@/components/ServiceRequestQueue.vue'
</script>
