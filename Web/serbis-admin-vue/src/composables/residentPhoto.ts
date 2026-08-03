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
 * revoking one that a `<v-img>` is still showing blanks the avatar, so release
 * happens on unmount rather than per row.
 */

import { getToken } from './authToken'
import { API_BASE } from '../config/api'

const urls = new Map<string, string>()
const inflight = new Map<string, Promise<string | null>>()

async function load(id: string): Promise<string | null> {
  const response = await fetch(`${API_BASE}/residents/${id}/photo`, {
    headers: { Authorization: `Bearer ${getToken()}`, Accept: '*/*' },
  })

  // 404 is the ordinary answer for a resident who has not uploaded one. It is
  // also what a non-owner gets, deliberately — the route does not confirm which
  // accounts exist.
  if (!response.ok) return null

  const url = URL.createObjectURL(await response.blob())
  urls.set(id, url)
  return url
}

/**
 * The object URL for a resident's photo, or null when there is none. Repeated
 * calls for the same id reuse the first result, including while it is still in
 * flight — a table redraw must not refetch one image per row.
 */
export function residentPhotoUrl(id: string | number): Promise<string | null> {
  const key = String(id)

  const cached = urls.get(key)
  if (cached) return Promise.resolve(cached)

  const pending = inflight.get(key)
  if (pending) return pending

  const request = load(key)
    .catch(() => null)
    .finally(() => inflight.delete(key))

  inflight.set(key, request)
  return request
}

/** Drops a single resident's cached image, so the next read refetches it. */
export function forgetResidentPhoto(id: string | number): void {
  const key = String(id)
  const url = urls.get(key)
  if (url) {
    URL.revokeObjectURL(url)
    urls.delete(key)
  }
}

/**
 * Revokes every object URL. Without this each visit to the resident list would
 * leak one blob per resident for the life of the tab.
 */
export function releaseResidentPhotos(): void {
  for (const url of urls.values()) URL.revokeObjectURL(url)
  urls.clear()
}
