// The router guard against the real router: who is sent where. The server
// refuses these requests regardless; this is what stops a page opening on a
// direct link. Browser globals are stubbed before the router is imported.
import test, { before, beforeEach } from 'node:test'
import assert from 'node:assert/strict'

const store = new Map()
globalThis.localStorage = {
  getItem: (k) => store.get(k) ?? null,
  setItem: (k, v) => store.set(k, String(v)),
  removeItem: (k) => store.delete(k),
}
const history = { state: {}, scrollRestoration: 'auto', pushState() {}, replaceState() {} }
const location = { pathname: '/', search: '', hash: '', origin: 'http://panel.test', href: 'http://panel.test/' }
globalThis.document = { addEventListener() {}, removeEventListener() {}, querySelector: () => null, createElement: () => ({ style: {}, content: {} }) }
globalThis.history = history
globalThis.location = location
globalThis.window = { history, location, addEventListener() {}, removeEventListener() {}, scrollX: 0, scrollY: 0 }

// What /me answers for the signed-in account.
let me = { sections: [], user: {} }
globalThis.fetch = async () => ({ ok: true, json: async () => me })

let router
let setToken
let n = 0

before(async () => {
  ;({ default: router } = await import('./index.ts'))
  ;({ setToken } = await import('../composables/authToken.ts'))
})

beforeEach(() => store.clear())

// A fresh token each time: the guard keeps /me per token.
async function visit(path, account) {
  if (account) {
    me = account
    setToken(`token-${++n}`, false)
  }
  await router.push(path).catch(() => {})

  return router.currentRoute.value.path
}

test('no token sends any page to /login', async () => {
  assert.equal(await visit('/users'), '/login')
})

test('a signed-in user is kept off /login', async () => {
  const account = { sections: ['residents'], user: {} }
  await visit('/files', account)
  assert.equal(await visit('/login'), '/users')
})

test('a user holding no sections is sent to /no-access', async () => {
  assert.equal(await visit('/hotlines', { sections: [], user: {} }), '/no-access')
})

test('a user holding the section opens the page', async () => {
  assert.equal(await visit('/files', { sections: ['files'], user: {} }), '/files')
})

test('a user without the section is sent to the first page they may open', async () => {
  assert.equal(await visit('/hotlines', { sections: ['dashboard'], user: {} }), '/')
})

test('Staff Accounts is refused unless /me lists it', async () => {
  assert.equal(await visit('/staff', { sections: ['logs'], user: { is_super_admin: false } }), '/logs')
})

test('a super admin opens Staff Accounts', async () => {
  const all = ['dashboard', 'staff', 'logs']
  assert.equal(await visit('/staff', { sections: all, user: { is_super_admin: true } }), '/staff')
})

test('an expired token is treated as signed out', async () => {
  setToken('stale', false)
  store.set('serbis_token_expires_at', String(Date.now() - 1000))

  assert.equal(await visit('/users'), '/login')
})
