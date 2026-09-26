// Run: npm test
import test from 'node:test'
import assert from 'node:assert/strict'
import { findLink } from './smsSegments.ts'

// Must match App\Services\Sms\SmsMessagePolicy::LINK_PATTERN, emails included:
// "juan@example.com" contains the domain example.com, and the server refuses it.
const links = [
  ['see https://mdrrmo.echague.gov.ph/notice', 'https://'],
  ['see ' + 'http' + '://example.com', 'http://'], // split: the plain-http case is the point
  ['visit www.example.com now', 'www.'],
  ['visit example.com now', 'example.com'],
  ['open bit.ly/abc', 'bit.ly'],
  ['open tinyurl.com/x', 'tinyurl.com'],
  ['go to sub.domain.ph/path', 'domain.ph'],
  ['server 192.168.1.10', '192.168.1.10'],
  ['server 192.168.1.10:8080/status', '192.168.1.10'],
  ['mail juan@example.com', 'example.com'],
]
for (const [message, found] of links) {
  test(`flags: ${message}`, () => assert.equal(findLink(message), found))
}

const clean = [
  'Meet at 7:45 at the covered court',
  'Ration weighs 1.5 kg per family',
  'Report to St. Michael Chapel',
  'Brgy. San Fabian relief on Monday',
  'Come at 8 a.m. or 3 p.m. sharp',
  'Wait for us...',
  'Please bring your ID. Thank you.',
  'Version 1.2.3 of the plan',
  '',
]
for (const message of clean) {
  test(`does not flag: ${message || '(empty)'}`, () => assert.equal(findLink(message), null))
}
