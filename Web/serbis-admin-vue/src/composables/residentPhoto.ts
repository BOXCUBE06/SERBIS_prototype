/**
 * Resident profile photos, fetched with the admin's bearer token.
 *
 * The column used to be a free-form string an admin could type, bound straight
 * into `<v-img :src>` — so opening the resident list fetched whatever URL had
 * been entered, from every admin's browser. It is now a path on the private
 * disk, served only by `GET /residents/{id}/photo` behind `auth:sanctum`.
 *
 * That route needs an Authorization header, and an `<img>` element does not
 * send one. So the bytes are fetched here and handed to the template as an
 * object URL. Object URLs are held until `releaseResidentPhotos()` runs —
 * revoking one that a `<v-img>` is still showing blanks the avatar, so they are
 * kept across page visits and released only when the session ends (clearAll in
 * useCachedFetch: logout, 401, a different token).
 */

import { getToken } from './authToken'
import { onClear } from './useCachedFetch'
import { API_BASE } from '../config/api'

// `version` is the resident row's updated_at: a changed row refetches its photo, so a
// photo replaced on another admin's screen does not stay stale here.
const urls = new Map<string, { url: string; version: string }>()
const inflight = new Map<string, Promise<string | null>>()

async function load(id: string, version: string): Promise<string | null> {
  const response = await fetch(`${API_BASE}/residents/${id}/photo`, {
    headers: { Authorization: `Bearer ${getToken()}`, Accept: '*/*' },
  })

  // 404 is the ordinary answer for a resident who has not uploaded one. It is
  // also what a non-owner gets, deliberately — the route does not confirm which
  // accounts exist.
  if (!response.ok) return null

  const url = URL.createObjectURL(await response.blob())
  urls.set(id, { url, version })
  return url
}

/**
 * The object URL for a resident's photo, or null when there is none. Repeated
 * calls for the same id reuse the first result, including while it is still in
 * flight — a table redraw must not refetch one image per row.
 */
export function residentPhotoUrl(id: string | number, version = ''): Promise<string | null> {
  const key = String(id)

  const cached = urls.get(key)
  if (cached?.version === version) return Promise.resolve(cached.url)
  // Superseded copy: dropped now, but a `<v-img>` may still be showing it for a beat.
  if (cached) forgetResidentPhoto(key)

  const pending = inflight.get(key)
  if (pending) return pending

  const request = load(key, version)
    .catch(() => null)
    .finally(() => inflight.delete(key))

  inflight.set(key, request)
  return request
}

/** Drops a single resident's cached image, so the next read refetches it. */
export function forgetResidentPhoto(id: string | number): void {
  const key = String(id)
  const cached = urls.get(key)
  if (cached) {
    URL.revokeObjectURL(cached.url)
    urls.delete(key)
  }
}

/** Revokes every object URL: they are held for the session, so this runs when it ends. */
export function releaseResidentPhotos(): void {
  for (const { url } of urls.values()) URL.revokeObjectURL(url)
  urls.clear()
}

onClear(releaseResidentPhotos)
