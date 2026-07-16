import React, { useState, useEffect } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import toast, { Toaster } from 'react-hot-toast';
import { 
    LayoutDashboard, 
    Ticket, 
    Database, 
    FileEdit, 
    Clock, 
    Bell, 
    Star, 
    Settings, 
    Users, 
    Shield,
    LogOut,
    ChevronDown,
    User,
    MoreHorizontal,
    Grid3X3,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Sheet, SheetContent } from '@/Components/ui/sheet';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from "@/Components/ui/dropdown-menu";
import { ThemeToggle } from '@/Components/ThemeToggle';
import NotificationBell from '@/Components/NotificationBell';
import ProfileModal from '@/Components/ProfileModal';
import { BottomNav } from '@/Components/BottomNav';
import type { BottomNavItem } from '@/Components/BottomNav';
import { useIdleTimer } from '@/hooks/useIdleTimer';

interface AdminLayoutProps {
    children: React.ReactNode;
    title?: string;
}

interface NavItem {
    label: string;
    icon?: any;
    route?: string;
    disabled?: boolean;
    badge?: React.ReactNode;
    children?: NavItem[];
}

const adminNavItems: NavItem[] = [
    { label: 'Dashboard', icon: LayoutDashboard, route: '/admin/dashboard' },
    { label: 'Tiketing', icon: Ticket, route: '/admin/tiket' },
    { 
        label: 'Master Data', 
        icon: Database, 
        children: [
            { label: 'Unit', route: '/admin/master/unit' },
            { label: 'Sub Unit', route: '/admin/master/sub-unit' },
            { label: 'Divisi', route: '/admin/master/divisi' },
            { label: 'Unit Organisasi', route: '/admin/master/unit-organisasi' },
            { label: 'Jabatan', route: '/admin/master/jabatan' },
        ]
    },
    { label: 'Peraturan Form', icon: FileEdit, route: '/admin/peraturan-form' },
    { label: 'SLA', icon: Clock, route: '/admin/sla-config' },
    { label: 'Reminder', icon: Bell, route: '/admin/reminder-config' },
    { label: 'CSAT', icon: Star, route: '/admin/csat' },
    { label: 'Konfigurasi', icon: Settings, route: '/admin/konfigurasi' },
    { label: 'Admin Mgt', icon: Shield, route: '/admin/manajemen-admin' },
    { label: 'User Mgt', icon: Users, route: '/admin/manajemen-user' },
];

function NavLink({ item, active }: { item: NavItem; active: boolean }) {
    const Icon = item.icon;
    return (
        <Link
            href={item.route!}
            className={`group relative flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all duration-200 ${
                active
                    ? 'bg-primary/10 text-primary before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-5 before:w-[3px] before:rounded-r-full before:bg-primary'
                    : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground'
            }`}
        >
            <Icon className={`h-4 w-4 shrink-0 transition-transform duration-200 ${active ? 'scale-110' : 'group-hover:scale-110'}`} />
            <span>{item.label}</span>
        </Link>
    );
}

