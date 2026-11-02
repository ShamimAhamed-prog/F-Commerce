export interface AuthUser {
    id: number;
    name: string;
    email: string;
    locale: string;
    is_super_admin: boolean;
}

export interface TenantSummary {
    id: number;
    name: string;
    slug: string;
}

export interface SharedProps {
    [key: string]: unknown;
    auth: { user: AuthUser | null };
    tenant: (TenantSummary & { timezone: string; currency: string; lakh: boolean }) | null;
    tenants: TenantSummary[];
    permissions: string[];
    locale: string;
    flash: { success: string | null; error: string | null };
}
