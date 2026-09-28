/**
 * How an account is named on screen. Shared by the Accounts table and the
 * profile dialog so the row and the dialog it opens cannot disagree.
 *
 * A head of the family is a person; a barangay or organization account is the
 * institution, with the contact person as a second line.
 */
import { initials as personInitials } from '@/composables/adminUi'
import { ACCOUNT_TYPE, accountTypeLabel } from '@/composables/accountType'

interface NamedAccount {
  account_type?: string | null
  first_name?: string | null
  middle_name?: string | null
  last_name?: string | null
  organization_name?: string | null
  barangay_name?: string | null
  barangay?: { barangay_name?: string | null } | null
}

export const fullName = (r: NamedAccount): string =>
  [r.last_name, [r.first_name, r.middle_name].filter(Boolean).join(' ')].filter(Boolean).join(', ')

export const barangayOf = (r: NamedAccount): string => r.barangay?.barangay_name || r.barangay_name || 'N/A'

/** The headline: a person for a head of the family, the institution otherwise. */
export const primaryName = (r: NamedAccount): string => {
  if (r.account_type === ACCOUNT_TYPE.barangay) {
    return barangayOf(r)
  }
  if (r.account_type === ACCOUNT_TYPE.organization) {
    return r.organization_name || accountTypeLabel(r.account_type)
  }
  return fullName(r)
}

/** Who to actually call, only where the headline isn't already a person. */
export const contactName = (r: NamedAccount): string | null =>
  r.account_type === ACCOUNT_TYPE.barangay || r.account_type === ACCOUNT_TYPE.organization ? fullName(r) : null

/** First letters of the first two words. */
export const wordInitials = (name?: string | null): string =>
  (name || '').split(/\s+/).filter((w) => /^\p{L}/u.test(w)).slice(0, 2).map((w) => w.charAt(0)).join('').toUpperCase()

/** A person's initials for a head of the family; the headline's for an institution. */
export const accountInitials = (r: NamedAccount): string =>
  r.account_type === ACCOUNT_TYPE.head || !r.account_type ? personInitials(r) : wordInitials(primaryName(r))
