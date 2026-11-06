<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CsatApiController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\MonitorApiController;
use App\Http\Controllers\Api\NotificationApiController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SparcIntegrationController;
use App\Http\Controllers\Api\TicketController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/contact-admin', [AuthController::class, 'contactAdmin'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    // Proxy to serve attachments with CORS headers for Flutter Web
    Route::get('/attachments/serve', [TicketController::class, 'serveAttachment']);

    // Auth & Profile
    Route::get('/user', [AuthController::class, 'profile']);
    Route::put('/user', [AuthController::class, 'updateProfile']);
    Route::delete('/user', [AuthController::class, 'deleteAccount']);
    Route::post('/user/avatar', [AuthController::class, 'uploadAvatar']);
    Route::post('/user/change-password', [AuthController::class, 'changePassword']);
    Route::post('/fcm-token', [AuthController::class, 'storeFcmToken']);
    Route::post('/users/fcm-token', [AuthController::class, 'storeFcmToken']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Dashboard
    Route::get('/dashboard', [DashboardApiController::class, 'index']);

    // Monitor (Pantauan Langsung)
    Route::get('/monitor/assets', [MonitorApiController::class, 'assets']);
    Route::get('/monitor/calendar', [MonitorApiController::class, 'calendar']);

    // Services
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/services/{subUnitId}/fields', [ServiceController::class, 'fields']);

    // Tickets
    Route::get('/tickets', [TicketController::class, 'index']);
    Route::get('/tickets/{id}', [TicketController::class, 'show']);
    Route::post('/tickets', [TicketController::class, 'store']);
    Route::post('/tickets/{id}/reply', [TicketController::class, 'reply']);
    Route::post('/tickets/{id}/assign', [TicketController::class, 'assignOperator']);
    Route::patch('/tickets/{id}/cancel', [TicketController::class, 'cancel']);
    Route::patch('/tickets/{id}/cancel-booking', [TicketController::class, 'cancelBooking']);
    Route::post('/tickets/{id}/accept', [TicketController::class, 'acceptResult']);
    Route::post('/tickets/{id}/revision', [TicketController::class, 'requestRevision']);
    Route::post('/tickets/{id}/status', [TicketController::class, 'changeStatus']);
    Route::post('/tickets/{id}/kembalikan-alat', [TicketController::class, 'kembalikanAlat']);
    Route::patch('/tickets/{id}/priority', [TicketController::class, 'changePriority']);

    // CSAT / Ratings
    Route::post('/tickets/{id}/rate', [CsatApiController::class, 'store']);
    Route::get('/ratings/pending', [CsatApiController::class, 'pending']);
    Route::get('/ratings', [CsatApiController::class, 'index']);

    // Notifications
    Route::get('/notifications', [NotificationApiController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationApiController::class, 'unreadCount']);
    Route::patch('/notifications/{id}/read', [NotificationApiController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationApiController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationApiController::class, 'destroy']);

    // Admin Device & User Management
    Route::get('/admin/users', [AuthController::class, 'getAdminUsersList']);
    Route::post('/admin/users/{id}/reset-device', [AuthController::class, 'resetUserDevice']);
    Route::post('/admin/admins/{id}/reset-device', [AuthController::class, 'resetAdminDevice']);
    Route::get('/admin/settings/device-lock', [AuthController::class, 'getDeviceLockSetting']);
    Route::post('/admin/settings/device-lock', [AuthController::class, 'toggleDeviceLockSetting']);
});

// =====================================================================
// SPARC v3 Server-to-Server Integration API
// =====================================================================
Route::prefix('integrations/sparc')
    ->middleware('sparc.token')
    ->group(function () {
        Route::get('ping', [SparcIntegrationController::class, 'ping']);
        Route::get('tickets', [SparcIntegrationController::class, 'index']);
        Route::get('tickets/{id}', [SparcIntegrationController::class, 'show']);
        Route::post('tickets/{id}/status', [SparcIntegrationController::class, 'updateStatus']);
        Route::post('tickets/{id}/reply', [SparcIntegrationController::class, 'reply']);
        Route::get('attachments/{attachment}', [SparcIntegrationController::class, 'streamAttachment']);
    });
