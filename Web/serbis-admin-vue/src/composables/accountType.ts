/**
 * The `tbl_residents.account_type` vocabulary.
 *
 * Three kinds of account request from the mobile app. A head of the family is
 * the ordinary case and what every account was before the column existed; a
 * barangay hall shares one account per barangay; an organization (a school,
 * PNP, BFP, a council stakeholder) requests for itself. Only staff create the
 * last two, from the Residents page.
 *
 * The stored values are the API's own snake_case; only the labels are ours.
 */

export const ACCOUNT_TYPE = {
  head: 'head_of_family',
  barangay: 'barangay',
  organization: 'organization',
} as const

export type AccountType = (typeof ACCOUNT_TYPE)[keyof typeof ACCOUNT_TYPE]

const LABELS: Record<string, string> = {
  [ACCOUNT_TYPE.head]: 'Head of the Family',
  [ACCOUNT_TYPE.barangay]: 'Barangay',
  [ACCOUNT_TYPE.organization]: 'Organization',
}

/** Falls back to the head of the family: a row without the field predates it. */
export function accountTypeLabel(type?: string | null): string {
  return LABELS[type || ACCOUNT_TYPE.head] ?? String(type)
}

/** `title`/`value` pairs for the create/edit select. */
export const ACCOUNT_TYPE_ITEMS = [
  { title: LABELS[ACCOUNT_TYPE.head], value: ACCOUNT_TYPE.head },
  { title: LABELS[ACCOUNT_TYPE.barangay], value: ACCOUNT_TYPE.barangay },
  { title: LABELS[ACCOUNT_TYPE.organization], value: ACCOUNT_TYPE.organization },
]

/** Same, with the list filter's "All" in front. */
export const ACCOUNT_TYPE_FILTER_ITEMS = [{ title: 'All types', value: 'All' }, ...ACCOUNT_TYPE_ITEMS]
