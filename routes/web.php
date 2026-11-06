<?php

use App\Http\Controllers\Admin\AdminManagementController;
use App\Http\Controllers\Admin\AiBotController;
use App\Http\Controllers\Admin\CsatController as AdminCsatController;
use App\Http\Controllers\Admin\DeviceLockConfigController;
use App\Http\Controllers\Admin\DivisiController;
use App\Http\Controllers\Admin\FormFieldController;
use App\Http\Controllers\Admin\JabatanController;
use App\Http\Controllers\Admin\LaporanOperatorController;
use App\Http\Controllers\Admin\LaporanTiketController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\ReminderConfigController;
use App\Http\Controllers\Admin\RoleManagementController;
use App\Http\Controllers\Admin\SchedulerController;
use App\Http\Controllers\Admin\SlaConfigController;
use App\Http\Controllers\Admin\SubUnitController;
use App\Http\Controllers\Admin\SystemConfigController;
use App\Http\Controllers\Admin\TicketController;
use App\Http\Controllers\Admin\UnitController;
use App\Http\Controllers\Admin\UnitOrganisasiController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Api\DropdownController;
use App\Http\Controllers\Auth\AdminLoginController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\UserLoginController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CsatController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\TvDashboardController;
use App\Http\Controllers\User\DashboardController;
use App\Http\Controllers\User\ProfileController;
use App\Http\Controllers\User\TicketHistoryController;
use App\Http\Controllers\User\TicketWizardController;
use App\Http\Controllers\WebPushController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

Route::get('/', HomeController::class);

// TV Dashboard (Public)
Route::get('/tv', [TvDashboardController::class, 'index'])->name('tv.index');
Route::get('/system/notification-sound', [SystemConfigController::class, 'serveNotificationSound'])->name('system.notification-sound');

