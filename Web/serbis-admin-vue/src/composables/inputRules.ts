/**
 * What each kind of field may hold, in one place. Mirrors the server's
 * App\Rules\Charset / App\Support\FieldRules (the source of truth) and the
 * mobile app's validators.dart, so the three layers agree.
 *
 * A kind has a keystroke filter (`filters`, applied by the v-filter directive
 * and by EditDialog's `filter`), a length (`limits`) and a blur rule
 * (`rules`). Rules return true or a plain, field-specific message.
 */

export type FieldKind = 'name' | 'phone' | 'age' | 'address' | 'label' | 'hotline' | 'identifier' | 'email' | 'note'

// Allowed characters, spelled once. `\p{L}` takes ñ/Ñ and other accented letters.
const CHARS = {
  name: String.raw`\p{L} .'’-`,
  address: String.raw`\p{L}\p{N} ,.\-#/`,
  label: String.raw`\p{L}\p{N} .,\-&'’()`,
  hotline: String.raw`\p{L}\p{N} .,\-&'’()/`,
  identifier: String.raw`A-Za-z0-9 ()\-`,
}

export const limits: Record<FieldKind, number> = {
  name: 50, phone: 11, age: 3, address: 150, label: 100, hotline: 100, identifier: 50, email: 100, note: 500,
}

const only = (chars: string) => new RegExp(`^[${chars}]*$`, 'u')
const strip = (chars: string) => new RegExp(`[^${chars}]`, 'gu')

// eslint-disable-next-line no-control-regex -- stripping control characters is the point
const CONTROL_OR_TAG =/[\u0000-\u0008\v\f\u000E-\u001F\u007F<>]/g

/** Keystroke and paste filter per kind: what is typed or pasted that does not belong is dropped. */
export const filters: Record<FieldKind, (value: string) => string> = {
  name: (v) => v.replace(strip(CHARS.name), ''),
  phone: (v) => v.replace(/\D/g, '').slice(0, limits.phone),
  age: (v) => v.replace(/\D/g, '').slice(0, limits.age),
  address: (v) => v.replace(strip(CHARS.address), ''),
  label: (v) => v.replace(strip(CHARS.label), ''),
  hotline: (v) => v.replace(strip(CHARS.hotline), ''),
  identifier: (v) => v.replace(strip(CHARS.identifier), ''),
  email: (v) => v.replace(/\s/g, ''),
  note: (v) => v.replace(CONTROL_OR_TAG, ''),
}

/** First letter upper-cased; used on display for records saved before the server did it. */
export const sentenceCase = (v: unknown): string => {
  const s = typeof v === 'string' ? v : ''
  return s ? s.charAt(0).toLocaleUpperCase() + s.slice(1) : s
}

type Rule = (value: any) => true | string

const blank = (v: unknown) => v === null || v === undefined || String(v).trim() === ''

export const rules = {
  required: (label: string): Rule => (v) => !blank(v) || `${label} is required.`,

  name: (label = 'Name'): Rule => (v) => {
    if (blank(v)) {return true}
    const s = String(v).trim()
    if (!only(CHARS.name).test(s)) {return `${label} can only contain letters, spaces, hyphens, apostrophes and periods.`}
    if (s.length < 2) {return `${label} must be at least 2 letters.`}
    return s.length <= limits.name || `${label} must be ${limits.name} characters or fewer.`
  },

  phone: (label = 'Mobile number'): Rule => (v) =>
    blank(v) || /^09\d{9}$/.test(String(v)) || `${label} must be 11 digits and start with 09.`,

  age: (label = 'Age'): Rule => (v) => {
    if (blank(v)) {return true}
    const s = String(v)
    return (/^\d{1,3}$/.test(s) && Number(s) <= 120) || `${label} must be a whole number from 0 to 120.`
  },

  address: (label = 'Address'): Rule => (v) => {
    if (blank(v)) {return true}
    const s = String(v).trim()
    if (!only(CHARS.address).test(s)) {return `${label} can only contain letters, numbers, spaces and , . - # /`}
    return s.length <= limits.address || `${label} must be ${limits.address} characters or fewer.`
  },

  label: (label = 'Name', max = limits.label): Rule => (v) => {
    if (blank(v)) {return true}
    const s = String(v).trim()
    if (!only(CHARS.label).test(s)) {return `${label} can only contain letters, numbers, spaces and . , - & ' ( )`}
    if (s.length < 2) {return `${label} must be at least 2 characters.`}
    return s.length <= max || `${label} must be ${max} characters or fewer.`
  },

  hotline: (label = 'Name', max = limits.hotline): Rule => (v) => {
    if (blank(v)) {return true}
    const s = String(v).trim()
    if (!only(CHARS.hotline).test(s)) {return `${label} can only contain letters, numbers, spaces and . , - & ' ( ) /`}
    if (s.length < 2) {return `${label} must be at least 2 characters.`}
    return s.length <= max || `${label} must be ${max} characters or fewer.`
  },

  identifier: (label = 'This field', max = limits.identifier): Rule => (v) => {
    if (blank(v)) {return true}
    const s = String(v).trim()
    if (!only(CHARS.identifier).test(s)) {return `${label} can only contain letters, numbers, spaces, hyphens and parentheses.`}
    return s.length <= max || `${label} must be ${max} characters or fewer.`
  },

  email: (label = 'Email'): Rule => (v) =>
    blank(v) || (/^[^\s@]+@[^\s@.]+(?:\.[^\s@.]+)+$/.test(String(v).trim()) && String(v).trim().length <= limits.email) || `${label} must be a valid email of ${limits.email} characters or fewer.`,

  note: (label = 'This field', max = limits.note): Rule => (v) =>
    blank(v) || String(v).trim().length <= max || `${label} must be ${max} characters or fewer.`,
}
