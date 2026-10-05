// What staff still have to do after adding a service, which is saved switched
// off. Each step links to its page only when this account may open it.

/**
 * @param {{ category?: string }} service the service as the API returned it
 * @param {(section: string) => boolean} can useCurrentAdmin's section check
 */
export function nextSteps(service, can) {
  const steps = [
    {
      key: 'audience',
      text: 'Choose which account types can request it.',
      link: can('service_audience') ? { to: '/service-audience', label: 'Service Audience' } : null,
      fallback: 'Ask someone with access to Service Audience.',
    },
  ]

  // Programs are approved without a unit; the vehicle page leaves them out.
  if (service.category !== 'programs') {
    steps.push({
      key: 'vehicles',
      text: 'If a unit goes out for it, choose which vehicle types fit. With none chosen, any unit except an ambulance can be assigned.',
      link: can('service_vehicles') ? { to: '/service-vehicles', label: 'Service Vehicles' } : null,
      fallback: 'Ask someone with access to Service Vehicles.',
    })
  }

  steps.push({ key: 'enable', text: 'Then switch it on with the Enable button on its row, so residents can request it.', link: null, fallback: '' })
  return steps
}
