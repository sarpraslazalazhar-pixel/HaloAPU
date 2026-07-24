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
    ChevronLeft,
    ChevronRight,
    User,
    MoreHorizontal,
    Grid3X3,
    Sparkles,
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
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger
} from "@/Components/ui/tooltip";
import { ThemeToggle } from '@/Components/ThemeToggle';
import NotificationBell from '@/Components/NotificationBell';
import ProfileModal from '@/Components/ProfileModal';
import { BottomNav } from '@/Components/BottomNav';
import type { BottomNavItem } from '@/Components/BottomNav';
import { useIdleTimer } from '@/hooks/useIdleTimer';
import { useWebPush } from '@/hooks/useWebPush';

interface AdminLayoutProps {
    children: React.ReactNode;
    title?: string;
}

interface NavSubItem {
    label: string;
    icon?: any;
    route: string;
    permissionGroup?: string;
}

interface NavItem {
    type: 'link' | 'header' | 'dropdown';
    label: string;
    icon?: any;
    route?: string;
    permissionGroup?: string;
    children?: NavSubItem[];
}

function isRouteActive(url: string, routePath?: string): boolean {
    if (!routePath) return false;
    const pathOnly = url.split('?')[0].split('#')[0];
    return pathOnly === routePath || pathOnly.startsWith(routePath + '/');
}

const adminNavItems: NavItem[] = [
    { type: 'link', label: 'Dasbor', icon: LayoutDashboard, route: '/admin/dashboard' },
    { type: 'link', label: 'Tiketing', icon: Ticket, route: '/admin/tiket' },
    { type: 'link', label: 'Monitor Grid', icon: Grid3X3, route: '/admin/monitor' },

    { type: 'header', label: 'MASTER DATA' },
    {
        type: 'dropdown',
        label: 'Layanan',
        icon: Database,
        permissionGroup: 'akses-layanan',
        children: [
            { label: 'Kanal Layanan', icon: Database, route: '/admin/master/unit', permissionGroup: 'akses-layanan' },
            { label: 'Jenis Layanan', icon: Database, route: '/admin/master/sub-unit', permissionGroup: 'akses-layanan' },
        ]
    },
    {
        type: 'dropdown',
        label: 'Struktur',
        icon: Users,
        permissionGroup: 'akses-struktur',
        children: [
            { label: 'Divisi', icon: Users, route: '/admin/master/divisi', permissionGroup: 'akses-struktur' },
            { label: 'Sub Divisi', icon: Users, route: '/admin/master/unit-organisasi', permissionGroup: 'akses-struktur' },
            { label: 'Jabatan', icon: Users, route: '/admin/master/jabatan', permissionGroup: 'akses-struktur' },
        ]
    },

    { type: 'header', label: 'KONFIGURASI', permissionGroup: 'akses-konfigurasi' },
    {
        type: 'dropdown',
        label: 'Konfigurasi',
        icon: Settings,
        permissionGroup: 'akses-konfigurasi',
        children: [
            { label: 'Form', icon: FileEdit, route: '/admin/peraturan-form', permissionGroup: 'akses-konfigurasi' },
            { label: 'SLA', icon: Clock, route: '/admin/sla-config', permissionGroup: 'akses-konfigurasi' },
            { label: 'Reminder', icon: Bell, route: '/admin/reminder-config', permissionGroup: 'akses-konfigurasi' },
            { label: 'Sistem', icon: Settings, route: '/admin/konfigurasi', permissionGroup: 'akses-konfigurasi' },
        ]
    },

    { type: 'header', label: 'LAPORAN', permissionGroup: 'akses-laporan' },
    { type: 'link', label: 'CSAT', icon: Star, route: '/admin/csat', permissionGroup: 'akses-laporan' },
    { type: 'link', label: 'Tiket', icon: Ticket, route: '/admin/laporan/tiket', permissionGroup: 'akses-laporan' },

    { type: 'header', label: 'MANAJEMEN AKUN', permissionGroup: 'akses-manajemen-akun' },
    { type: 'link', label: 'Manajemen Peran', icon: Shield, route: '/admin/manajemen-peran', permissionGroup: 'akses-manajemen-akun' },
    { type: 'link', label: 'Manajemen Operator', icon: Shield, route: '/admin/manajemen-operator', permissionGroup: 'akses-manajemen-akun' },
    { type: 'link', label: 'Manajemen Pengguna', icon: Users, route: '/admin/manajemen-user', permissionGroup: 'akses-manajemen-akun' },
];

