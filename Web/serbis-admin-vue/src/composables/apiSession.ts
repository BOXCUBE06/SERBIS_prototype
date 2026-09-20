/**
 * Global handling for an expired session.
 *
 * Admin tokens now expire server-side after 8 hours (audit #30). Before that
 * they were valid forever, so a 401 could only mean "never signed in" and no
 * view needed to handle one. Now any request can come back 401 mid-session,
 * and every view calls fetch() directly and renders the error text — which
 * would show "Unauthenticated." in a red alert on a page that looks logged in,
 * with no way out but a manual reload.
 *
 * Intercepting fetch once here beats threading a handler through ~10 views:
 * there is exactly one rule, and it cannot be forgotten at a new call site.
 */

import type { Router } from 'vue-router'
import { clearToken } from './authToken'
import { API_BASE } from '../config/api'

// The login routes answer 401 for bad credentials. Bouncing on those would
// redirect the login page to itself and swallow the "wrong password" message.
const CREDENTIAL_ROUTES = ['/admin/login', '/resident/login']

let installed = false

export function installSessionExpiryHandler(router: Router): void {
  if (installed) return
  installed = true

  const originalFetch = window.fetch.bind(window)

  window.fetch = async (input, init) => {
    const response = await originalFetch(input, init)

    const url = typeof input === 'string' ? input : (input instanceof URL ? input.href : input.url)

    // A temporary password was handed out for this account: the server refuses
    // everything but /me, /logout and /admin/change-password until it is
    // replaced. Send the visitor to the one page that can fix it, whichever
    // view made the call. Read from a clone so the caller still gets its body.
    if (response.status === 403 && url.startsWith(API_BASE)) {
      const body = await response.clone().json().catch(() => null)
      if (body?.code === 'password_change_required' && router.currentRoute.value.path !== '/change-password') {
        router.push('/change-password')
      }
      return response
    }

    if (response.status !== 401) return response

    if (!url.startsWith(API_BASE)) return response
    if (CREDENTIAL_ROUTES.some((route) => url.startsWith(`${API_BASE}${route}`))) return response

    // The token is gone as far as the server is concerned; drop it here too so
    // the router guard cannot bounce the visitor straight back out of /login.
    clearToken()

    if (router.currentRoute.value.path !== '/login') {
      router.push({ path: '/login', query: { expired: '1' } })
    }

    return response
  }
}
