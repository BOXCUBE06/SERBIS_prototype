import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  { path: '/login', component: () => import('../views/LoginView.vue') },
  { path: '/register', component: () => import('../views/RegisterView.vue') },
  { path: '/', component: () => import('../views/DashboardView.vue') },
  { path: '/users', component: () => import('../views/UsersView.vue') },
  { path: '/services-config', component: () => import('../views/ServicesConfigView.vue') },
  { path: '/manage-requests', component: () => import('../views/ManageRequestView.vue') },
  { path: '/sms', component: () => import('../views/SmsView.vue') },
  { path: '/files', component: () => import('../views/FilesView.vue') },
  { path: '/logs', component: () => import('../views/LogsView.vue') },
  { path: '/vehicles', component: () => import('../views/VehiclesView.vue') },
  { path: '/inventory', component: () => import('../views/EquipmentInventoryView.vue') },
  { path: '/borrowings', component: () => import('../views/EquipmentBorrowingView.vue') },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('serbis_token')
  const isAuthRoute = to.path === '/login' || to.path === '/register'

  if (!token && !isAuthRoute) return next('/login')
  if (token && isAuthRoute) return next('/')
  next()
})

export default router