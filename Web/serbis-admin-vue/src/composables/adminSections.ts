/**
 * The panel's sections: one per sidebar item, in sidebar order, each with the
 * stable key access is granted by. Keys, never labels, so renaming a page does
 * not move anyone's permissions.
 *
 * The menu, the router and the access dialog on Staff Accounts all read this one
 * list. The server keeps its own copy (App\Support\AdminSections) and is the one
 * that enforces it; if a key is added here it has to be added there too, or the
 * server will refuse a section the menu offers.
 */

export type SectionGroup = 'main' | 'system'

export interface AdminSection {
  key: string
  to: string
  title: string
  icon: string
  group: SectionGroup
  /** Not handed out per account: only a super admin has it. */
  superAdminOnly?: boolean
}

export const ADMIN_SECTIONS: AdminSection[] = [
  { key: 'dashboard', to: '/', title: 'Dashboard', icon: 'mdi-view-dashboard-outline', group: 'main' },
  // Directly after Dashboard: the two are read together, one for today and
  // one for the quarter.
  { key: 'analytics', to: '/analytics', title: 'Analytics', icon: 'mdi-chart-box-outline', group: 'main' },
  { key: 'requests', to: '/manage-requests', title: 'Resident Requests', icon: 'mdi-clipboard-text-outline', group: 'main' },
  { key: 'ambulance', to: '/conduction-requests', title: 'Ambulance Dispatch Requests', icon: 'mdi-ambulance', group: 'main' },
  { key: 'borrowings', to: '/borrowings', title: 'Equipment Borrowing', icon: 'mdi-hand-extended-outline', group: 'main' },
  { key: 'vehicles', to: '/vehicles', title: 'Vehicles', icon: 'mdi-ambulance', group: 'main' },
  { key: 'inventory', to: '/inventory', title: 'Resource Management', icon: 'mdi-toolbox-outline', group: 'main' },
  // Below Resource Management on purpose: it is the list of what the catalogue
  // above does not carry, and it is read next to it, not next to the board.
  { key: 'procurement', to: '/procurement', title: 'Procurement Reference', icon: 'mdi-clipboard-list-outline', group: 'main' },
  { key: 'sms', to: '/sms', title: 'Text Blast (SMS)', icon: 'mdi-message-text-fast-outline', group: 'main' },

  { key: 'services', to: '/services-config', title: 'Manage Services', icon: 'mdi-wrench-outline', group: 'system' },
  { key: 'service_audience', to: '/service-audience', title: 'Service Audience', icon: 'mdi-account-check-outline', group: 'system' },
  { key: 'service_vehicles', to: '/service-vehicles', title: 'Service Vehicles', icon: 'mdi-truck-outline', group: 'system' },
  { key: 'residents', to: '/users', title: 'Residents', icon: 'mdi-account-group-outline', group: 'system' },
  { key: 'staff', to: '/staff', title: 'Staff Accounts', icon: 'mdi-shield-account-outline', group: 'system', superAdminOnly: true },
  { key: 'files', to: '/files', title: 'Documents', icon: 'mdi-folder-outline', group: 'system' },
  { key: 'logs', to: '/logs', title: 'Activity Logs', icon: 'mdi-history', group: 'system' },
]

/** What a super admin may give to another account. Staff Accounts is not on it. */
export const ASSIGNABLE_SECTIONS: AdminSection[] = ADMIN_SECTIONS.filter((s) => !s.superAdminOnly)

/** The section a route path belongs to, or undefined for a path that is not a page (login, not-found). */
export function sectionForPath(path: string): string | undefined {
  return ADMIN_SECTIONS.find((s) => s.to === path)?.key
}