function NavLink({ item, active, isCollapsed }: { item: NavItem; active: boolean; isCollapsed: boolean }) {
    const Icon = item.icon;

    return (
        <Link
            href={item.route!}
            title={isCollapsed ? item.label : undefined}
            className={`group relative flex items-center transition-all duration-200 ${isCollapsed
                    ? 'h-10 w-10 justify-center rounded-xl mx-auto'
                    : 'gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium w-full'
                } ${active
                    ? 'bg-sky-500/10 text-sky-600 dark:bg-sky-500/20 dark:text-sky-400 font-semibold shadow-sm border border-sky-200/50 dark:border-sky-800/40'
                    : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground'
                }`}
        >
            <Icon className={`h-4 w-4 shrink-0 transition-transform duration-200 ${active ? 'scale-110 text-sky-500 dark:text-sky-400' : 'group-hover:scale-110'}`} />
            {!isCollapsed && <span className="truncate">{item.label}</span>}
        </Link>
    );
}

function NavDropdown({ item, isCollapsed, url, permissions }: { item: NavItem; isCollapsed: boolean; url: string; permissions?: string[] }) {
    const visibleChildren = item.children?.filter(child =>
        !child.permissionGroup || (permissions && permissions.includes(child.permissionGroup))
    ) || [];

    if (visibleChildren.length === 0) return null;

    const isChildActive = visibleChildren.some(child => isRouteActive(url, child.route));
    const [isOpen, setIsOpen] = useState(isChildActive);
    const [popoverOpen, setPopoverOpen] = useState(false);
    const popoverRef = React.useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (isChildActive) {
            setIsOpen(true);
        }
    }, [url]);

    // Close popover when clicking outside
    useEffect(() => {
        if (!popoverOpen) return;
        const handleClick = (e: MouseEvent) => {
            if (popoverRef.current && !popoverRef.current.contains(e.target as Node)) {
                setPopoverOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClick);
        return () => document.removeEventListener('mousedown', handleClick);
    }, [popoverOpen]);

    const Icon = item.icon;

    if (isCollapsed) {
        return (
            <div className="relative flex justify-center w-full">
                <DropdownMenu open={popoverOpen} onOpenChange={setPopoverOpen}>
                    <DropdownMenuTrigger
                        aria-label={item.label}
                        className={`group relative flex h-10 w-10 items-center justify-center rounded-xl transition-all duration-200 cursor-pointer outline-none ${isChildActive || popoverOpen
                                ? 'bg-sky-500/10 text-sky-600 dark:bg-sky-500/20 dark:text-sky-400 font-semibold border border-sky-200/50 dark:border-sky-800/40 shadow-sm'
                                : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground'
                            }`}
                    >
                        <Icon className={`h-4 w-4 shrink-0 transition-transform duration-200 ${isChildActive || popoverOpen ? 'scale-110 text-sky-500 dark:text-sky-400' : 'group-hover:scale-110'}`} />
                    </DropdownMenuTrigger>

                    <DropdownMenuContent side="right" align="start" sideOffset={10} className="w-52 p-1.5 z-[100] shadow-xl border bg-popover">
                        <div className="text-xs font-bold text-sky-600 dark:text-sky-400 px-2.5 py-1.5 border-b mb-1 flex items-center gap-2">
                            <Icon className="h-3.5 w-3.5" />
                            <span>{item.label}</span>
                        </div>
                        <div className="space-y-0.5">
                            {visibleChildren.map((child, idx) => {
                                const active = isRouteActive(url, child.route);
                                const ChildIcon = child.icon || Icon;
                                return (
                                    <Link
                                        key={idx}
                                        href={child.route}
                                        onClick={() => setPopoverOpen(false)}
                                        className={`flex items-center gap-2.5 px-2.5 py-2 text-xs font-medium rounded-lg cursor-pointer transition-colors w-full outline-none ${active
                                                ? 'bg-sky-500/15 text-sky-600 dark:bg-sky-500/25 dark:text-sky-400 font-semibold'
                                                : 'text-muted-foreground hover:bg-muted/70 hover:text-foreground hover:bg-muted/50'
                                            }`}
                                    >
                                        <ChildIcon className={`h-3.5 w-3.5 shrink-0 ${active ? 'text-sky-500' : ''}`} />
                                        <span className="truncate">{child.label}</span>
                                    </Link>
                                );
                            })}
                        </div>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-0.5">
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className={`group relative flex items-center justify-between gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium w-full transition-all duration-200 ${isChildActive
                        ? 'bg-sky-500/10 text-sky-600 dark:bg-sky-500/20 dark:text-sky-400 font-semibold'
                        : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground'
                    }`}
            >
                <div className="flex items-center gap-3 min-w-0">
                    <Icon className={`h-4 w-4 shrink-0 transition-transform duration-200 ${isChildActive ? 'scale-110 text-sky-500 dark:text-sky-400' : 'group-hover:scale-110'}`} />
                    <span className="truncate">{item.label}</span>
                </div>
                <ChevronDown className={`h-4 w-4 shrink-0 transition-transform duration-200 ${isOpen ? 'rotate-180 text-foreground' : 'text-muted-foreground'}`} />
            </button>

            {isOpen && (
                <div className="flex flex-col gap-0.5 ml-4 pl-3 border-l border-border/60 py-1 transition-all">
                    {visibleChildren.map((child, idx) => {
                        const active = isRouteActive(url, child.route);
                        const ChildIcon = child.icon;
                        return (
                            <Link
                                key={idx}
                                href={child.route}
                                className={`flex items-center gap-2.5 rounded-lg px-3 py-2 text-xs font-medium transition-all duration-150 ${active
                                        ? 'bg-sky-500/15 text-sky-600 dark:bg-sky-500/25 dark:text-sky-400 font-semibold shadow-xs'
                                        : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground'
                                    }`}
                            >
                                {ChildIcon ? (
                                    <ChildIcon className={`h-3.5 w-3.5 shrink-0 ${active ? 'text-sky-500' : ''}`} />
                                ) : (
                                    <span className={`h-1.5 w-1.5 rounded-full shrink-0 ${active ? 'bg-sky-500' : 'bg-muted-foreground/40'}`} />
                                )}
                                <span className="truncate">{child.label}</span>
                            </Link>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

export default function AdminLayout({ children, title }: AdminLayoutProps) {
    const { auth, flash, appConfig } = usePage<any>().props;
    const admin = auth.admin || auth.user;
    const [profileOpen, setProfileOpen] = useState(false);
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const { subscribe } = useWebPush(admin);

    // Collapsed sidebar state with localStorage persistence
    const [isCollapsed, setIsCollapsed] = useState<boolean>(() => {
        if (typeof window !== 'undefined') {
            return localStorage.getItem('admin_sidebar_collapsed') === 'true';
        }
        return false;
    });

    const toggleCollapse = () => {
        setIsCollapsed(prev => {
            const next = !prev;
            if (typeof window !== 'undefined') {
                localStorage.setItem('admin_sidebar_collapsed', String(next));
            }
            return next;
        });
    };

    useIdleTimer('/admin/logout');

    useEffect(() => {
        if (flash?.success) toast.success(flash.success, { id: 'flash-success' });
        if (flash?.error) toast.error(flash.error, { id: 'flash-error' });
        if (flash?.message) toast(flash.message, { id: 'flash-message' });

        // Listen for Echo notifications
        if (window.Echo && admin) {
            // Cek apakah sudah ditanya hari ini
            const lastAskedStr = localStorage.getItem('notif_last_asked');
            const lastAsked = lastAskedStr ? parseInt(lastAskedStr, 10) : 0;
            const now = Date.now();
            const oneDay = 24 * 60 * 60 * 1000;
            const shouldAsk = (now - lastAsked) > oneDay;

            // Proactive notification permission request
            if ('Notification' in window && Notification.permission === 'default' && shouldAsk) {
                toast((t) => (
                    <div className="flex flex-col gap-2">
                        <span className="text-sm font-medium">Aktifkan Notifikasi Browser</span>
                        <span className="text-xs text-muted-foreground">Terima pemberitahuan saat ada tiket baru atau update.</span>
                        <div className="flex gap-2 justify-end mt-1">
                            <Button size="sm" variant="outline" onClick={() => {
                                localStorage.setItem('notif_last_asked', Date.now().toString());
                                toast.dismiss(t.id);
                            }}>Nanti</Button>
                            <Button size="sm" onClick={() => {
                                localStorage.setItem('notif_last_asked', Date.now().toString());
                                toast.dismiss(t.id);
                                Notification.requestPermission().then((permission) => {
                                    if (permission === 'granted') {
                                        subscribe();
                                        toast.success('Notifikasi diaktifkan!');
                                    }
                                });
                            }}>Aktifkan</Button>
                        </div>
                    </div>
                ), { duration: Infinity, id: 'notif-request', position: 'bottom-right' });
            }

            const channel = admin.hasOwnProperty('username') && !admin.hasOwnProperty('divisi_id')
                ? `App.Models.Admin.${admin.id}`
                : `App.Models.User.${admin.id}`;

            window.Echo.private(channel)
                .notification((notification: any) => {
                    if ('Notification' in window && Notification.permission === 'granted') {
                        new Notification(notification.title || 'Pemberitahuan Baru', {
                            body: notification.message || '',
                        });
                    }
                    toast.success(notification.title || 'Pemberitahuan Baru', { id: `notif-${Date.now()}` });
                });

            return () => {
                window.Echo.leave(channel);
            };
        }
    }, [flash, admin]);

    const url = usePage().url;
    const isActive = (routePath: string) => isRouteActive(url, routePath);

    const systemName = appConfig?.nama_sistem || 'HALO APU';
    const faviconUrl = appConfig?.favicon_path ? `/storage/${appConfig.favicon_path}` : '/favicon.ico';
    const logoUrl = appConfig?.logo_path ? `/storage/${appConfig.logo_path}` : null;

    const renderSidebar = (collapsed: boolean) => (
        <div className="flex h-full min-h-0 flex-col justify-between">
            {/* Header / Brand Logo */}
            <div className={`flex h-14 shrink-0 items-center border-b px-4 lg:h-[60px] transition-all duration-300 ${collapsed ? 'justify-center' : 'justify-between'
                }`}>
                <Link href="/admin/dashboard" className="flex items-center gap-2.5 min-w-0">
                    {collapsed ? (
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-sky-500/10 dark:bg-sky-500/20 p-1.5 transition-all hover:scale-105 border border-sky-200/50 dark:border-sky-800/50 shadow-sm">
                            <img
                                src={faviconUrl}
                                alt="Favicon"
                                className="h-full w-full object-contain"
                                onError={(e) => {
                                    // Fallback to Icon if image breaks
                                    (e.target as HTMLElement).style.display = 'none';
                                }}
                            />
                        </div>
                    ) : (
                        logoUrl ? (
                            <img id="displayBannerImg" src={logoUrl} alt="Banner Logo" className="h-10 max-w-[180px] object-contain transition-all" />
                        ) : (
                            <div className="flex items-center gap-2">
                                <Sparkles className="h-5 w-5 text-sky-500" />
                                <span className="text-base font-bold tracking-tight text-foreground">{systemName}</span>
                            </div>
                        )
                    )}
                </Link>
            </div>

            {/* Navigation Items */}
            <div className="flex-1 min-h-0 overflow-y-auto py-4 px-2.5 sidebar-scroll">
                <nav className="flex flex-col gap-1 pb-6">
                    {adminNavItems.map((item, index) => {
                        // Check permissions
                        if (item.permissionGroup && auth.permissions && !auth.permissions.includes(item.permissionGroup)) {
                            return null;
                        }

                        if (item.type === 'header') {
                            if (collapsed) {
                                return (
                                    <div key={index} className="my-2 border-t border-border/60 mx-2" />
                                );
                            }
                            return (
                                <div key={index} className="px-3 pt-4 pb-1">
                                    <p className="text-[11px] font-bold text-muted-foreground/70 uppercase tracking-widest">
                                        {item.label}
                                    </p>
                                </div>
                            );
                        }

                        if (item.type === 'dropdown') {
                            return (
                                <NavDropdown
                                    key={index}
                                    item={item}
                                    isCollapsed={collapsed}
                                    url={url}
                                    permissions={auth.permissions}
                                />
                            );
                        }

                        const active = item.route ? isActive(item.route) : false;
                        return <NavLink key={index} item={item} active={active} isCollapsed={collapsed} />;
                    })}
                </nav>
            </div>

            {/* Footer User Info */}
            <div className="shrink-0 border-t p-2.5 bg-background">
                {collapsed ? (
                    <Tooltip>
                        <TooltipTrigger className="mx-auto">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-muted/70 hover:bg-muted cursor-pointer border shadow-sm transition-all">
                                {admin?.avatar_path ? (
                                    <img src={`/storage/${admin.avatar_path}`} alt="Avatar" className="h-full w-full rounded-xl object-cover" />
                                ) : (
                                    <User className="h-4 w-4 text-sky-600 dark:text-sky-400" />
                                )}
                            </div>
                        </TooltipTrigger>
                        <TooltipContent side="right" className="flex flex-col gap-0.5 text-xs bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900">
                            <span className="font-semibold">{admin?.name || admin?.username || 'Admin'}</span>
                            <span className="text-[10px] text-zinc-400 dark:text-zinc-500">{admin?.email || ''}</span>
                        </TooltipContent>
                    </Tooltip>
                ) : (
                    <div className="rounded-xl bg-muted/50 border border-border/50 p-2.5">
                        <div className="flex items-center gap-2.5">
                            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-sky-500/10 overflow-hidden">
                                {admin?.avatar_path ? (
                                    <img src={`/storage/${admin.avatar_path}`} alt="Avatar" className="h-full w-full object-cover" />
                                ) : (
                                    <User className="h-4 w-4 text-sky-600 dark:text-sky-400" />
                                )}
                            </div>
                            <div className="min-w-0">
                                <p className="text-xs font-semibold text-foreground truncate">{admin?.name || admin?.username || 'Admin'}</p>
                                <p className="text-[10px] text-muted-foreground truncate">{admin?.email || ''}</p>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );

    const bottomNavItems: BottomNavItem[] = [
        { label: 'Dasbor', icon: LayoutDashboard, route: '/admin/dashboard' },
        { label: 'Tiket', icon: Ticket, route: '/admin/tiket' },
        { label: 'Monitor', icon: Grid3X3, route: '/admin/monitor' },
        { label: 'CSAT', icon: Star, route: '/admin/csat' },
        { label: 'Lainnya', icon: MoreHorizontal, onClick: () => setSidebarOpen(true) },
    ];

    return (
        <div className={`grid h-screen w-full overflow-hidden transition-all duration-300 ease-in-out ${isCollapsed ? 'md:grid-cols-[72px_1fr]' : 'md:grid-cols-[250px_1fr]'
            }`}>
            <Toaster position="top-center" toastOptions={{ duration: 4000 }} />
            {title && <Head title={title} />}

            {/* Mobile Sheet Sidebar */}
            <Sheet open={sidebarOpen} onOpenChange={setSidebarOpen}>
                <SheetContent side="left" className="flex flex-col p-0 w-72">
                    {renderSidebar(false)}
                </SheetContent>
            </Sheet>

            {/* Desktop Sidebar (Collapsible) */}
            <div className="relative hidden border-r bg-white dark:bg-zinc-950 md:flex flex-col h-full max-h-screen overflow-visible transition-all duration-300 ease-in-out">
                {renderSidebar(isCollapsed)}

                {/* Floating Collapse/Expand Toggle Button */}
                <Button
                    variant="outline"
                    size="icon"
                    onClick={toggleCollapse}
                    className="absolute -right-3.5 top-5 z-30 hidden md:flex h-7 w-7 rounded-full border bg-background shadow-md hover:bg-accent text-foreground transition-transform hover:scale-110"
                    title={isCollapsed ? "Buka Sidebar" : "Tutup Sidebar"}
                >
                    {isCollapsed ? (
                        <ChevronRight className="h-3.5 w-3.5 text-sky-600 dark:text-sky-400" />
                    ) : (
                        <ChevronLeft className="h-3.5 w-3.5 text-sky-600 dark:text-sky-400" />
                    )}
                </Button>
            </div>

            {/* Main Content Area */}
            <div className="flex flex-col min-w-0 overflow-hidden bg-zinc-50/50 dark:bg-zinc-900/50">
                <header className="relative z-40 flex h-14 shrink-0 items-center gap-3 border-b bg-white/80 dark:bg-zinc-950/80 backdrop-blur-md supports-[backdrop-filter]:bg-white/60 px-4 lg:h-[60px] lg:px-6">
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
                                Ubah Profil
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
