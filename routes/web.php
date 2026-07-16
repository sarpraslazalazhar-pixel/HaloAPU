<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\UserLoginController;
use App\Http\Controllers\Auth\AdminLoginController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\CsatController;
use App\Http\Controllers\Admin\CsatController as AdminCsatController;
use App\Http\Controllers\Admin\SystemConfigController;
use App\Http\Controllers\Admin\AdminManagementController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\User\DashboardController;
use App\Http\Controllers\Api\DropdownController;

Route::get('/', HomeController::class);

// TV Dashboard (Public)
Route::get('/tv', [\App\Http\Controllers\TvDashboardController::class, 'index'])->name('tv.index');

Route::middleware('guest')->group(function () {
    Route::get('login', [UserLoginController::class, 'showLoginForm'])->name('login');
    Route::post('login', [UserLoginController::class, 'login'])->middleware('throttle:5,1');
    
    Route::get('register', [RegisterController::class, 'showForm'])->name('register');

    // Lupa Password
    Route::get('/lupa-password', [ForgotPasswordController::class, 'showForm'])->name('password.request');
    Route::post('/lupa-password', [ForgotPasswordController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [ForgotPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword'])->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [UserLoginController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Tickets Wizard
    Route::get('/tiket/buat', [\App\Http\Controllers\User\TicketWizardController::class, 'create'])->name('tiket.create');
    Route::post('/tiket', [\App\Http\Controllers\User\TicketWizardController::class, 'store'])->name('tiket.store');

    // Ticket History
    Route::get('/tiket/riwayat', [\App\Http\Controllers\User\TicketHistoryController::class, 'index'])->name('tiket.riwayat');
    Route::get('/tiket/{ticket}', [\App\Http\Controllers\User\TicketHistoryController::class, 'show'])->name('tiket.show');
    Route::patch('/tiket/{ticket}/batal', [\App\Http\Controllers\User\TicketHistoryController::class, 'cancel'])->name('tiket.batal');
    Route::get('/tiket/download/{attachment}', [\App\Http\Controllers\User\TicketHistoryController::class, 'download'])->name('tiket.download');

    // Profil User
    Route::put('/profil', [\App\Http\Controllers\User\ProfileController::class, 'update'])->name('profil.update');
    Route::post('/profil/avatar', [\App\Http\Controllers\User\ProfileController::class, 'uploadAvatar'])->name('profil.upload-avatar');

    // CSAT
    Route::post('/csat/{ticket}', [CsatController::class, 'store'])->name('csat.store');
    Route::get('/csat/riwayat', [CsatController::class, 'riwayat'])->name('csat.riwayat');

    // Monitor
    Route::get('/monitor', [MonitorController::class, 'userIndex'])->name('monitor');


    // API dropdown (dependent dropdown & dynamic form)
    Route::prefix('api')->group(function () {
        Route::get('/org-units/{divisiId}', [DropdownController::class, 'orgUnits'])->name('api.org-units');
        Route::get('/sub-units/{unitId}', [DropdownController::class, 'subUnits'])->name('api.sub-units');
        Route::get('/form-fields/{subUnitId}', [DropdownController::class, 'formFields'])->name('api.form-fields');
    });
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [AdminLoginController::class, 'showLoginForm'])->name('login');
        Route::post('login', [AdminLoginController::class, 'login'])->middleware('throttle:5,1');
    });

    Route::middleware('auth:admin')->group(function () {
        Route::post('logout', [AdminLoginController::class, 'logout'])->name('logout');
        Route::get('/dashboard', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard');

        // Profil Admin
        Route::put('/profil', [\App\Http\Controllers\Admin\ProfileController::class, 'update'])->name('profil.update');
        Route::post('/profil/avatar', [\App\Http\Controllers\Admin\ProfileController::class, 'uploadAvatar'])->name('profil.upload-avatar');

        // CSAT Admin
        Route::get('/csat', [AdminCsatController::class, 'index'])->name('csat.index');

        // Monitor Admin
        Route::get('/monitor', [MonitorController::class, 'adminIndex'])->name('monitor.index');


        // Konfigurasi Sistem
        Route::get('/konfigurasi', [SystemConfigController::class, 'index'])->name('konfigurasi.index');
        Route::put('/konfigurasi', [SystemConfigController::class, 'update'])->name('konfigurasi.update');
        Route::post('/konfigurasi/upload-logo', [SystemConfigController::class, 'uploadLogo'])->name('konfigurasi.upload-logo');
        Route::post('/konfigurasi/upload-banner', [SystemConfigController::class, 'uploadBanner'])->name('konfigurasi.upload-banner');
        Route::post('/konfigurasi/upload-favicon', [SystemConfigController::class, 'uploadFavicon'])->name('konfigurasi.upload-favicon');
        Route::post('/konfigurasi/upload-sound', [SystemConfigController::class, 'uploadSound'])->name('konfigurasi.upload-sound');

        // Manual Scheduler
        Route::prefix('scheduler')->name('scheduler.')->group(function () {
            Route::post('/sla-check', [\App\Http\Controllers\Admin\SchedulerController::class, 'runSlaCheck'])->name('sla-check');
            Route::post('/booking-reminder', [\App\Http\Controllers\Admin\SchedulerController::class, 'runBookingReminder'])->name('booking-reminder');
            Route::post('/pending-reminder', [\App\Http\Controllers\Admin\SchedulerController::class, 'runPendingReminder'])->name('pending-reminder');
            Route::post('/csat-reminder', [\App\Http\Controllers\Admin\SchedulerController::class, 'runCsatReminder'])->name('csat-reminder');
            Route::post('/run-all', [\App\Http\Controllers\Admin\SchedulerController::class, 'runAll'])->name('run-all');
        });

        // Manajemen Admin
        Route::resource('manajemen-admin', AdminManagementController::class)->except(['create', 'edit', 'show'])->names('manajemen-admin');

        // Manajemen User
        Route::resource('manajemen-user', UserManagementController::class)->except(['create', 'edit', 'show'])->names('manajemen-user');

        // Master Data
        Route::resource('master/unit', \App\Http\Controllers\Admin\UnitController::class)->except(['create', 'edit', 'show'])->names('master.unit');
        Route::resource('master/sub-unit', \App\Http\Controllers\Admin\SubUnitController::class)->except(['create', 'edit', 'show'])->names('master.sub-unit');
        Route::resource('master/divisi', \App\Http\Controllers\Admin\DivisiController::class)->except(['create', 'edit', 'show'])->names('master.divisi');
        Route::resource('master/unit-organisasi', \App\Http\Controllers\Admin\UnitOrganisasiController::class)->except(['create', 'edit', 'show'])->names('master.unit-organisasi');
        Route::post('master/jabatan/reorder', [\App\Http\Controllers\Admin\JabatanController::class, 'reorder'])->name('master.jabatan.reorder');
        Route::resource('master/jabatan', \App\Http\Controllers\Admin\JabatanController::class)->except(['create', 'edit', 'show'])->names('master.jabatan');

        // Peraturan Form
        Route::prefix('peraturan-form')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\FormFieldController::class, 'index'])->name('peraturan-form.index');
            Route::get('/{subUnit}/builder', [\App\Http\Controllers\Admin\FormFieldController::class, 'builder'])->name('peraturan-form.builder');
            Route::post('/{subUnit}/fields', [\App\Http\Controllers\Admin\FormFieldController::class, 'store'])->name('peraturan-form.store');
            Route::put('/fields/{formField}', [\App\Http\Controllers\Admin\FormFieldController::class, 'update'])->name('peraturan-form.update');
            Route::delete('/fields/{formField}', [\App\Http\Controllers\Admin\FormFieldController::class, 'destroy'])->name('peraturan-form.destroy');
            Route::post('/{subUnit}/reorder', [\App\Http\Controllers\Admin\FormFieldController::class, 'reorder'])->name('peraturan-form.reorder');
        });

        // SLA Config
        Route::get('sla-config', [\App\Http\Controllers\Admin\SlaConfigController::class, 'index'])->name('sla-config.index');
        Route::put('sla-config', [\App\Http\Controllers\Admin\SlaConfigController::class, 'update'])->name('sla-config.update');

        // Reminder Config
        Route::get('reminder-config', [\App\Http\Controllers\Admin\ReminderConfigController::class, 'index'])->name('reminder-config.index');
        Route::put('reminder-config', [\App\Http\Controllers\Admin\ReminderConfigController::class, 'update'])->name('reminder-config.update');

        // Notifications
        Route::prefix('notifications')->name('notifications.')->group(function () {
            Route::get('unread-count', [\App\Http\Controllers\Admin\NotificationController::class, 'unreadCount'])->name('unread-count');
            Route::get('/', [\App\Http\Controllers\Admin\NotificationController::class, 'index'])->name('index');
            Route::patch('{id}/read', [\App\Http\Controllers\Admin\NotificationController::class, 'markAsRead'])->name('read');
            Route::patch('{id}/snooze', [\App\Http\Controllers\Admin\NotificationController::class, 'snooze'])->name('snooze');
            Route::patch('{id}/done', [\App\Http\Controllers\Admin\NotificationController::class, 'markAsDone'])->name('done');
            Route::post('mark-all-read', [\App\Http\Controllers\Admin\NotificationController::class, 'markAllAsRead'])->name('mark-all-read');
        });

        // Tiketing Admin
        Route::prefix('tiket')->name('tiket.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\TicketController::class, 'index'])->name('index');
            Route::get('/download/{attachment}', [\App\Http\Controllers\Admin\TicketController::class, 'downloadAttachment'])->name('download');
            Route::get('/{ticket}', [\App\Http\Controllers\Admin\TicketController::class, 'show'])->name('show');
            Route::patch('/{ticket}/status', [\App\Http\Controllers\Admin\TicketController::class, 'updateStatus'])->name('status');
        });
    });
});
