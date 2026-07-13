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
    Menu,
    LogOut,
    ChevronDown,
    User
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Sheet, SheetContent, SheetTrigger } from '@/Components/ui/sheet';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/Components/ui/dropdown-menu";
import { ThemeToggle } from '@/Components/ThemeToggle';
import NotificationBell from '@/Components/NotificationBell';

interface AdminLayoutProps {
    children: React.ReactNode;
    title?: string;
}

interface NavItem {
    label: string;
    icon?: any;
    route?: string;
    routeName?: string;
    disabled?: boolean;
    badge?: React.ReactNode;
    children?: NavItem[];
}

const adminNavItems: NavItem[] = [
    { label: 'Dashboard', icon: LayoutDashboard, route: '/admin/dashboard', routeName: 'admin.dashboard' },
    { label: 'Tiketing', icon: Ticket, route: '/admin/tiket', routeName: 'admin.tiket.index' },
    { 
        label: 'Master Data', 
        icon: Database, 
        children: [
            { label: 'Unit', route: '/admin/master/unit', routeName: 'admin.master.unit.index' },
            { label: 'Sub Unit', route: '/admin/master/sub-unit', routeName: 'admin.master.sub-unit.index' },
            { label: 'Divisi', route: '/admin/master/divisi', routeName: 'admin.master.divisi.index' },
            { label: 'Unit Organisasi', route: '/admin/master/unit-organisasi', routeName: 'admin.master.unit-organisasi.index' },
            { label: 'Jabatan', route: '/admin/master/jabatan', routeName: 'admin.master.jabatan.index' },
        ]
    },
    { label: 'Peraturan Form', icon: FileEdit, route: '/admin/peraturan-form', routeName: 'admin.peraturan-form.index' },
    { label: 'SLA', icon: Clock, route: '/admin/sla-config', routeName: 'admin.sla-config.index' },
    { label: 'Reminder', icon: Bell, route: '/admin/reminder-config', routeName: 'admin.reminder-config.index' },
    { label: 'CSAT', icon: Star, route: '/admin/csat', routeName: 'admin.csat.index' },
    { label: 'Konfigurasi', icon: Settings, route: '/admin/konfigurasi', routeName: 'admin.konfigurasi.index' },
    { label: 'Admin Mgt', icon: Shield, route: '/admin/manajemen-admin', routeName: 'admin.manajemen-admin.index' },
    { label: 'User Mgt', icon: Users, route: '/admin/manajemen-user', routeName: 'admin.manajemen-user.index' },
];

