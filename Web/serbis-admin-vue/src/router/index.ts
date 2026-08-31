import { createRouter, createWebHistory } from 'vue-router'
import { getToken } from '../composables/authToken'

const routes = [
  { path: '/login', component: () => import('../views/LoginView.vue') },
  { path: '/', component: () => import('../views/DashboardView.vue') },
  { path: '/users', component: () => import('../views/UsersView.vue') },
  { path: '/staff', component: () => import('../views/StaffView.vue') },
  { path: '/services-config', component: () => import('../views/ServicesConfigView.vue') },
  // `fixedHeight` opts a route out of the shell's page scrolling — a
  // fixed-height split pane that scrolls inside its own columns instead,
  // matching a native app screen rather than a document. Every other view
  // is a scrolling page (`align-start` + `min-height: 100vh`, or no
  // `fill-height` at all), so making the shell `overflow: hidden` globally
  // would clip the rest of them.
  { path: '/manage-requests', component: () => import('../views/ManageRequestView.vue'), meta: { fixedHeight: true } },
  // Both tabs (Bookings' split pane, Trip Logs' table) now match
  // /manage-requests' canvas instead of scrolling the page underneath them
  // (layout redesign follow-up, 2026-08-31: the page-scroll approach tried
  // first fought Vuetify's own sticky-positioning internals and still left
  // the action bar reachable only by scrolling on a tall request).
  { path: '/conduction-requests', component: () => import('../views/ConductionRequestView.vue'), meta: { fixedHeight: true } },
  { path: '/sms', component: () => import('../views/SmsView.vue') },
  { path: '/files', component: () => import('../views/FilesView.vue') },
  { path: '/logs', component: () => import('../views/LogsView.vue') },
  { path: '/vehicles', component: () => import('../views/VehiclesView.vue') },
  { path: '/inventory', component: () => import('../views/EquipmentInventoryView.vue') },
  { path: '/borrowings', component: () => import('../views/EquipmentBorrowingView.vue') },
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

router.beforeEach((to, from, next) => {
  // getToken() returns null on an expired token and clears it, so an expired
  // session is bounced to /login on the next navigation.
  const token = getToken()
  const isAuthRoute = to.path === '/login'

  if (!token && !isAuthRoute) return next('/login')
  if (token && isAuthRoute) return next('/')
  next()
})

export default router