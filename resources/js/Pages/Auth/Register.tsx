import { Head, Link, usePage } from '@inertiajs/react';
import React from 'react';

export default function Register() {
    const { appConfig } = usePage<any>().props;
    return (
        <>
            <Head title="Daftar Akun - Halo APU" />
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
                    
                    {/* Register Info Card */}
                    <section className="bg-white p-8 md:p-10 rounded-2xl shadow-2xl w-full max-w-md text-center">
                        {/* Logo and Heading */}
                        <div className="flex flex-col items-center mb-8">
                            {appConfig?.logo_path ? (
                                <img src={`/storage/${appConfig.logo_path}`} alt="Halo APU Logo" className="w-full max-w-[240px] h-24 object-contain" />
                            ) : (
                                <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuABorF6Z-kfxPybny-hrgcEUGX2NFFouw0fb6XNnNaWEfuQ_j6gmBM-c-x0soYLgS4uVHRXJ36GRTVi018M0sAdUUa2HxzK6uWkIutYFaTLmGZgKeg97B59xTodfUcGqFBK1SmjJUOzbEUhu4n3_bVQHjN8_DLeV4xxFP1WkjWxPzfwZ_RoU6lcFJnU2zyiRaf24p94qwTx3cm0Ut1QL9sqx6JSumjmndGHLKl1MLW2FaLUDjeSe1ot3WzpJysK9M2bxEKktm91J333" alt="Halo APU Logo" className="w-full max-w-[240px] object-contain" />
                            )}
                        </div>
                        
                        <div className="space-y-6">
                            <div className="rounded-md bg-blue-50 p-4 border border-blue-200">
                                <h3 className="text-sm font-medium text-blue-800">Pendaftaran Akun</h3>
                                <div className="mt-2 text-sm text-blue-700">
                                    <p>
                                        Pendaftaran mandiri tidak tersedia di sistem ini. 
                                        Silakan hubungi Administrator sistem atau pihak HR/IT 
                                        untuk pembuatan akun Halo APU.
                                    </p>
                                </div>
                            </div>
                            
                            <div>
                                <Link
                                    href="/login"
                                    className="inline-flex w-full justify-center rounded-lg border border-gray-300 bg-white py-2 px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-[#00a2e8] focus:ring-offset-2 transition-colors"
                                >
                                    Kembali ke Halaman Login
                                </Link>
                            </div>
                        </div>
                    </section>

                    {/* HeroText */}
                    <section className="hidden md:flex flex-col text-right max-w-lg text-white">
                        <h2 className="text-5xl font-bold mb-6 text-[#0088cc] drop-shadow-sm">Back Office System</h2>
                        <div className="italic text-gray-700 space-y-4">
                            <p className="text-xl">
                                "Ketahuilah bahwa kemenangan bersama kesabaran, kelapangan bersama kesempitan, dan kesulitan bersama kemudahan".
                            </p>
                            <p className="text-lg font-semibold text-[#0088cc]">
                                (HR Tirmidzi)
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