export default function AdminLayout({ children, title }: AdminLayoutProps) {
    const { auth, flash, appConfig } = usePage<any>().props;
    const admin = auth.admin || auth.user;
    const [openMenus, setOpenMenus] = useState<Record<string, boolean>>({});
    const [profileOpen, setProfileOpen] = useState(false);
    const [sidebarOpen, setSidebarOpen] = useState(false);

    useIdleTimer('/admin/logout');

    useEffect(() => {
        if (flash?.success) toast.success(flash.success, { id: 'flash-success' });
        if (flash?.error) toast.error(flash.error, { id: 'flash-error' });
        if (flash?.message) toast(flash.message, { id: 'flash-message' });
    }, [flash]);
    
    const url = usePage().url;
    const isActive = (routePath: string) => url.startsWith(routePath);

    const toggleMenu = (label: string) => {
        setOpenMenus(prev => ({ ...prev, [label]: !prev[label] }));
    };

    const systemName = appConfig?.nama_sistem || 'HALO APU';

    const SidebarContent = () => (
        <div className="flex h-full flex-col">
            <div className="flex h-14 shrink-0 items-center gap-2.5 border-b px-5 lg:h-[60px]">
                <Link href="/admin/dashboard" className="flex items-center gap-2.5 min-w-0">
                    {appConfig?.logo_path && (
                        <img id="displayBannerImg" src={`/storage/${appConfig.logo_path}`} alt="Banner" style={{ height: '55px', width: 'auto', objectFit: 'contain', display: 'inline-block' }} />
                    )}
                </Link>
            </div>
            <div className="flex-1 overflow-y-auto py-4 px-3 sidebar-scroll">
                <nav className="flex flex-col gap-1">
                    {adminNavItems.map((item, index) => {
                        const Icon = item.icon;
                        const hasActiveChild = item.children?.some(c => isActive(c.route!));
                        const active = item.route ? isActive(item.route) : !!hasActiveChild;

                        if (item.children) {
                            const hasToggled = item.label in openMenus;
                            const isOpen = hasToggled ? openMenus[item.label] : hasActiveChild;
                            return (
                                <div key={index}>
                                    <button
                                        onClick={() => toggleMenu(item.label)}
                                        className={`group flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all duration-200 ${
                                            active
                                                ? 'text-primary'
                                                : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground'
                                        }`}
                                    >
                                        <Icon className={`h-4 w-4 shrink-0 transition-transform duration-200 ${active ? 'scale-110' : ''}`} />
                                        <span className="flex-1 text-left">{item.label}</span>
                                        <ChevronDown className={`h-3.5 w-3.5 transition-transform duration-200 ${isOpen ? 'rotate-180' : ''}`} />
                                    </button>
                                    {isOpen && (
                                        <div className="ml-3 mt-1 border-l pl-2 space-y-0.5">
                                            {item.children.map((child, cIndex) => {
                                                const childActive = isActive(child.route || '');
                                                return (
                                                    <Link
                                                        key={cIndex}
                                                        href={child.route!}
                                                        className={`relative flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-all duration-200 ${
                                                            childActive
                                                                ? 'bg-primary/10 text-primary font-medium'
                                                                : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground'
                                                        }`}
                                                    >
                                                        {child.label}
                                                    </Link>
                                                )
                                            })}
                                        </div>
                                    )}
                                </div>
                            );
                        }

                        return (
                            <NavLink key={index} item={item} active={active} />
                        );
                    })}
                </nav>
            </div>

            <div className="border-t px-3 py-3">
                <div className="rounded-lg bg-muted/60 px-3 py-3">
                    <div className="flex items-center gap-2.5">
                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 overflow-hidden">
                            {admin?.avatar_path ? (
                                <img src={`/storage/${admin.avatar_path}`} alt="Avatar" className="h-full w-full object-cover" />
                            ) : (
                                <User className="h-3.5 w-3.5 text-primary" />
                            )}
                        </div>
                        <div className="min-w-0">
                            <p className="text-xs font-medium text-foreground truncate">{admin?.name || admin?.username || 'Admin'}</p>
                            <p className="text-[11px] text-muted-foreground truncate">{admin?.email || ''}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );

    const bottomNavItems: BottomNavItem[] = [
        { label: 'Dashboard', icon: LayoutDashboard, route: '/admin/dashboard' },
        { label: 'Tiket', icon: Ticket, route: '/admin/tiket' },
        { label: 'Monitor', icon: Grid3X3, route: '/admin/monitor' },
        { label: 'CSAT', icon: Star, route: '/admin/csat' },
        { label: 'Lainnya', icon: MoreHorizontal, onClick: () => setSidebarOpen(true) },
    ];

    return (
        <div className="grid h-screen w-full overflow-hidden md:grid-cols-[240px_1fr] lg:grid-cols-[260px_1fr]">
            <Toaster position="top-right" toastOptions={{ duration: 4000 }} />
            {title && <Head title={title} />}
            
            <Sheet open={sidebarOpen} onOpenChange={setSidebarOpen}>
                <SheetContent side="left" className="flex flex-col p-0 w-72">
                    <SidebarContent />
                </SheetContent>
            </Sheet>
            
            <div className="hidden border-r bg-white dark:bg-zinc-950 md:flex flex-col overflow-hidden">
                <SidebarContent />
            </div>
            
            <div className="flex flex-col min-w-0 overflow-hidden bg-zinc-50/50 dark:bg-zinc-900/50">
                <header className="flex h-14 shrink-0 items-center gap-3 border-b bg-white/80 dark:bg-zinc-950/80 backdrop-blur-md supports-[backdrop-filter]:bg-white/60 px-4 lg:h-[60px] lg:px-6">
                    <div className="flex-1" />
                    
                    <NotificationBell />
                    <ThemeToggle />
                    
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="rounded-full h-8 w-8 border overflow-hidden">
                                {admin?.avatar_path ? (
                                    <img src={`/storage/${admin.avatar_path}`} alt="Avatar" className="h-full w-full object-cover" />
                                ) : (
                                    <User className="h-4 w-4" />
                                )}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                            <DropdownMenuGroup>
                                <DropdownMenuLabel>
                                    <div className="flex flex-col gap-0.5">
                                        <span className="text-sm font-medium">{admin?.name || admin?.username || 'Admin'}</span>
                                        <span className="text-xs font-normal text-muted-foreground">{admin?.email || ''}</span>
                                    </div>
                                </DropdownMenuLabel>
                            </DropdownMenuGroup>
                            <div className="border-t my-1" />
                            <DropdownMenuItem onClick={() => setProfileOpen(true)} className="cursor-pointer">
                                <User className="h-4 w-4 mr-2" />
                                Edit Profil
                            </DropdownMenuItem>
                            <DropdownMenuItem className="p-0">
                                <Link href="/admin/logout" method="post" as="button" className="flex w-full cursor-pointer items-center gap-2 px-2 py-1.5 text-sm text-red-600 transition-colors hover:bg-red-50 dark:hover:bg-red-950/30 rounded-md">
                                    <LogOut className="h-4 w-4" />
                                    Keluar
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <ProfileModal open={profileOpen} onOpenChange={setProfileOpen} user={admin} isAdmin={true} />
                </header>
                <main className="flex-1 overflow-y-auto">
                    <div key={url} className="animate-page-in mx-auto w-full max-w-7xl p-4 lg:p-6 xl:p-8 pb-[calc(64px+env(safe-area-inset-bottom,16px))] md:pb-0">
                        {children}
                    </div>
                </main>
            </div>

            <BottomNav items={bottomNavItems} />
        </div>
    );
}
