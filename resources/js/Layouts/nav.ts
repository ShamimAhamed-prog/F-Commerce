import { stripBase } from '@/lib/base';

/** Navigation registry. Only live routes are listed; entries are hidden per permission. */
export interface NavItem {
    key: string; // i18n key under nav.*
    href: string;
    icon: string;
    group: 'overview' | 'sales' | 'catalog' | 'finance' | 'workspace';
    permission?: string;
    tab?: boolean; // shown in the mobile bottom tab bar
    match?: string; // path prefix that marks it active (defaults to href)
}

export const NAV: NavItem[] = [
    { key: 'dashboard', href: '/app/dashboard', icon: 'home', group: 'overview', permission: 'dashboard.view', tab: true },
    { key: 'orders', href: '/app/orders', icon: 'receipt', group: 'sales', permission: 'orders.view', tab: true },
    { key: 'shipments', href: '/app/shipments', icon: 'truck', group: 'sales', permission: 'shipments.view' },
    { key: 'customers', href: '/app/customers', icon: 'users', group: 'sales', permission: 'customers.view' },
    { key: 'products', href: '/app/products', icon: 'box', group: 'catalog', permission: 'products.view', tab: true },
    { key: 'inventory', href: '/app/inventory', icon: 'stack', group: 'catalog', permission: 'inventory.view' },
    { key: 'imports', href: '/app/imports', icon: 'upload', group: 'catalog', permission: 'products.import' },
    { key: 'accounting', href: '/app/accounting', icon: 'book', group: 'finance', permission: 'accounting.view', tab: true, match: '/app/accounting' },
    { key: 'expenses', href: '/app/accounting/expenses', icon: 'wallet', group: 'finance', permission: 'expenses.view' },
    { key: 'cod', href: '/app/accounting/cod', icon: 'truck', group: 'finance', permission: 'accounting.view' },
    { key: 'equity', href: '/app/accounting/equity', icon: 'coins', group: 'finance', permission: 'accounting.view' },
    { key: 'reports', href: '/app/reports/profit-loss', icon: 'chart', group: 'finance', permission: 'reports.view', match: '/app/reports' },
    { key: 'staff', href: '/app/settings/staff', icon: 'users', group: 'workspace', permission: 'staff.manage' },
    { key: 'couriers', href: '/app/settings/couriers', icon: 'truck', group: 'workspace', permission: 'integrations.manage' },
    { key: 'settings', href: '/app/settings', icon: 'cog', group: 'workspace', permission: 'settings.manage', match: '/app/settings$' },
    { key: 'workspaces', href: '/app/workspaces', icon: 'building', group: 'workspace' },
];

export const GROUPS: { key: NavItem['group']; label: string }[] = [
    { key: 'overview', label: 'nav.g_overview' },
    { key: 'sales', label: 'nav.g_sales' },
    { key: 'catalog', label: 'nav.g_catalog' },
    { key: 'finance', label: 'nav.g_finance' },
    { key: 'workspace', label: 'nav.g_workspace' },
];

/** Mobile "More" tab: everything that is not in the bottom bar. */
export const MORE_TAB = { key: 'more', href: '/app/more', icon: 'more' as const, group: 'workspace' as const };

/** Active-state matcher. `match` may end in `$` for an exact path. */
export function isActive(item: { href: string; match?: string }, url: string): boolean {
    const path = stripBase(url.split('?')[0]);
    const m = item.match ?? item.href;
    if (m.endsWith('$')) return path === m.slice(0, -1);
    // Sibling items under a shared prefix (e.g. /app/accounting vs /app/accounting/expenses) must not both light up.
    if (item.href === '/app/accounting') return path === '/app/accounting' || path.startsWith('/app/accounting/accounts') || path.startsWith('/app/accounting/journal');
    return path === m || path.startsWith(m + '/');
}
