<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\UserLoginController;
use App\Http\Controllers\Auth\AdminLoginController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('login', [UserLoginController::class, 'showLoginForm'])->name('login');
    Route::post('login', [UserLoginController::class, 'login']);
    
    Route::get('register', function () {
        return Inertia::render('Auth/Register');
    })->name('register');

    // Lupa Password
    Route::get('/lupa-password', [ForgotPasswordController::class, 'showForm'])->name('password.request');
    Route::post('/lupa-password', [ForgotPasswordController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [ForgotPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword'])->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [UserLoginController::class, 'logout'])->name('logout');
    Route::get('/dashboard', function () {
        return inertia('User/Dashboard');
    })->name('dashboard');

    // Tickets Wizard
    Route::get('/tiket/buat', [\App\Http\Controllers\User\TicketWizardController::class, 'create'])->name('tiket.create');
    Route::post('/tiket', [\App\Http\Controllers\User\TicketWizardController::class, 'store'])->name('tiket.store');

    // Ticket History
    Route::get('/tiket/riwayat', [\App\Http\Controllers\User\TicketHistoryController::class, 'index'])->name('tiket.riwayat');
    Route::get('/tiket/{ticket}', [\App\Http\Controllers\User\TicketHistoryController::class, 'show'])->name('tiket.show');
    Route::get('/tiket/download/{attachment}', [\App\Http\Controllers\User\TicketHistoryController::class, 'download'])->name('tiket.download');

    // API dropdown (dependent dropdown & dynamic form)
    Route::prefix('api')->group(function () {
        Route::get('/org-units/{divisiId}', function ($divisiId) {
            return \App\Models\OrgUnit::where('divisi_id', $divisiId)->orderBy('nama_unit_organisasi')->get();
        })->name('api.org-units');
    
        Route::get('/sub-units/{unitId}', function ($unitId) {
            return \App\Models\SubUnit::where('unit_id', $unitId)->where('aktif', true)->orderBy('nama_layanan')->get();
        })->name('api.sub-units');
    
        Route::get('/form-fields/{subUnitId}', function ($subUnitId) {
            return \App\Models\FormField::where('sub_unit_id', $subUnitId)->orderBy('urutan')->get();
        })->name('api.form-fields');
    });
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [AdminLoginController::class, 'showLoginForm'])->name('login');
        Route::post('login', [AdminLoginController::class, 'login']);
    });

    Route::middleware('auth:admin')->group(function () {
        Route::post('logout', [AdminLoginController::class, 'logout'])->name('logout');
        Route::get('/dashboard', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard');

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

        // Tiketing Admin
        Route::prefix('tiket')->name('tiket.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\TicketController::class, 'index'])->name('index');
            Route::get('/download/{attachment}', [\App\Http\Controllers\Admin\TicketController::class, 'downloadAttachment'])->name('download');
            Route::get('/{ticket}', [\App\Http\Controllers\Admin\TicketController::class, 'show'])->name('show');
            Route::patch('/{ticket}/status', [\App\Http\Controllers\Admin\TicketController::class, 'updateStatus'])->name('status');
        });
    });
});
