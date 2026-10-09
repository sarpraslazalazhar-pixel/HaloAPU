import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import React, { FormEventHandler, useEffect, useState } from 'react';
import toast, { Toaster } from 'react-hot-toast';

export default function UserLogin() {
  const { appConfig, flash } = usePage<any>().props;

  const { data, setData, post, processing, errors } = useForm({
    username: '',
    password: '',
    remember: true,
    role: '',
  });

  const [showPassword, setShowPassword] = useState(false);
  const [showRoleModal, setShowRoleModal] = useState(false);
  const [selectedRoleLoading, setSelectedRoleLoading] = useState<string | null>(null);

  useEffect(() => {
    const sessionAlert = sessionStorage.getItem('session_expired_alert');

    if (sessionAlert) {
      toast.error(sessionAlert, { id: 'session-expired-toast', duration: 5000 });
      sessionStorage.removeItem('session_expired_alert');
    }

    if (flash?.error) {
      toast.error(flash.error, { id: 'flash-error' });
    }

    if (flash?.success) {
      toast.success(flash.success, { id: 'flash-success' });
    }

    if (flash?.role_selection_required) {
      setShowRoleModal(true);
    }
  }, [flash]);

  const submit: FormEventHandler = (e) => {
    e.preventDefault();
    post('/login');
  };

  const handleChooseRole = (roleKey: 'user' | 'admin') => {
    setSelectedRoleLoading(roleKey);
    router.post(
      '/login',
      {
        username: data.username,
        password: data.password,
        remember: data.remember,
        role: roleKey,
      },
      {
        preserveState: true,
        onFinish: () => {
          setSelectedRoleLoading(null);
        },
        onError: () => {
          setSelectedRoleLoading(null);
          setShowRoleModal(false);
        },
      }
    );
  };

  return (
    <>
      <Head title="Login - Halo APU" />

      <main
        className="min-h-screen bg-cover bg-center flex items-center justify-center relative p-4 md:p-8 font-sans text-gray-800"
        style={{
          backgroundImage: `url('${appConfig?.banner_path ? `/storage/${appConfig.banner_path}` : '/images/bg-login.webp'}')`
        }}
      >
        <Toaster position="top-center" toastOptions={{ duration: 4000 }} />
        <div className="absolute inset-y-0 left-0 w-1/3 bg-gradient-to-r from-[rgba(0,136,204,0.7)] via-[rgba(0,136,204,0.4)] to-transparent pointer-events-none hidden md:block" />
        <div className="container mx-auto flex flex-col md:flex-row items-center justify-center gap-8 lg:gap-24 relative z-10">

          {/* Login Card */}
          <section className="bg-white p-8 md:p-10 rounded-2xl shadow-2xl w-full max-w-md">
            {/* Logo and Heading */}
            <div className="flex flex-col items-center mb-8">
              {appConfig?.logo_path ? (
                <img src={`/storage/${appConfig.logo_path}`} alt="Halo APU Logo" width={240} height={96} loading="eager" fetchPriority="high" className="w-full max-w-[240px] h-24 object-contain" />
              ) : (
                <img src="/images/logo.png" alt="Halo APU Logo" width={240} height={96} loading="eager" fetchPriority="high" className="w-full max-w-[240px] h-24 object-contain" />
              )}
            </div>

            {/* Login Form */}
            <form className="space-y-6" onSubmit={submit}>
              {/* Username/Email Field */}
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1" htmlFor="username">
                  Username/Email<span className="text-red-500">*</span>
                </label>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg className="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2"></path>
                    </svg>
                  </div>
                  <input
                    className="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-[#0088cc] focus:border-[#0088cc] text-sm"
                    id="username"
                    name="username"
                    autoComplete="username"
                    placeholder="Username/email"
                    required
                    type="text"
                    value={data.username}
                    onChange={(e) => setData('username', e.target.value)}
                  />
                </div>
                {errors.username && (
                  <p className="mt-1 text-xs text-red-600">{errors.username}</p>
                )}
              </div>

              {/* Password Field */}
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1" htmlFor="password">
                  Password<span className="text-red-500">*</span>
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
                    autoComplete="current-password"
                    placeholder="password"
                    required
                    type={showPassword ? 'text' : 'password'}
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                  />
                  <button
                    className="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none"
                    type="button"
                    aria-label={showPassword ? 'Sembunyikan password' : 'Tampilkan password'}
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

              {/* Remember & Forgot Password */}
              <div className="flex items-center justify-between mt-4">
                <div className="flex items-center gap-2">
                  <input
                    className="h-4 w-4 text-[#0088cc] rounded border-gray-300 focus:ring-[#0088cc]"
                    id="remember"
                    type="checkbox"
                    checked={data.remember}
                    onChange={(e) => setData('remember', e.target.checked)}
                  />
                  <label className="text-xs text-gray-600 font-medium cursor-pointer" htmlFor="remember">Ingat saya</label>
                </div>
                <Link className="text-xs font-medium text-[#006da3] hover:underline py-1.5 px-1 inline-block" href="/lupa-password">Lupa password?</Link>
              </div>

              {/* Submit Button */}
              <button
                className="w-full bg-[#0088cc] hover:bg-[#0077b3] active:scale-[0.98] text-white font-semibold py-3 rounded-lg flex items-center justify-center gap-2 transition duration-200 mt-2 disabled:opacity-70"
                type="submit"
                disabled={processing}
              >
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2"></path>
                </svg>
                {processing ? 'Memproses...' : 'Masuk'}
              </button>

              {/* Register Prompt */}
              <div className="mt-6 text-center text-xs text-gray-500">
                Belum punya akun? <Link href="/register" className="font-semibold text-[#006da3] py-1 px-1 inline-block">Hubungi Admin</Link>
              </div>
            </form>
          </section>

          {/* HeroText */}
          <section className="hidden md:flex flex-col text-right max-w-lg text-white animate-[page-in_0.45s_ease-out]">
            <h1 className="text-3xl font-bold mb-6 text-[#0088cc] drop-shadow-sm">PLATFORM LAYANAN TERPADU</h1>
            <div className="italic text-gray-700 space-y-4">
              <p className="text-xl">
                Karena pelayanan terbaik kepada sesama amil dan nadzir adalah kunci utama mempercepat dan menyempurnakan pelayanan terbaik kita kepada umat.
              </p>
            </div>
          </section>
        </div>

        {/* Role Selection Modal */}
        {showRoleModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-[fadeIn_0.2s_ease-out]">
            <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 space-y-5 border border-gray-100">
              <div className="text-center space-y-1.5">
                <div className="w-12 h-12 bg-sky-50 text-[#0088cc] rounded-full flex items-center justify-center mx-auto mb-2">
                  <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                  </svg>
                </div>
                <h2 className="text-lg font-bold text-gray-900">Pilih Peran Masuk</h2>
                <p className="text-xs text-gray-600">
                  Akun kamu terdaftar sebagai <strong>Pengaju</strong> dan <strong>Admin / Operator</strong>. Pilih peran yang ingin kamu gunakan:
                </p>
              </div>

              <div className="space-y-3">
                <button
                  type="button"
                  disabled={selectedRoleLoading !== null}
                  onClick={() => handleChooseRole('user')}
                  className="w-full text-left p-4 rounded-xl border-2 border-gray-200 hover:border-[#0088cc] hover:bg-sky-50/50 transition duration-150 flex items-center gap-4 group focus:outline-none focus:ring-2 focus:ring-[#0088cc] disabled:opacity-60"
                >
                  <div className="w-10 h-10 rounded-lg bg-blue-100 text-[#0088cc] flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform">
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="font-semibold text-sm text-gray-900 group-hover:text-[#0088cc]">
                      Masuk sebagai User
                    </div>
                    <div className="text-xs text-gray-500 truncate">
                      Buat tiket, pantau progres & layanan
                    </div>
                  </div>
                  {selectedRoleLoading === 'user' ? (
                    <span className="text-xs text-[#0088cc] font-medium">Memproses...</span>
                  ) : (
                    <svg className="w-5 h-5 text-gray-400 group-hover:text-[#0088cc] group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7" />
                    </svg>
                  )}
                </button>

                <button
                  type="button"
                  disabled={selectedRoleLoading !== null}
                  onClick={() => handleChooseRole('admin')}
                  className="w-full text-left p-4 rounded-xl border-2 border-gray-200 hover:border-[#0088cc] hover:bg-sky-50/50 transition duration-150 flex items-center gap-4 group focus:outline-none focus:ring-2 focus:ring-[#0088cc] disabled:opacity-60"
                >
                  <div className="w-10 h-10 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform">
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="font-semibold text-sm text-gray-900 group-hover:text-indigo-600">
                      Masuk sebagai Admin / Operator
                    </div>
                    <div className="text-xs text-gray-500 truncate">
                      Kelola tiket, tindak lanjut, & sistem
                    </div>
                  </div>
                  {selectedRoleLoading === 'admin' ? (
                    <span className="text-xs text-indigo-600 font-medium">Memproses...</span>
                  ) : (
                    <svg className="w-5 h-5 text-gray-400 group-hover:text-indigo-600 group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7" />
                    </svg>
                  )}
                </button>
              </div>

              <div className="pt-2 border-t border-gray-100 flex justify-end">
                <button
                  type="button"
                  onClick={() => setShowRoleModal(false)}
                  disabled={selectedRoleLoading !== null}
                  className="px-4 py-2 text-xs font-medium text-gray-600 hover:text-gray-900 rounded-lg hover:bg-gray-100 transition"
                >
                  Batal
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Footer */}
        <footer className="absolute bottom-4 left-0 right-0 text-center text-gray-500 text-xs">
          © 2026 Halo APU - Al Azhar Peduli Umat. All rights reserved.
        </footer>
      </main>
    </>
  );
}
