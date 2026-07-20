import React, { useState, useEffect } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import toast, { Toaster } from 'react-hot-toast';
import { 
    LayoutDashboard, 
    PlusCircle, 
    History, 
    Star, 
    LogOut,
    User,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from "@/Components/ui/dropdown-menu";
import { ThemeToggle } from '@/Components/ThemeToggle';
import ProfileModal from '@/Components/ProfileModal';
import { BottomNav } from '@/Components/BottomNav';
import type { BottomNavItem } from '@/Components/BottomNav';
import { useIdleTimer } from '@/hooks/useIdleTimer';

interface UserLayoutProps {
    children: React.ReactNode;
    title?: string;
}

interface NavItem {
    label: string;
    icon: any;
    route: string;
}

const userNavItems: NavItem[] = [
    { label: 'Dashboard', icon: LayoutDashboard, route: '/dashboard' },
    { label: 'Ajukan Tiket', icon: PlusCircle, route: '/tiket/buat' },
    { label: 'Riwayat Tiket', icon: History, route: '/tiket/riwayat' },
    { label: 'CSAT', icon: Star, route: '/csat/riwayat' },
];

function NavLink({ item, active }: { item: NavItem; active: boolean }) {
    const Icon = item.icon;
    return (
        <Link
            href={item.route}
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

export default function UserLayout({ children, title }: UserLayoutProps) {
    const { auth, flash, appConfig } = usePage<any>().props;
    const user = auth.user;
    const [profileOpen, setProfileOpen] = useState(false);

    useIdleTimer('/logout');

    useEffect(() => {
        if (flash?.success) toast.success(flash.success, { id: 'flash-success' });
        if (flash?.error) toast.error(flash.error, { id: 'flash-error' });
        if (flash?.message) toast(flash.message, { id: 'flash-message' });
    }, [flash]);
    
    const url = usePage().url;
    const systemName = appConfig?.nama_sistem || 'HALO APU';

    const SidebarContent = () => (
        <div className="flex h-full flex-col">
            <div className="flex h-14 shrink-0 items-center gap-2.5 border-b px-5 lg:h-[60px]">
                <Link href="/dashboard" className="flex items-center gap-2.5 min-w-0">
                    {appConfig?.logo_path && (
                        <img id="displayBannerImg" src={`/storage/${appConfig.logo_path}`} alt="Banner" style={{ height: '55px', width: 'auto', objectFit: 'contain', display: 'inline-block' }} />
                    )}
                </Link>
            </div>
            <div className="flex-1 overflow-y-auto py-4 px-3 sidebar-scroll">
                <nav className="flex flex-col gap-1">
                    {userNavItems.map((item) => (
                        <NavLink
                            key={item.route}
                            item={item}
                            active={url.startsWith(item.route) && item.route !== '#'}
                        />
                    ))}
                </nav>
            </div>

            <div className="border-t px-3 py-3">
                <div className="rounded-lg bg-muted/60 px-3 py-3">
                    <div className="flex items-center gap-2.5">
                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 overflow-hidden">
                            {user?.avatar_path ? (
                                <img src={`/storage/${user.avatar_path}`} alt="Avatar" className="h-full w-full object-cover" />
                            ) : (
                                <User className="h-3.5 w-3.5 text-primary" />
                            )}
                        </div>
                        <div className="min-w-0">
                            <p className="text-xs font-medium text-foreground truncate">{user?.name || user?.username || 'User'}</p>
                            <p className="text-[11px] text-muted-foreground truncate">{user?.email || ''}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );

    const bottomNavItems: BottomNavItem[] = [
        { label: 'Dashboard', icon: LayoutDashboard, route: '/dashboard' },
        { label: 'Buat Tiket', icon: PlusCircle, route: '/tiket/buat' },
        { label: 'Riwayat', icon: History, route: '/tiket/riwayat' },
        { label: 'CSAT', icon: Star, route: '/csat/riwayat' },
    ];

    return (
        <div className="grid h-screen w-full overflow-hidden md:grid-cols-[240px_1fr] lg:grid-cols-[260px_1fr]">
            <Toaster position="top-center" toastOptions={{ duration: 4000 }} />
            {title && <Head title={title} />}
            
            <div className="hidden border-r bg-white dark:bg-zinc-950 md:flex flex-col overflow-hidden">
                <SidebarContent />
            </div>
            
            <div className="flex flex-col min-w-0 overflow-hidden bg-zinc-50/50 dark:bg-zinc-900/50">
                <header className="flex h-14 shrink-0 items-center gap-3 border-b bg-white/80 dark:bg-zinc-950/80 backdrop-blur-md supports-[backdrop-filter]:bg-white/60 px-4 lg:h-[60px] lg:px-6">
                    <div className="flex-1" />
                    
                    <ThemeToggle />
                    
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="rounded-full h-8 w-8 border overflow-hidden">
                                {user?.avatar_path ? (
                                    <img src={`/storage/${user.avatar_path}`} alt="Avatar" className="h-full w-full object-cover" />
                                ) : (
                                    <User className="h-4 w-4" />
                                )}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                            <DropdownMenuGroup>
                                <DropdownMenuLabel>
                                    <div className="flex flex-col gap-0.5">
                                        <span className="text-sm font-medium">{user?.name || user?.username || 'User'}</span>
                                        <span className="text-xs font-normal text-muted-foreground">{user?.email || ''}</span>
                                    </div>
                                </DropdownMenuLabel>
                            </DropdownMenuGroup>
                            <div className="border-t my-1" />
                            <DropdownMenuItem onClick={() => setProfileOpen(true)} className="cursor-pointer">
                                <User className="h-4 w-4 mr-2" />
                                Edit Profil
                            </DropdownMenuItem>
                            <DropdownMenuItem className="p-0">
                                <Link href="/logout" method="post" as="button" className="flex w-full cursor-pointer items-center gap-2 px-2 py-1.5 text-sm text-red-600 transition-colors hover:bg-red-50 dark:hover:bg-red-950/30 rounded-md">
                                    <LogOut className="h-4 w-4" />
                                    Keluar
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <ProfileModal open={profileOpen} onOpenChange={setProfileOpen} user={user} isAdmin={false} />
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
