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

export type SectionGroup = 'overview' | 'requests' | 'resources' | 'residents' | 'configuration' | 'system'

/** Sidebar and access-dialog order; each item's group must be one of these. */
export const SECTION_GROUPS: { key: SectionGroup; label: string }[] = [
  { key: 'overview', label: 'Overview' },
  { key: 'requests', label: 'Requests' },
  { key: 'resources', label: 'Resources' },
  { key: 'residents', label: 'Accounts' },
  { key: 'configuration', label: 'Configuration' },
  { key: 'system', label: 'System' },
]

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
  { key: 'dashboard', to: '/', title: 'Dashboard', icon: 'mdi-view-dashboard-outline', group: 'overview' },
  // Directly after Dashboard: the two are read together, one for today and
  // one for the quarter.
  { key: 'analytics', to: '/analytics', title: 'Analytics', icon: 'mdi-chart-box-outline', group: 'overview' },

  { key: 'requests', to: '/manage-requests', title: 'Resident Requests', icon: 'mdi-clipboard-text-outline', group: 'requests' },
  { key: 'ambulance', to: '/conduction-requests', title: 'Ambulance Dispatch', icon: 'mdi-ambulance', group: 'requests' },
  { key: 'borrowings', to: '/borrowings', title: 'Equipment Borrowing', icon: 'mdi-hand-extended-outline', group: 'requests' },

  { key: 'vehicles', to: '/vehicles', title: 'Vehicles', icon: 'mdi-ambulance', group: 'resources' },
  { key: 'responders', to: '/responders', title: 'Responders', icon: 'mdi-account-hard-hat-outline', group: 'resources' },
  { key: 'inventory', to: '/inventory', title: 'Resource Management', icon: 'mdi-toolbox-outline', group: 'resources' },
  // Below Resource Management on purpose: it is the list of what the catalogue
  // above does not carry, and it is read next to it, not next to the board.
  { key: 'procurement', to: '/procurement', title: 'Procurement Reference', icon: 'mdi-clipboard-list-outline', group: 'resources' },
  { key: 'files', to: '/files', title: 'Documents', icon: 'mdi-folder-outline', group: 'resources' },

  { key: 'residents', to: '/users', title: 'Accounts', icon: 'mdi-account-group-outline', group: 'residents' },
  { key: 'sms', to: '/sms', title: 'Text Blast (SMS)', icon: 'mdi-message-text-fast-outline', group: 'residents' },

  { key: 'services', to: '/services-config', title: 'Manage Services', icon: 'mdi-wrench-outline', group: 'configuration' },
  { key: 'service_audience', to: '/service-audience', title: 'Service Audience', icon: 'mdi-account-check-outline', group: 'configuration' },
  { key: 'service_vehicles', to: '/service-vehicles', title: 'Service Vehicles', icon: 'mdi-truck-outline', group: 'configuration' },

  { key: 'staff', to: '/staff', title: 'Staff Accounts', icon: 'mdi-shield-account-outline', group: 'system', superAdminOnly: true },
  { key: 'logs', to: '/logs', title: 'Activity Logs', icon: 'mdi-history', group: 'system' },
]

/** What a super admin may give to another account. Staff Accounts is not on it. */
export const ASSIGNABLE_SECTIONS: AdminSection[] = ADMIN_SECTIONS.filter((s) => !s.superAdminOnly)

/** The section a route path belongs to, or undefined for a path that is not a page (login, not-found). */
export function sectionForPath(path: string): string | undefined {
  return ADMIN_SECTIONS.find((s) => s.to === path)?.key
}
