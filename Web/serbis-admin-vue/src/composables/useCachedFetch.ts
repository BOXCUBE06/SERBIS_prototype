/**
 * Stale-while-revalidate GET for admin pages.
 *
 * Every page used to open on a skeleton and refetch from scratch, even for a
 * list it had shown a second ago. `get()` hands back what it already holds at
 * once (`onData`), then fetches again unless the copy is younger than `ttl`
 * and calls `onData` a second time with the fresh body. `loading` is true only
 * while a request has nothing cached to show; `refreshing` while one is
 * revalidating behind rows already on screen.
 *
 * Paths are relative to API_BASE, the same string for `get()` and `invalidate()`.
 * After a write, `invalidate()` the path first so the refetch cannot flash the
 * pre-write copy. The cache belongs to one token: a new sign-in, `clearAll()`
 * on logout and `clearAll()` on a 401 each drop it, so one admin's lists never
 * reach the next.
 */
import { computed, ref } from 'vue'
import { authHeaders } from '@/composables/adminUi'
import { getToken } from '@/composables/authToken'
import { API_BASE } from '@/config/api'

/** Lists that change rarely and several pages read: cached copies younger than this skip the refetch. */
export const REFERENCE_TTL_MS = 60_000

// Logs and Analytics key on their filters, so the cache needs a ceiling; the oldest entry goes first.
const MAX_ENTRIES = 100

const cache = new Map<string, { body: unknown; at: number }>()
const inflight = new Map<string, Promise<unknown>>()
// Bumped by every invalidate: a response that started before it is not cached.
let generation = 0
let owner: string | null = null
const clearHooks: Array<() => void> = []

/** Runs `hook` whenever the cache is cleared: for data held elsewhere that must not outlive a session. */
export function onClear(hook: () => void): void {
  clearHooks.push(hook)
}

export function clearAll(): void {
  cache.clear()
  inflight.clear()
  generation++
  owner = null
  clearHooks.forEach((hook) => hook())
}

/** Drops every cached path starting with `path`, and stops a running request from refilling it. */
export function invalidate(path: string): void {
  const prefix = API_BASE + path
  for (const map of [cache, inflight]) {
    for (const key of map.keys()) {
      if (key.startsWith(prefix)) {map.delete(key)}
    }
  }
  generation++
}

async function request(url: string): Promise<unknown> {
  const started = generation
  const res = await fetch(url, { headers: authHeaders() })
  const body = await res.json().catch(() => null)

  if (!res.ok) {
    const message = (body as { message?: string } | null)?.message ?? `Request failed (${res.status})`
    throw Object.assign(new Error(message), { status: res.status })
  }

  if (started === generation) {
    cache.set(url, { body, at: Date.now() })
    if (cache.size > MAX_ENTRIES) {cache.delete(cache.keys().next().value as string)}
  }
  return body
}

export function useCachedFetch() {
  const cold = ref(0)
  const warm = ref(0)
  const loading = computed(() => cold.value > 0)
  const refreshing = computed(() => cold.value === 0 && warm.value > 0)

  async function get<T = unknown>(
    path: string,
    { ttl = 0, fresh = false, onData }: { ttl?: number; fresh?: boolean; onData?: (body: T) => void } = {},
  ): Promise<T> {
    const url = API_BASE + path
    const token = getToken()
    if (token !== owner) {
      clearAll()
      owner = token
    }

    // `fresh`: skip the cached copy (a write just made it wrong) but still count as a
    // refresh, not a first load, so the page dims its rows instead of falling back to skeletons.
    const hit = fresh ? undefined : cache.get(url)
    if (hit) {
      onData?.(hit.body as T)
      if (Date.now() - hit.at < ttl) {return hit.body as T}
    }

    const pending = hit || fresh ? warm : cold
    pending.value++
    try {
      // Two callers asking for the same URL share one request.
      let shared = inflight.get(url)
      if (!shared) {
        const created: Promise<unknown> = request(url).finally(() => {
          if (inflight.get(url) === created) {inflight.delete(url)}
        })
        inflight.set(url, created)
        shared = created
      }

      const body = (await shared) as T
      onData?.(body)
      return body
    } finally {
      pending.value--
    }
  }

  return { get, loading, refreshing }
}
