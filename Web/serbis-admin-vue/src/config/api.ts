/**
 * The one place the backend's address is written down.
 *
 * Every API call in the panel goes through this. It used to be a
 * `http://localhost:8000/api` literal repeated twenty times across twelve
 * files, so a deployed build talked to a machine that only exists on a
 * developer's laptop — and missing one of the twenty would have left half the
 * panel pointing at production and half at localhost.
 *
 * Vite inlines `import.meta.env.*` when the bundle is produced, not when it
 * runs, so this is a build-time value: `.env.development` supplies it for
 * `npm run dev`, and a production build has to be handed `VITE_API_BASE` by
 * whatever runs it. There is deliberately no localhost fallback — a fallback
 * is how a bundle quietly ships pointing at nothing.
 */
const base = import.meta.env.VITE_API_BASE

if (!base) {
  throw new Error(
    'VITE_API_BASE is not set. For local work copy .env.example to .env; for a ' +
    'deployed build set it in the host\'s build environment. It is the API root, ' +
    'including /api — e.g. http://localhost:8000/api',
  )
}

// A trailing slash here would make every caller's `${API_BASE}/vehicles` into
// `//vehicles`, which some proxies treat as a protocol-relative URL.
export const API_BASE = base.replace(/\/+$/, '')