// Storage fallback when public/storage symlink is missing on Windows
Route::withoutMiddleware(['web'])->get('/storage/{path}', function ($path) {
    $basePath = realpath(storage_path('app/public'));
    $targetPath = storage_path('app/public/'.$path);
    $realPath = realpath($targetPath);

    if (! $realPath || ! $basePath || ! str_starts_with($realPath, $basePath) || is_dir($realPath)) {
        abort(404);
    }

    return response()->file($realPath, [
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->where('path', '.*')->name('storage.local');

// Build asset fallback when web server document root differs from public directory on shared hosting
Route::withoutMiddleware(['web'])->get('/build/{path}', function ($path) {
    $basePath = realpath(public_path('build'));
    $targetPath = public_path('build/'.$path);
    $realPath = realpath($targetPath);

    if (! $realPath || ! $basePath || ! str_starts_with($realPath, $basePath) || is_dir($realPath)) {
        abort(404);
    }

    $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];
    $contentType = $mimeTypes[$extension] ?? (mime_content_type($realPath) ?: 'application/octet-stream');

    return new BinaryFileResponse($realPath, 200, [
        'Content-Type' => $contentType,
        'Cache-Control' => 'public, max-age=31536000, immutable',
        'Access-Control-Allow-Origin' => '*',
    ]);
})->where('path', '.*')->name('build.asset');

Route::middleware('guest')->group(function () {
    Route::get('login', [UserLoginController::class, 'showLoginForm'])->name('login');
    Route::post('login', [UserLoginController::class, 'login'])->middleware('throttle:5,1');

    Route::get('register', [RegisterController::class, 'showForm'])->name('register');

    // Lupa Password
    Route::get('/lupa-password', [ForgotPasswordController::class, 'showForm'])->name('password.request');
    Route::post('/lupa-password', [ForgotPasswordController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [ForgotPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [UserLoginController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/faq', function () {
        return Inertia::render('User/Faq');
    })->name('faq');

    // Tickets Wizard
    Route::get('/tiket', function () {
        return redirect()->route('tiket.create');
    });
    Route::get('/tiket/buat', [TicketWizardController::class, 'create'])->name('tiket.create');
    Route::post('/tiket', [TicketWizardController::class, 'store'])->name('tiket.store');

    // Ticket History
    Route::get('/tiket/riwayat', [TicketHistoryController::class, 'index'])->name('tiket.riwayat');
    Route::get('/tiket/{ticket}', [TicketHistoryController::class, 'show'])->name('tiket.show');
    Route::post('/tiket/{ticket}/reply', [TicketHistoryController::class, 'reply'])->name('tiket.reply');
    Route::post('/tiket/{ticket}/accept-result', [TicketHistoryController::class, 'acceptResult'])->name('tiket.accept-result');
    Route::post('/tiket/{ticket}/request-revision', [TicketHistoryController::class, 'requestRevision'])->name('tiket.request-revision');
    Route::post('/tiket/{ticket}/kembalikan-alat', [TicketHistoryController::class, 'kembalikanAlat'])->name('tiket.kembalikan-alat');
    Route::patch('/tiket/{ticket}/batal', [TicketHistoryController::class, 'cancel'])->name('tiket.batal');
    Route::get('/tiket/download/{attachment}', [TicketHistoryController::class, 'download'])->name('tiket.download');
    Route::get('/tiket/view/{attachment}', [TicketHistoryController::class, 'viewAttachment'])->name('tiket.view');

    // Profil User
    Route::put('/profil', [ProfileController::class, 'update'])->name('profil.update');
    Route::post('/profil/avatar', [ProfileController::class, 'uploadAvatar'])->name('profil.upload-avatar');

    // CSAT
    Route::post('/csat/{ticket}', [CsatController::class, 'store'])->name('csat.store');
    Route::get('/csat/riwayat', [CsatController::class, 'riwayat'])->name('csat.riwayat');

    // Monitor
    Route::get('/monitor', [MonitorController::class, 'userIndex'])->name('monitor');

    // User Chat
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/', [ChatController::class, 'index'])->name('index');
        Route::get('/tickets', [ChatController::class, 'userTickets'])->name('tickets');
        Route::get('/conversations/{conversation}', [ChatController::class, 'show'])->name('conversations.show');
        Route::post('/conversations/{conversation}/messages', [ChatController::class, 'storeMessage'])->name('messages.store');
        Route::put('/messages/{message}', [ChatController::class, 'updateMessage'])->name('messages.update');
        Route::delete('/messages/{message}', [ChatController::class, 'destroyMessage'])->name('messages.destroy');
        Route::post('/conversations/{conversation}/read', [ChatController::class, 'markAsRead'])->name('read');
        Route::post('/conversations/{conversation}/typing', [ChatController::class, 'typing'])->name('typing');
        Route::get('/download/{attachment}', [ChatController::class, 'downloadAttachment'])->name('download');
    });

    // Notifications User
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::patch('{id}/read', [NotificationController::class, 'markAsRead'])->name('read');
        Route::patch('{id}/snooze', [NotificationController::class, 'snooze'])->name('snooze');
        Route::patch('{id}/done', [NotificationController::class, 'markAsDone'])->name('done');
        Route::post('mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('mark-all-read');
    });
});

// API dropdown (dependent dropdown & dynamic form)
Route::prefix('api')->group(function () {
    Route::get('/org-units/{divisiId}', [DropdownController::class, 'orgUnits'])->name('api.org-units');
    Route::get('/sub-units/{unitId}', [DropdownController::class, 'subUnits'])->name('api.sub-units');
    Route::get('/form-fields/{subUnitId}', [DropdownController::class, 'formFields'])->name('api.form-fields');
});

// Web Push Subscriptions
Route::post('/push/subscribe', [WebPushController::class, 'subscribe'])->name('push.subscribe');
Route::post('/push/unsubscribe', [WebPushController::class, 'unsubscribe'])->name('push.unsubscribe');

Route::prefix('admin')->name('admin.')->group(function () {
    // Akses /admin otomatis diarahkan ke login (jika guest) atau dashboard (jika sudah login)
    Route::get('/', function () {
        return auth('admin')->check()
            ? redirect()->route('admin.dashboard')
            : redirect()->route('login');
    });

    Route::middleware('guest:admin')->group(function () {
        Route::get('login', function () {
            return redirect()->route('login');
        })->name('login');
        Route::post('login', [AdminLoginController::class, 'login'])->middleware('throttle:5,1');
    });

    Route::middleware('auth:admin')->group(function () {
        Route::post('logout', [AdminLoginController::class, 'logout'])->name('logout');
        Route::get('/dashboard', [App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard');

        // Profil Admin
        Route::put('/profil', [App\Http\Controllers\Admin\ProfileController::class, 'update'])->name('profil.update');
        Route::post('/profil/avatar', [App\Http\Controllers\Admin\ProfileController::class, 'uploadAvatar'])->name('profil.upload-avatar');

        // CSAT & Laporan Admin
        Route::middleware('permission:akses-laporan')->group(function () {
            Route::get('/csat', [AdminCsatController::class, 'index'])->name('csat.index');
            Route::get('/laporan/tiket/export-pdf', [LaporanTiketController::class, 'exportPdf'])->name('laporan.tiket.export-pdf');
            Route::get('/laporan/tiket/export', [LaporanTiketController::class, 'export'])->name('laporan.tiket.export');
            Route::get('/laporan/tiket', [LaporanTiketController::class, 'index'])->name('laporan.tiket');
            Route::get('/laporan/kinerja-operator', [LaporanOperatorController::class, 'index'])->name('laporan.operator');
            Route::get('/laporan/kinerja-operator/{admin}/ulasan', [LaporanOperatorController::class, 'ulasan'])->name('laporan.operator.ulasan');
            Route::get('/laporan/kinerja-operator/export', [LaporanOperatorController::class, 'export'])->name('laporan.operator.export');
            Route::get('/laporan/kinerja-operator/export-pdf', [LaporanOperatorController::class, 'exportPdf'])->name('laporan.operator.export-pdf');
        });

        // Monitor Admin
        Route::get('/monitor', [MonitorController::class, 'adminIndex'])->name('monitor.index');

        // Konfigurasi Sistem
        Route::middleware('permission:akses-konfigurasi')->group(function () {
            Route::get('/konfigurasi', [SystemConfigController::class, 'index'])->name('konfigurasi.index');
            Route::put('/konfigurasi', [SystemConfigController::class, 'update'])->name('konfigurasi.update');
            Route::post('/konfigurasi/test-wa', [SystemConfigController::class, 'testWa'])->name('konfigurasi.test-wa');
            Route::post('/konfigurasi/upload-logo', [SystemConfigController::class, 'uploadLogo'])->name('konfigurasi.upload-logo');
            Route::post('/konfigurasi/upload-banner', [SystemConfigController::class, 'uploadBanner'])->name('konfigurasi.upload-banner');
            Route::post('/konfigurasi/upload-favicon', [SystemConfigController::class, 'uploadFavicon'])->name('konfigurasi.upload-favicon');
            Route::post('/konfigurasi/upload-sound', [SystemConfigController::class, 'uploadSound'])->name('konfigurasi.upload-sound');
            Route::post('/konfigurasi/test-sparc', [SystemConfigController::class, 'testSparcConnection'])->name('konfigurasi.test-sparc');
            Route::post('/konfigurasi/generate-sparc-credentials', [SystemConfigController::class, 'generateSparcCredentials'])->name('konfigurasi.generate-sparc-credentials');

            // System Optimization (protected — admin only)
            Route::get('/system/optimize', function () {
                Artisan::call('config:cache');
                Artisan::call('route:cache');
                Artisan::call('view:cache');

                return back()->with('success', 'System optimized successfully.');
            })->name('system.optimize');

            Route::get('/system/clear', function () {
                Artisan::call('config:clear');
                Artisan::call('route:clear');
                Artisan::call('view:clear');
                Artisan::call('cache:clear');

                return back()->with('success', 'System cache cleared successfully.');
            })->name('system.clear');
        });

        // Manual Scheduler (Protected by akses-konfigurasi permission)
        Route::middleware('permission:akses-konfigurasi')->prefix('scheduler')->name('scheduler.')->group(function () {
            Route::post('/sla-check', [SchedulerController::class, 'runSlaCheck'])->name('sla-check');
            Route::post('/booking-reminder', [SchedulerController::class, 'runBookingReminder'])->name('booking-reminder');
            Route::post('/pending-reminder', [SchedulerController::class, 'runPendingReminder'])->name('pending-reminder');
            Route::post('/csat-reminder', [SchedulerController::class, 'runCsatReminder'])->name('csat-reminder');
            Route::post('/run-all', [SchedulerController::class, 'runAll'])->name('run-all');
        });

        // Manajemen Akun
        Route::middleware('permission:akses-manajemen-akun')->group(function () {
            // Manajemen Operator
            Route::resource('manajemen-operator', AdminManagementController::class)->except(['create', 'edit', 'show'])->names('manajemen-operator');
            Route::post('manajemen-operator/{manajemen_operator}/reset-device', [AdminManagementController::class, 'resetDevice'])->name('manajemen-operator.reset-device');
            Route::post('manajemen-operator/{manajemen_operator}/send-reset-link', [AdminManagementController::class, 'sendResetLink'])->name('manajemen-operator.send-reset-link');

            // Manajemen User
            Route::resource('manajemen-user', UserManagementController::class)->except(['create', 'edit', 'show'])->names('manajemen-user');
            Route::post('manajemen-user/{user}/reset-device', [UserManagementController::class, 'resetDevice'])->name('manajemen-user.reset-device');
            Route::post('manajemen-user/{user}/send-reset-link', [UserManagementController::class, 'sendResetLink'])->name('manajemen-user.send-reset-link');

            // Manajemen Peran
            Route::resource('manajemen-peran', RoleManagementController::class)->except(['create', 'edit', 'show'])->names('manajemen-peran');
        });

        // Master Data Layanan
        Route::middleware('permission:akses-layanan')->group(function () {
            Route::resource('master/unit', UnitController::class)->except(['create', 'edit', 'show'])->names('master.unit');
            Route::resource('master/sub-unit', SubUnitController::class)->except(['create', 'edit', 'show'])->names('master.sub-unit');
        });

        // Master Data Struktur
        Route::middleware('permission:akses-struktur')->group(function () {
            Route::resource('master/divisi', DivisiController::class)->except(['create', 'edit', 'show'])->names('master.divisi');
            Route::resource('master/unit-organisasi', UnitOrganisasiController::class)->except(['create', 'edit', 'show'])->names('master.unit-organisasi');
            Route::post('master/jabatan/reorder', [JabatanController::class, 'reorder'])->name('master.jabatan.reorder');
            Route::resource('master/jabatan', JabatanController::class)->except(['create', 'edit', 'show'])->names('master.jabatan');
        });

        // Peraturan Form
        Route::middleware('permission:akses-konfigurasi')->group(function () {
            Route::prefix('peraturan-form')->group(function () {
                Route::get('/', [FormFieldController::class, 'index'])->name('peraturan-form.index');
                Route::get('/{subUnit}/builder', [FormFieldController::class, 'builder'])->name('peraturan-form.builder');
                Route::post('/{subUnit}/fields', [FormFieldController::class, 'store'])->name('peraturan-form.store');
                Route::put('/fields/{formField}', [FormFieldController::class, 'update'])->name('peraturan-form.update');
                Route::delete('/fields/{formField}', [FormFieldController::class, 'destroy'])->name('peraturan-form.destroy');
                Route::post('/{subUnit}/reorder', [FormFieldController::class, 'reorder'])->name('peraturan-form.reorder');
            });

            // SLA Config
            Route::resource('sla-config', SlaConfigController::class)->except(['create', 'edit', 'show']);

            // Reminder Config
            Route::get('reminder-config', [ReminderConfigController::class, 'index'])->name('reminder-config.index');
            Route::put('reminder-config', [ReminderConfigController::class, 'update'])->name('reminder-config.update');

            // Peraturan Lock Device
            Route::prefix('device-lock-config')->name('device-lock-config.')->group(function () {
                Route::get('/', [DeviceLockConfigController::class, 'index'])->name('index');
                Route::put('/', [DeviceLockConfigController::class, 'update'])->name('update');
                Route::post('/reset-account/{type}/{id}', [DeviceLockConfigController::class, 'resetAccountDevices'])->name('reset-account');
                Route::post('/revoke-device/{deviceId}', [DeviceLockConfigController::class, 'revokeDevice'])->name('revoke-device');
                Route::post('/reset-all', [DeviceLockConfigController::class, 'resetAllDevices'])->name('reset-all');
            });
        });

        // Notifications
        Route::prefix('notifications')->name('notifications.')->group(function () {
            Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
            Route::get('/', [NotificationController::class, 'index'])->name('index');
            Route::patch('{id}/read', [NotificationController::class, 'markAsRead'])->name('read');
            Route::patch('{id}/snooze', [NotificationController::class, 'snooze'])->name('snooze');
            Route::patch('{id}/done', [NotificationController::class, 'markAsDone'])->name('done');
            Route::post('mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('mark-all-read');
        });

        // Tiketing Admin
        Route::prefix('tiket')->name('tiket.')->group(function () {
            Route::get('/', [TicketController::class, 'index'])->name('index');
            Route::get('/buat', [TicketController::class, 'create'])->name('create');
            Route::post('/', [TicketController::class, 'store'])->name('store');
            Route::get('/download/{attachment}', [TicketController::class, 'downloadAttachment'])->name('download');
            Route::get('/view/{attachment}', [TicketController::class, 'viewAttachment'])->name('view');
            Route::get('/{ticket}', [TicketController::class, 'show'])->name('show');
            Route::post('/{ticket}/reply', [TicketController::class, 'reply'])->name('reply');
            Route::patch('/{ticket}/assign', [TicketController::class, 'assignOperator'])->name('assign');
            Route::patch('/{ticket}/status', [TicketController::class, 'updateStatus'])->name('status');
            Route::patch('/{ticket}/priority', [TicketController::class, 'updatePriority'])->name('priority');
            Route::patch('/{ticket}/cancel-booking', [TicketController::class, 'cancelBooking'])->name('cancel-booking');
            Route::patch('/{ticket}/batal', [TicketController::class, 'cancel'])->name('cancel');
            Route::patch('/{ticket}/kembalikan-alat', [TicketController::class, 'kembalikanAlat'])->name('kembalikan-alat');
        });

        // Chat Admin
        Route::prefix('chat')->name('chat.')->group(function () {
            Route::get('/', [App\Http\Controllers\Admin\ChatController::class, 'index'])->name('index');
            Route::get('/tickets', [App\Http\Controllers\Admin\ChatController::class, 'ticketsList'])->name('tickets');
            Route::get('/conversations/{conversation}', [App\Http\Controllers\Admin\ChatController::class, 'show'])->name('conversations.show');
            Route::post('/conversations/{conversation}/messages', [App\Http\Controllers\Admin\ChatController::class, 'storeMessage'])->name('messages.store');
            Route::put('/messages/{message}', [App\Http\Controllers\Admin\ChatController::class, 'updateMessage'])->name('messages.update');
            Route::delete('/messages/{message}', [App\Http\Controllers\Admin\ChatController::class, 'destroyMessage'])->name('messages.destroy');
            Route::post('/conversations/{conversation}/read', [App\Http\Controllers\Admin\ChatController::class, 'markAsRead'])->name('read');
            Route::post('/conversations/{conversation}/typing', [App\Http\Controllers\Admin\ChatController::class, 'typing'])->name('typing');
            Route::get('/download/{attachment}', [App\Http\Controllers\Admin\ChatController::class, 'downloadAttachment'])->name('download');
        });

        // HaloAPU AI (Admin)
        Route::get('/ai', [AiBotController::class, 'index'])->name('ai-bot.index');
        Route::post('/ai/chat', [AiBotController::class, 'chat'])->name('ai-bot.chat');

        // Laravel Pulse Redirect (Admin)
        Route::get('/pulse', function () {
            return redirect()->route('pulse');
        })->name('pulse');
    });
});

// Laravel Pulse Dashboard
Route::middleware(['web', 'auth:admin'])->get('/pulse', function () {
    if (! app()->environment('local', 'development')) {
        $admin = auth('admin')->user();
        if (! $admin || ! method_exists($admin, 'hasRole') || ! $admin->hasRole(['superadmin', 'Super Admin'])) {
            return redirect()->route('admin.dashboard')->with('error', 'Akses ditolak: Hanya Super Admin yang dapat mengakses Laravel Pulse.');
        }
    }

    return view('pulse::dashboard');
})->name('pulse');

// Redirect URL lama /ai-bot-haloapu ke /admin/ai (redirect only, no public chat endpoint)
Route::get('/ai-bot-haloapu', function () {
    return redirect()->route('admin.ai-bot.index');
});
// REMOVED: Route::post('/ai-bot-haloapu/chat', ...) — was publicly accessible without auth
Route::get('/admin/ai-bot-haloapu', function () {
    return redirect()->route('admin.ai-bot.index');
});
