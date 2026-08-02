/**
 * Token storage with a client-side expiry.
 *
 * "Remember me" only chooses how long this browser keeps the token:
 * 30 days when ticked, 8 hours when not. Read getToken() as "a token this
 * browser is still willing to use", not "a token the API still accepts".
 *
 * The server now caps an admin token at 8 hours of its own (audit #30,
 * config/sanctum.php), which is the shorter of the two, so ticking "Remember
 * me" no longer extends a working session past that — it only decides how
 * long a dead token loiters in localStorage. When the server expiry lands
 * first, the 401 interceptor in apiSession.ts clears it and returns the admin
 * to /login with a notice.
 */

const TOKEN_KEY = 'serbis_token'
const EXPIRY_KEY = 'serbis_token_expires_at'

const REMEMBERED_MS = 30 * 24 * 60 * 60 * 1000 // 30 days
const SESSION_MS = 8 * 60 * 60 * 1000 // 8 hours — one working day

export function setToken(token: string, remember: boolean): void {
  const ttl = remember ? REMEMBERED_MS : SESSION_MS
  localStorage.setItem(TOKEN_KEY, token)
  localStorage.setItem(EXPIRY_KEY, String(Date.now() + ttl))
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(EXPIRY_KEY)
}

/**
 * Returns the token, or null once it has expired. Expiry is enforced here
 * rather than at the call sites so that a stale token cannot be read back
 * by anything that forgets to check.
 */
export function getToken(): string | null {
  const token = localStorage.getItem(TOKEN_KEY)
  if (!token) return null

  const expiresAt = Number(localStorage.getItem(EXPIRY_KEY))

  // A token written before this expiry scheme existed has no timestamp.
  // Treat it as expired rather than as valid forever.
  if (!expiresAt || Number.isNaN(expiresAt) || Date.now() >= expiresAt) {
    clearToken()
    return null
  }

  return token
}

export function isAuthenticated(): boolean {
  return getToken() !== null
}
