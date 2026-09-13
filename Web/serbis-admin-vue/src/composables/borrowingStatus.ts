/**
 * The EquipmentBorrowing status vocabulary — one definition, read by both
 * EquipmentBorrowingView (the working pipeline) and ProcurementReferenceView
 * (the same rows, read-only). These used to be two verbatim-identical arrays
 * (`columns` and `STATUS_META`) that happened to agree because nobody had
 * touched one without the other yet, not because anything enforced it.
 *
 * Colors, and why each one is what it is:
 * - Pending  #B45309 — a darkened amber, not the raw `warning` token
 *   (`#F57C00`): this chip is solid-fill white-on-color, not text-on-tint,
 *   and white on `#F57C00` fails AA. `warning-strong` was tuned for a 14%
 *   tint background, not a full-strength one, so it doesn't fit here either
 *   — this stays its own hand-tuned value.
 * - Approved #1D4ED8 — its own color, not `info`. `Responding` (the
 *   ServiceRequest family's nearest equivalent moment) already owns `info`;
 *   the two vocabularies never render in the same table, but a shared
 *   status-color definition should still mean one thing per hue, so
 *   Approved keeps a dedicated blue rather than borrowing Responding's.
 * - Released #297A67 — was `#0E7490` (teal) until this pass; moved onto the
 *   same green as Returned/primary/success, all three names for one hue in
 *   this theme (see plugins/vuetify.ts: "Success is the same green as
 *   primary"). Released and Returned never render in the same table today
 *   (Active pipeline vs. History are separate tabs), but they are now
 *   genuinely the same color, not just similar — flagged in the commit,
 *   easy to give Released its own accent again if that reads as a loss of
 *   information once both are visible somewhere at once (e.g. a future
 *   combined status filter).
 * - Returned #297A67 — unchanged.
 * - Denied   #B91C1C — unchanged, same darkened-for-solid-fill reasoning as
 *   Pending above (`error` is `#D32F2F`; white text on that is tighter than
 *   on this).
 * - Cancelled #475569 — unchanged, and deliberately not red. Checked both
 *   controllers before relying on this: ServiceRequestController.php:962
 *   and EquipmentBorrowingController.php:281 are both a resident withdrawing
 *   their own request/booking, never a staff refusal. Denied is the refusal;
 *   Cancelled is not, in either family, which is also why the
 *   ServiceRequest-family pill for this same word (in settings.scss) is
 *   neutral slate now too, not grouped with Disapproved's red.
 */
export interface BorrowingStatusMeta {
  status: string
  label: string
  accent: string
  icon: string
  terminal?: boolean
}

export const BORROWING_STATUSES: BorrowingStatusMeta[] = [
  { status: 'Pending', label: 'Pending', accent: '#B45309', icon: 'mdi-clock-outline' },
  { status: 'Approved', label: 'Approved', accent: '#1D4ED8', icon: 'mdi-check-decagram-outline' },
  { status: 'Released', label: 'Released', accent: '#297A67', icon: 'mdi-hand-extended-outline' },
  { status: 'Returned', label: 'Returned', accent: '#297A67', icon: 'mdi-check-circle-outline', terminal: true },
  { status: 'Denied', label: 'Denied', accent: '#B91C1C', icon: 'mdi-close-circle-outline', terminal: true },
  // The resident withdrew it themselves, so it is not a refusal and must not
  // sit in the red the way Denied does. Slate, 7.4:1 with white text.
  // Terminal here too: nothing in either view can move a cancelled request,
  // and the backend refuses every attempt on both models.
  { status: 'Cancelled', label: 'Cancelled', accent: '#475569', icon: 'mdi-cancel', terminal: true },
]

export const statusAccent = (status?: string | null): string =>
  BORROWING_STATUSES.find((s) => s.status === status)?.accent || '#64748B'

export const statusIcon = (status?: string | null): string =>
  BORROWING_STATUSES.find((s) => s.status === status)?.icon || 'mdi-help-circle-outline'
