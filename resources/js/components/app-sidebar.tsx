import { Link, usePage } from '@inertiajs/react';
import { KeyRound, LayoutGrid, Shield } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index } from '@/routes/admin';
import { edit } from '@/routes/ai-settings';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Candidatures',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const items = [
        ...mainNavItems,
        ...(auth.user.role !== 'guest'
            ? [{ title: 'Moteurs IA', href: edit(), icon: KeyRound }]
            : []),
        ...(auth.user.role === 'admin'
            ? [{ title: 'Administration', href: index(), icon: Shield }]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
