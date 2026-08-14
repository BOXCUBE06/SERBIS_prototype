# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Two audiences on two different surfaces:

- **Admin panel (this surface):** MDRRMO Echague office staff, at a desk, running the day-to-day desk work of a barangay disaster/social-services coordination office — resident accounts, equipment loans, vehicle fleet readiness, service requests, SMS blasts.
- **Mobile app:** barangay residents (heads of the family) filing requests and receiving updates.

## Product Purpose

SERBIS coordinates community services and resource lending for MDRRMO Echague: resident/service-request management, equipment borrowing (approve → release → return), fleet readiness, and SMS communication with barangays.

## Positioning

Explicitly **not** an emergency-response or dispatch tool, despite sitting inside a disaster-office context. It is administrative coordination software — never argue from urgency, never adopt alarm/dispatch visual language, even for an overdue loan.

## Operating Context

- Admin panel used at a desk during office hours, desktop-primary; must still work down to phone width.
- Borrow Requests specifically: staff move a resident's equipment loan through Pending → Approved → Released → Returned (or Denied), tracking due dates and stock levels along the way.

## Capabilities and Constraints

- Terminology: residents are addressed as "Head of the Family" in admin-facing copy, not "resident," per standing product decision.
- Tone: calm and administrative throughout, including overdue/error states — no siren-red alarm dashboards, no urgency framing, even on a disaster-office-adjacent surface.

## Brand Commitments

- Product name: SERBIS. Org: MDRRMO Echague (Municipal Disaster Risk Reduction and Management Office).
- Brand green is load-bearing and fixed: `#297A67` (primary token) / `#0f4c3a` (deep brand green used for headers/CTAs elsewhere in the admin panel). New layout work must sit inside this identity, not replace it.
- Existing admin panel visual system (established, not to be treated as absent): dense `v-data-table` records, tinted status pills, brand-green accents, Vuetify component base — present across User Management, Fleet Management, Staff Accounts.

## Product Principles

- Calm over alarming: administrative tone even where the subject matter (disaster office) could tempt urgency styling.
- Task clarity over decoration: this is desk software staff use repeatedly, not a marketing surface.
- One coherent system across the admin panel — a new structural direction for one page should still read as the same product as its neighbors.
- Terminology precision: "Head of the Family," never "resident," in admin copy.

## Accessibility & Inclusion

No project-specific requirement beyond standard WCAG AA, already a running concern across this admin panel (contrast, keyboard access, focus states have been actively fixed in prior work).
