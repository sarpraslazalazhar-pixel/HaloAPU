import React, { useState, useEffect } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import toast, { Toaster } from 'react-hot-toast';
import { 
    LayoutDashboard, 
    PlusCircle, 
    History, 
    Star, 
    Monitor, 
    Menu,
    LogOut,
    User
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTrigger } from '@/components/ui/sheet';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { ThemeToggle } from '@/Components/ThemeToggle';

interface UserLayoutProps {
    children: React.ReactNode;
    title?: string;
}

const userNavItems = [
    { label: 'Dashboard', icon: LayoutDashboard, route: '/dashboard', routeName: 'dashboard' },
    { label: 'Ajukan Tiket', icon: PlusCircle, route: '/tiket/buat', routeName: 'tiket.create' },
    { label: 'Riwayat Tiket', icon: History, route: '/tiket/riwayat', routeName: 'tiket.riwayat' },
    { label: 'CSAT', icon: Star, route: '#', routeName: 'csat', disabled: true, badge: 'Segera' },
    { label: 'Live Monitor', icon: Monitor, route: '#', routeName: 'live-monitor', disabled: true, badge: 'Segera' },
];

export default function UserLayout({ children, title }: UserLayoutProps) {
    const { auth, flash } = usePage<any>().props;
    const user = auth.user;

    useEffect(() => {
        if (flash?.success) toast.success(flash.success, { id: 'flash-success' });
        if (flash?.error) toast.error(flash.error, { id: 'flash-error' });
        if (flash?.message) toast(flash.message, { id: 'flash-message' });
    }, [flash]);
    
    const url = usePage().url;
    const isActive = (routePath: string) => url.startsWith(routePath);

    const SidebarContent = () => (
        <div className="flex h-full flex-col gap-4">
            <div className="flex h-14 items-center border-b px-4 lg:h-[60px] lg:px-6">
                <Link href="/" className="flex items-center gap-2 font-semibold">
                    <div className="w-8 h-8 bg-[#00a2e8] rounded-full flex items-center justify-center relative overflow-hidden">
                        <div className="absolute top-1/4 left-1/4 w-1.5 h-1.5 bg-white rounded-full"></div>
                        <div className="absolute top-1/4 right-1/4 w-1.5 h-1.5 bg-[#f39c12] rounded-full"></div>
                        <div className="absolute bottom-1/4 w-4 h-2 border-b-2 border-white rounded-full"></div>
                    </div>
                    <span className="text-[#1a2b4c] dark:text-white">HALO APU</span>
                </Link>
            </div>
            <div className="flex-1 overflow-auto py-2">
                <nav className="grid items-start px-2 text-sm font-medium lg:px-4">
                    {userNavItems.map((item, index) => {
                        const Icon = item.icon;
                        const active = isActive(item.route) && item.route !== '#';
                        
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
            
            {/* Desktop Sidebar */}
            <div className="hidden border-r bg-muted/40 md:flex flex-col overflow-y-auto">
                <SidebarContent />
            </div>
            
            <div className="flex flex-col min-w-0 overflow-hidden">
                <header className="flex h-14 shrink-0 items-center gap-4 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60 px-4 lg:h-[60px] lg:px-6">
                    {/* Mobile Sidebar */}
                    <Sheet>
                        <SheetTrigger asChild>
                            <Button
                                variant="outline"
                                size="icon"
                                className="shrink-0 md:hidden"
                            >
                                <Menu className="h-5 w-5" />
                                <span className="sr-only">Toggle navigation menu</span>
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="left" className="flex flex-col p-0 w-72">
                            <SidebarContent />
                        </SheetContent>
                    </Sheet>
                    
                    <div className="w-full flex-1">
                        {/* Search or breadcrumbs could go here */}
                    </div>
                    
                    <ThemeToggle />
                    
                    <Link href="/logout" method="post" as="button" className="text-sm font-medium text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 px-3 py-2 rounded-md transition-colors flex items-center gap-2">
                        <LogOut className="h-4 w-4" />
                        <span className="hidden sm:inline">Keluar</span>
                    </Link>

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="secondary" size="icon" className="rounded-full">
                                <User className="h-5 w-5" />
                                <span className="sr-only">Toggle user menu</span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuLabel>
                                <div className="flex flex-col">
                                    <span>{user?.username || 'User'}</span>
                                    <span className="text-xs font-normal text-muted-foreground">{user?.email || 'user@example.com'}</span>
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
