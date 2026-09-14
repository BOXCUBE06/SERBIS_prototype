/**
 * How many SMS segments a message will actually bill, and in which encoding.
 *
 * The Text Blast page used to show a plain `counter="160"` and validate
 * `length <= 160`. That is not what PhilSMS charges on. A GSM-7 message bills
 * one segment up to 160 characters, but a single character outside the GSM-7
 * alphabet — one `ñ`, one curly quote pasted out of Word, one emoji — moves the
 * whole message to UCS-2, where a segment holds 70 characters instead. A
 * 160-character message that has left GSM-7 bills three segments, at three
 * times the price, with nothing on screen saying so.
 *
 * There is no sandbox to check this against (see PhilSms.php), so this is an
 * estimate derived from the GSM 03.38 tables rather than something read back
 * from the vendor. It matches what every SMS gateway documents, but treat the
 * number as "what this should cost" rather than as a receipt.
 */

/**
 * The GSM 03.38 basic character set — one septet each.
 *
 * Written out rather than expressed as a regex range because it is not a
 * range: it interleaves ASCII with a specific handful of Greek capitals and
 * accented Latin letters, and skips others that look like they belong. `Á` is
 * not in it; `Ä` is. Guessing at the membership is the whole failure mode this
 * file exists to prevent.
 *
 * 0x1B (ESC) is deliberately absent — it is the escape byte for the extension
 * table below, not a character anybody can type.
 */
const GSM7_BASIC = new Set(
  (
    '@£$¥èéùìòÇ\nØø\rÅå' +
    'Δ_ΦΓΛΩΠΨΣΘΞÆæßÉ' +
    ' !"#¤%&\'()*+,-./' +
    '0123456789:;<=>?' +
    '¡ABCDEFGHIJKLMNO' +
    'PQRSTUVWXYZÄÖÑÜ§' +
    '¿abcdefghijklmno' +
    'pqrstuvwxyzäöñüà'
  ).split(''),
)

/**
 * The GSM 03.38 extension table. These stay inside GSM-7 — they do not force
 * Unicode — but each one is sent as ESC + the character, so it costs two
 * septets, not one.
 *
 * Worth knowing because `€` and `[` are ordinary things to type: twelve of
 * them silently consume twenty-four of the 160.
 */
const GSM7_EXTENDED = new Set(['^', '{', '}', '\\', '[', '~', ']', '|', '€', '\f'])

/** A GSM-7 message bills one segment up to here. */
const GSM7_SINGLE = 160
/**
 * Past one segment, each part gives up six septets to the concatenation header
 * that tells the handset how to reassemble them — so a two-segment message is
 * 306 septets of room, not 320.
 */
const GSM7_MULTIPART = 153

/** The same two numbers in UTF-16 code units, once the message has left GSM-7. */
const UCS2_SINGLE = 70
const UCS2_MULTIPART = 67

export type SmsEncoding = 'GSM-7' | 'UCS-2'

export interface SmsSegmentInfo {
  /** Which alphabet the whole message is sent in — one bad character decides it. */
  encoding: SmsEncoding
  /** Billed segments. 0 for an empty message: nothing is sent, nothing is charged. */
  segments: number
  /** Septets under GSM-7, UTF-16 code units under UCS-2. Not the same as `length`. */
  units: number
  /** How many units this encoding allows before the next segment starts. */
  capacity: number
  /** Units still free inside the last segment. */
  remaining: number
  /**
   * The distinct characters that forced UCS-2, in the order they first appear.
   * Empty under GSM-7. This is what lets the page say *which* character cost
   * the money instead of just reporting that something did.
   */
  offendingCharacters: string[]
}

/**
 * Counts what one message costs.
 *
 * Iterated with a for..of, which walks code points rather than UTF-16 units, so
 * an emoji is examined as one character instead of as two lone surrogates that
 * would both be reported as offending. The UCS-2 length is still measured in
 * UTF-16 units — `.length` — because that is what the air interface counts, and
 * an emoji genuinely does occupy two of them.
 */
export function describeSms(message: string): SmsSegmentInfo {
  const text = message ?? ''

  let septets = 0
  const offending: string[] = []
  const seen = new Set<string>()

  for (const char of text) {
    if (GSM7_BASIC.has(char)) {
      septets += 1
    } else if (GSM7_EXTENDED.has(char)) {
      septets += 2
    } else if (!seen.has(char)) {
      seen.add(char)
      offending.push(char)
    }
  }

  const isGsm7 = offending.length === 0

  const units = isGsm7 ? septets : text.length
  const single = isGsm7 ? GSM7_SINGLE : UCS2_SINGLE
  const multipart = isGsm7 ? GSM7_MULTIPART : UCS2_MULTIPART

  // An empty box is not a zero-length message being sent for free — it is no
  // message at all, and reporting "1 segment" next to an empty field reads as
  // though pressing Send would cost something.
  const segments = units === 0 ? 0 : (units <= single ? 1 : Math.ceil(units / multipart))
  const capacity = segments <= 1 ? single : multipart * segments

  return {
    encoding: isGsm7 ? 'GSM-7' : 'UCS-2',
    segments,
    units,
    capacity,
    remaining: Math.max(0, capacity - units),
    offendingCharacters: offending,
  }
}

/**
 * The offending characters as something readable in a sentence.
 *
 * A curly quote and a straight quote are the same shape at 12px, so naming the
 * character alone ("this message contains ’") is not enough to act on — the
 * one that costs money is indistinguishable from the one that does not. The
 * common Word-paste culprits are named in words for that reason; anything
 * else is quoted as-is.
 */
export function nameCharacter(char: string): string {
  const named: Record<string, string> = {
    '‘': 'a curly quote (‘)',
    '’': 'a curly apostrophe (’)',
    '“': 'a curly quote (“)',
    '”': 'a curly quote (”)',
    '–': 'an en dash (–)',
    '—': 'an em dash (—)',
    '…': 'an ellipsis (…)',
    ' ': 'a non-breaking space',
    '​': 'a zero-width space',
  }

  return named[char] ?? `"${char}"`
}
