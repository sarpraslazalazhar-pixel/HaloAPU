import { Head, Link, useForm, usePage } from '@inertiajs/react';
import React, { FormEventHandler } from 'react';

export default function ForgotPassword({ status }: { status?: string }) {
 const { appConfig } = usePage<any>().props;
 const { data, setData, post, processing, errors } = useForm({
 email: '',
 });

 const submit: FormEventHandler = (e) => {
 e.preventDefault();
 post('/lupa-password');
 };

 return (
 <>
 <Head title="Lupa Password - Halo APU" />
 <style>
 {`
 .login-wrapper {
 background-image: url('${appConfig?.banner_path ?`/storage/${appConfig.banner_path}`: '/images/bg-login.png'}');
 background-size: cover;
 background-position: center;
 min-height: 100vh;
 display: flex;
 align-items: center;
 justify-content: center;
 position: relative;
 }
 .pattern-overlay {
 position: absolute;
 top: 0;
 left: 0;
 bottom: 0;
 width: 33%;
 background: linear-gradient(90deg, rgba(0, 136, 204, 0.7) 0%, rgba(0, 136, 204, 0.4) 50%, transparent 100%);
 pointer-events: none;
 }
`}
 </style>
 
 <main className="login-wrapper p-4 md:p-8 font-sans text-gray-800">
 <div className="pattern-overlay hidden md:block"></div>
 <div className="container mx-auto flex flex-col md:flex-row items-center justify-center gap-8 lg:gap-24 relative z-10">
 
 {/* Card */}
 <section className="bg-white p-8 md:p-10 rounded-2xl shadow-2xl w-full max-w-md">
 {/* Logo and Heading */}
 <div className="flex flex-col items-center mb-8">
 {appConfig?.logo_path ? (
 <img src={`/storage/${appConfig.logo_path}`} alt="Halo APU Logo" className="w-full max-w-[240px] h-24 object-contain" />
 ) : (
 <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuABorF6Z-kfxPybny-hrgcEUGX2NFFouw0fb6XNnNaWEfuQ_j6gmBM-c-x0soYLgS4uVHRXJ36GRTVi018M0sAdUUa2HxzK6uWkIutYFaTLmGZgKeg97B59xTodfUcGqFBK1SmjJUOzbEUhu4n3_bVQHjN8_DLeV4xxFP1WkjWxPzfwZ_RoU6lcFJnU2zyiRaf24p94qwTx3cm0Ut1QL9sqx6JSumjmndGHLKl1MLW2FaLUDjeSe1ot3WzpJysK9M2bxEKktm91J333" alt="Halo APU Logo" className="w-full max-w-[240px] object-contain" />
 )}
 <h3 className="mt-4 text-lg font-semibold text-gray-800">Lupa Password</h3>
 </div>

 <div className="mb-6 text-sm text-gray-600 text-center">
 Lupa password Anda? Tidak masalah. Beri tahu kami alamat email Anda dan kami akan mengirimi Anda link reset password.
 </div>

 {status && (
 <div className="mb-4 font-medium text-sm text-green-600 text-center bg-green-50 py-2 rounded">
 {status}
 </div>
 )}
 
 {/* Form */}
 <form className="space-y-6" onSubmit={submit}>
 <div>
 <label className="block text-xs font-medium text-gray-700 mb-1" htmlFor="email">
 Email Terdaftar<span className="text-red-500">*</span>
 </label>
 <div className="relative">
 <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
 <svg className="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2"></path>
 </svg>
 </div>
 <input 
 className="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-[#0088cc] focus:border-[#0088cc] text-sm" 
 id="email" 
 name="email" 
 placeholder="Masukkan email Anda" 
 required 
 type="email"
 value={data.email}
 onChange={(e) => setData('email', e.target.value)}
 />
 </div>
 {errors.email && (
 <p className="mt-1 text-xs text-red-600">{errors.email}</p>
 )}
 </div>
 
 <button 
 className="w-full bg-[#0088cc] hover:bg-[#0077b3] text-white font-semibold py-3 rounded-lg flex items-center justify-center gap-2 transition duration-200 mt-2 disabled:opacity-70" 
 type="submit"
 disabled={processing}
 >
 {processing ? 'Mengirim...' : 'Kirim Link Reset'}
 </button>
 
 <div className="mt-4 text-center">
 <Link href="/login" className="text-sm font-medium text-[#0088cc] hover:underline">
 Kembali ke halaman login
 </Link>
 </div>
 </form>
 </section>

 {/* HeroText */}
 <section className="hidden md:flex flex-col text-right max-w-lg text-white">
 <h4 className="text-3xl font-bold mb-6 text-[#0088cc] drop-shadow-sm">PLATFORM LAYANAN TERPADU</h4>
 <div className="italic text-gray-700 space-y-4">
 <p className="text-xl">
 Karena pelayanan terbaik kepada sesama amil dan nadzir adalah kunci utama mempercepat dan menyempurnakan pelayanan terbaik kita kepada umat.
 </p>
 </div>
 </section>
 </div>

 {/* Footer */}
 <footer className="absolute bottom-4 left-0 right-0 text-center text-gray-500 text-xs">
 © 2026 Halo APU - Al Azhar Peduli Umat. All rights reserved.
 </footer>
 </main>
 </>
 );
}
