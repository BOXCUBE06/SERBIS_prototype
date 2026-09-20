/**
 * Phone numbers as the panel shows and takes them.
 *
 * The server stores and returns a resident's number in one form,
 * `+639XXXXXXXXX` (E.164) — it is the resident's login and the form the SMS
 * provider takes. Staff write and dial the national form, `09XXXXXXXXX`, so
 * everything the panel prints goes through `displayPhone`, and the forms accept
 * any of the three spellings the server does. The server compares in canonical
 * form, so "09171234567" and "+639171234567" are the same number there.
 */

/** The three spellings the server accepts — App\Support\PhoneNumber::REGEX. */
export const MOBILE_NUMBER_PATTERN = /^(09\d{9}|639\d{9}|\+639\d{9})$/

/** `+639171234567` (or `639…`) read as `09171234567`; anything else is returned trimmed and unchanged. */
export function displayPhone(value: string | null | undefined): string {
  const v = (value ?? '').trim()
  const match = v.match(/^\+?63(9\d{9})$/)
  return match ? `0${match[1]}` : v
}

/** Whether `value` is a Philippine mobile number the server will accept. */
export function isMobileNumber(value: string | null | undefined): boolean {
  return MOBILE_NUMBER_PATTERN.test((value ?? '').trim())
}
