import React from 'react';
import { Head, Link, usePage } from '@inertiajs/react';


interface GuestLayoutProps {
 children: React.ReactNode;
 title?: string;
}

export default function GuestLayout({ children, title }: GuestLayoutProps) {
 const { appConfig } = usePage<any>().props;

 return (
 <div className="min-h-screen flex flex-col items-center justify-center bg-gradient-to-br from-blue-50 via-white to-sky-100 px-4">
 {title && <Head title={title} />}



 <div className="w-full max-w-md">
 <div className="mb-8 text-center">
 <Link href="/" className="inline-flex items-center gap-2 font-semibold text-xl">
 {appConfig?.logo_path ? (
 <img src={`/storage/${appConfig.logo_path}`} alt="Logo" className="h-10 object-contain" />
 ) : (
 <div className="w-10 h-10 bg-[#00a2e8] rounded-full flex items-center justify-center relative overflow-hidden shrink-0">
 <div className="absolute top-1/4 left-1/4 w-2 h-2 bg-white rounded-full"></div>
 <div className="absolute top-1/4 right-1/4 w-2 h-2 bg-[#f39c12] rounded-full"></div>
 <div className="absolute bottom-1/4 w-5 h-2.5 border-b-2 border-white rounded-full"></div>
 </div>
 )}
 <span className="text-[#1a2b4c] truncate" title={appConfig?.nama_sistem || 'HALO APU'}>
 {appConfig?.nama_sistem || 'HALO APU'}
 </span>
 </Link>
 </div>

 <div className="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
 {children}
 </div>
 </div>
 </div>
 );
}