export default function AdminLayout({ children, title }: AdminLayoutProps) {
    const { auth, flash } = usePage<any>().props;
    const admin = auth.admin || auth.user;
    const [openMenus, setOpenMenus] = useState<Record<string, boolean>>({});

    useEffect(() => {
        if (flash?.success) toast.success(flash.success, { id: 'flash-success' });
        if (flash?.error) toast.error(flash.error, { id: 'flash-error' });
        if (flash?.message) toast(flash.message, { id: 'flash-message' });
    }, [flash]);
    
    const url = usePage().url;
    const isActive = (routePath: string) => url.startsWith(routePath);
    const isChildActive = (children: any[]) => children.some(c => isActive(c.route));

    const toggleMenu = (label: string) => {
        setOpenMenus(prev => ({ ...prev, [label]: !prev[label] }));
    };

    const SidebarContent = () => (
        <div className="flex h-full flex-col gap-4">
            <div className="flex h-14 items-center border-b px-4 lg:h-[60px] lg:px-6">
                <Link href="/admin/dashboard" className="flex items-center gap-2 font-semibold">
                    <div className="w-8 h-8 bg-[#00a2e8] rounded-full flex items-center justify-center relative overflow-hidden">
                        <div className="absolute top-1/4 left-1/4 w-1.5 h-1.5 bg-white rounded-full"></div>
                        <div className="absolute top-1/4 right-1/4 w-1.5 h-1.5 bg-[#f39c12] rounded-full"></div>
                        <div className="absolute bottom-1/4 w-4 h-2 border-b-2 border-white rounded-full"></div>
                    </div>
                    <span className="text-[#1a2b4c] dark:text-white">HALO APU <span className="text-sm font-normal text-muted-foreground ml-1">Admin</span></span>
                </Link>
            </div>
            <div className="flex-1 overflow-auto py-2">
                <nav className="grid items-start px-2 text-sm font-medium lg:px-4">
                    {adminNavItems.map((item, index) => {
        const Icon = item.icon;
        const active = item.route ? (isActive(item.route) && item.route !== '#') : isChildActive(item.children || []);
        const hasToggled = item.label in openMenus;
        const isOpen = hasToggled ? openMenus[item.label] : active;
                        
                        if (item.children) {
                            return (
                                <div key={index} className="flex flex-col">
                                    <button
                                        onClick={() => toggleMenu(item.label)}
                                        className={`flex items-center gap-3 rounded-lg px-3 py-2 transition-all hover:text-primary ${
                                            active ? 'text-primary' : 'text-muted-foreground'
                                        }`}
                                    >
                                        <Icon className="h-4 w-4" />
                                        {item.label}
                                        <ChevronDown className={`ml-auto h-4 w-4 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
                                    </button>
                                    {isOpen && (
                                        <div className="ml-4 mt-1 grid gap-1 border-l pl-2">
                                            {item.children.map((child, cIndex) => {
                                                const childActive = isActive(child.route || '');
                                                return (
                                                    <Link
                                                        key={cIndex}
                                                        href={child.route}
                                                        className={`flex items-center gap-3 rounded-lg px-3 py-2 transition-all hover:text-primary ${
                                                            childActive ? 'bg-muted text-primary' : 'text-muted-foreground'
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
                        
                        return item.disabled ? (
                            <div key={index} className="flex items-center gap-3 rounded-lg px-3 py-2 text-muted-foreground transition-all">
                                <Icon className="h-4 w-4" />
                                {item.label}
                                {item.badge && (
                                    <span className="ml-auto flex h-6 shrink-0 items-center justify-center rounded-full bg-muted px-2 text-xs">
                                        {item.badge}
                                    </span>
                                )}
                            </div>
                        ) : (
                            <Link
                                key={index}
                                href={item.route}
                                className={`flex items-center gap-3 rounded-lg px-3 py-2 transition-all hover:text-primary ${
                                    active ? 'bg-muted text-primary' : 'text-muted-foreground'
                                }`}
                            >
                                <Icon className="h-4 w-4" />
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>
            </div>
        </div>
    );

    return (
        <div className="grid h-screen w-full overflow-hidden md:grid-cols-[220px_1fr] lg:grid-cols-[280px_1fr]">
            <Toaster position="top-right" toastOptions={{ duration: 4000 }} />
            {title && <Head title={title} />}
            
            <div className="hidden border-r bg-muted/40 md:flex flex-col overflow-y-auto">
                <SidebarContent />
            </div>
            
            <div className="flex flex-col min-w-0 overflow-hidden">
                <header className="flex h-14 shrink-0 items-center gap-4 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 px-4 lg:h-[60px] lg:px-6">
                    <Sheet>
                        <SheetTrigger
                            render={
                                <Button
                                    variant="outline"
                                    size="icon"
                                    className="shrink-0 md:hidden"
                                />
                            }
                        >
                            <Menu className="h-5 w-5" />
                            <span className="sr-only">Toggle navigation menu</span>
                        </SheetTrigger>
                        <SheetContent side="left" className="flex flex-col p-0 w-72">
                            <SidebarContent />
                        </SheetContent>
                    </Sheet>
                    
                    <div className="w-full flex-1"></div>
                    
                    <NotificationBell />
                    <ThemeToggle />
                    
                    <Link href="/admin/logout" method="post" as="button" className="text-sm font-medium text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 px-3 py-2 rounded-md transition-colors flex items-center gap-2">
                        <LogOut className="h-4 w-4" />
                        <span className="hidden sm:inline">Keluar</span>
                    </Link>

                    <DropdownMenu>
                        <DropdownMenuTrigger
                            render={
                                <Button variant="secondary" size="icon" className="rounded-full" />
                            }
                        >
                            <User className="h-5 w-5" />
                            <span className="sr-only">Toggle admin menu</span>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuLabel>
                                <div className="flex flex-col">
                                    <span>{admin?.username || 'Admin'}</span>
                                    <span className="text-xs font-normal text-muted-foreground">{admin?.email || 'admin@haloapu.test'}</span>
                                </div>
                            </DropdownMenuLabel>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </header>
                <main className="flex flex-1 flex-col gap-4 p-4 lg:gap-6 lg:p-6 overflow-y-auto relative">
                    {children}
                </main>
            </div>
        </div>
    );
}
