// Lets `node --test` import app modules the way Vite does: the `@/` alias,
// extensionless relative imports, and .vue files (stubbed, never rendered).
// config/api.ts reads import.meta.env, which only exists under Vite, so it is
// swapped for a fixed address. Loaded by `npm test` through --import.
import { registerHooks } from 'node:module'
import { statSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const src = fileURLToPath(new URL('..', import.meta.url))
const EXTENSIONS = ['.ts', '.js', '.mjs']
const apiStub = new URL('./api-stub.mjs', import.meta.url).href

const isFile = (p) => {
  try {
    return statSync(p).isFile()
  } catch {
    return false
  }
}

// base, or base plus the first extension that exists; null when neither does.
const withExtension = (base) => [base, ...EXTENSIONS.map((e) => base + e)].find(isFile) ?? null

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier.endsWith('.vue')) return { url: 'data:text/javascript,export default {}', shortCircuit: true }
    if (/(^@|\.\.?)\/(config\/)?api$/.test(specifier) && specifier.includes('config')) return { url: apiStub, shortCircuit: true }

    let base = null
    if (specifier.startsWith('@/')) base = path.join(src, specifier.slice(2))
    else if (specifier.startsWith('.') && context.parentURL?.startsWith('file:')) {
      base = path.resolve(path.dirname(fileURLToPath(context.parentURL)), specifier)
    }

    const found = base && withExtension(base)

    return found ? { url: pathToFileURL(found).href, shortCircuit: true } : nextResolve(specifier, context)
  },
})
