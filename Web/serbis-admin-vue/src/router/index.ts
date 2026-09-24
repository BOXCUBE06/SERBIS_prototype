import { createRouter, createWebHistory } from 'vue-router'
import { getToken } from '../composables/authToken'
import { can, firstAllowedPath, loadCurrentAdmin } from '../composables/useCurrentAdmin'

// `meta.section` is the admin-panel section a route belongs to (see
// composables/adminSections.ts). The guard below refuses a route whose section
// the signed-in admin does not hold. The server refuses the same requests
// regardless; this is what stops the page opening on a direct link.
const routes = [
  { path: '/login', component: () => import('../views/LoginView.vue') },
  // Signed in, but shown without the shell: an account holding a temporary
  // password reaches nothing else until it has replaced it.
  { path: '/change-password', component: () => import('../views/ChangePasswordView.vue') },
  // Where an account with no sections at all lands. Not a section itself, or it
  // could not be reached by the accounts it exists for.
  { path: '/no-access', component: () => import('../views/NoAccessView.vue') },
  { path: '/', component: () => import('../views/DashboardView.vue'), meta: { section: 'dashboard' } },
  // A scrolling page, like every route except the two marked fixedHeight —
  // the sections stack and the filter bar sticks to the top of the scroll.
  { path: '/analytics', component: () => import('../views/AnalyticsView.vue'), meta: { section: 'analytics' } },
  { path: '/users', component: () => import('../views/UsersView.vue'), meta: { section: 'residents' } },
  { path: '/staff', component: () => import('../views/StaffView.vue'), meta: { section: 'staff' } },
  { path: '/services-config', component: () => import('../views/ServicesConfigView.vue'), meta: { section: 'services' } },
  { path: '/service-audience', component: () => import('../views/ServiceAudienceView.vue'), meta: { section: 'service_audience' } },
  { path: '/service-vehicles', component: () => import('../views/ServiceVehiclesView.vue'), meta: { section: 'service_vehicles' } },
  // `meta: { fixedHeight: true }` opts a route out of the shell's page
  // scrolling (App.vue). No route uses it now: the two that did held a split
  // pane, which became a modal, and inside the capped height their shared
  // DataTablePage lost its status tabs and footer to flex shrinking.
  { path: '/manage-requests', component: () => import('../views/ManageRequestView.vue'), meta: { section: 'requests' } },
  { path: '/conduction-requests', component: () => import('../views/ConductionRequestView.vue'), meta: { section: 'ambulance' } },
  { path: '/sms', component: () => import('../views/SmsView.vue'), meta: { section: 'sms' } },
  { path: '/files', component: () => import('../views/FilesView.vue'), meta: { section: 'files' } },
  { path: '/logs', component: () => import('../views/LogsView.vue'), meta: { section: 'logs' } },
  { path: '/vehicles', component: () => import('../views/VehiclesView.vue'), meta: { section: 'vehicles' } },
  { path: '/responders', component: () => import('../views/ResponderView.vue'), meta: { section: 'responders' } },
  { path: '/inventory', component: () => import('../views/EquipmentInventoryView.vue'), meta: { section: 'inventory' } },
  { path: '/borrowings', component: () => import('../views/EquipmentBorrowingView.vue'), meta: { section: 'borrowings' } },
  { path: '/procurement', component: () => import('../views/ProcurementReferenceView.vue'), meta: { section: 'procurement' } },
  // Catch-all last: without it an unknown path matched no route and rendered a
  // blank page inside the shell, which reads as a broken app rather than a bad
  // link. The guard below still bounces an unauthenticated visitor to /login,
  // so this is only ever reached by a signed-in admin.
  { path: '/:pathMatch(.*)*', component: () => import('../views/NotFoundView.vue') },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to, from, next) => {
  // getToken() returns null on an expired token and clears it, so an expired
  // session is bounced to /login on the next navigation.
  const token = getToken()
  const isAuthRoute = to.path === '/login'

  if (!token && !isAuthRoute) return next('/login')
  if (token && isAuthRoute) return next('/')

  const section = to.meta.section as string | undefined

  if (token && section) {
    await loadCurrentAdmin()

    if (!can(section)) {
      // The first page they may open, or the explanation if there is none.
      // Never the page they were refused, which would loop.
      const fallback = firstAllowedPath()
      return next(fallback && fallback !== to.path ? fallback : '/no-access')
    }
  }

  next()
})

export default router
