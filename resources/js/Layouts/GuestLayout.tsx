import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { ThemeToggle } from '@/Components/ThemeToggle';

interface GuestLayoutProps {
    children: React.ReactNode;
    title?: string;
}

export default function GuestLayout({ children, title }: GuestLayoutProps) {
    return (
        <div className="min-h-screen flex flex-col items-center justify-center bg-gradient-to-br from-blue-50 via-white to-sky-100 dark:from-gray-900 dark:via-gray-950 dark:to-slate-900 px-4">
            {title && <Head title={title} />}

            <div className="absolute top-4 right-4">
                <ThemeToggle />
            </div>

            <div className="w-full max-w-md">
                <div className="mb-8 text-center">
                    <Link href="/" className="inline-flex items-center gap-2 font-semibold text-xl">
                        <div className="w-10 h-10 bg-[#00a2e8] rounded-full flex items-center justify-center relative overflow-hidden">
                            <div className="absolute top-1/4 left-1/4 w-2 h-2 bg-white rounded-full"></div>
                            <div className="absolute top-1/4 right-1/4 w-2 h-2 bg-[#f39c12] rounded-full"></div>
                            <div className="absolute bottom-1/4 w-5 h-2.5 border-b-2 border-white rounded-full"></div>
                        </div>
                        <span className="text-[#1a2b4c] dark:text-white">HALO APU</span>
                    </Link>
                </div>

                <div className="bg-white dark:bg-gray-900 rounded-xl shadow-lg border border-gray-200 dark:border-gray-800 p-6">
                    {children}
                </div>
            </div>
        </div>
    );
}
