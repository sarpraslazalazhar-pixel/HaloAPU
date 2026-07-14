import { Head, Link, useForm, usePage } from '@inertiajs/react';
import React, { FormEventHandler, useState, useEffect } from 'react';

export default function ResetPassword({ token, email }: { token: string, email: string }) {
    const { appConfig } = usePage<any>().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    const [showPassword, setShowPassword] = useState(false);
    const [showPasswordConfirm, setShowPasswordConfirm] = useState(false);

    useEffect(() => {
        return () => {
            reset('password', 'password_confirmation');
        };
    }, []);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/reset-password');
    };

    return (
        <>
            <Head title="Reset Password - Halo APU" />
            <style>
                {`
                .login-wrapper {
                  background-image: url('/images/bg-login.png');
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
                            <h3 className="mt-4 text-lg font-semibold text-gray-800">Reset Password</h3>
                        </div>
                        
                        {/* Form */}
                        <form className="space-y-5" onSubmit={submit}>
                            {/* Email */}
                            <div>
                                <label className="block text-xs font-medium text-gray-700 mb-1" htmlFor="email">
                                    Email<span className="text-red-500">*</span>
                                </label>
                                <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg className="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2"></path>
                                        </svg>
                                    </div>
                                    <input 
                                        className="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg bg-gray-100 focus:outline-none text-sm" 
                                        id="email" 
                                        name="email" 
                                        type="email"
                                        value={data.email}
                                        readOnly
                                    />
                                </div>
                                {errors.email && (
                                    <p className="mt-1 text-xs text-red-600">{errors.email}</p>
                                )}
                            </div>
                            
                            {/* Password Field */}
                            <div>
                                <label className="block text-xs font-medium text-gray-700 mb-1" htmlFor="password">
                                    Password Baru<span className="text-red-500">*</span>
                                </label>
                                <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg className="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2"></path>
                                        </svg>
                                    </div>
                                    <input 
                                        className="block w-full pl-10 pr-10 py-2 border border-gray-300 rounded-lg focus:ring-[#0088cc] focus:border-[#0088cc] text-sm" 
                                        id="password" 
                                        name="password" 
                                        placeholder="Min 6 karakter" 
                                        required 
                                        type={showPassword ? 'text' : 'password'}
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                    />
                                    <button 
                                        className="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none" 
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                    >
                                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            {showPassword ? (
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                            ) : (
                                                <>
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </>
                                            )}
                                        </svg>
                                    </button>
                                </div>
                                {errors.password && (
                                    <p className="mt-1 text-xs text-red-600">{errors.password}</p>
                                )}
                            </div>

                            {/* Confirm Password Field */}
                            <div>
                                <label className="block text-xs font-medium text-gray-700 mb-1" htmlFor="password_confirmation">
                                    Konfirmasi Password<span className="text-red-500">*</span>
                                </label>
                                <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg className="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2"></path>
                                        </svg>
                                    </div>
                                    <input 
                                        className="block w-full pl-10 pr-10 py-2 border border-gray-300 rounded-lg focus:ring-[#0088cc] focus:border-[#0088cc] text-sm" 
                                        id="password_confirmation" 
                                        name="password_confirmation" 
                                        placeholder="Ketik ulang password baru" 
                                        required 
                                        type={showPasswordConfirm ? 'text' : 'password'}
                                        value={data.password_confirmation}
                                        onChange={(e) => setData('password_confirmation', e.target.value)}
                                    />
                                    <button 
                                        className="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none" 
                                        type="button"
                                        onClick={() => setShowPasswordConfirm(!showPasswordConfirm)}
                                    >
                                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            {showPasswordConfirm ? (
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                            ) : (
                                                <>
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </>
                                            )}
                                        </svg>
                                    </button>
                                </div>
                                {errors.password_confirmation && (
                                    <p className="mt-1 text-xs text-red-600">{errors.password_confirmation}</p>
                                )}
                            </div>
                            
                            <button 
                                className="w-full bg-[#0088cc] hover:bg-[#0077b3] text-white font-semibold py-3 rounded-lg flex items-center justify-center gap-2 transition duration-200 mt-2 disabled:opacity-70" 
                                type="submit"
                                disabled={processing}
                            >
                                {processing ? 'Memproses...' : 'Simpan Password Baru'}
                            </button>
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
