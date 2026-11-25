<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiBriefingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiBriefingController extends Controller
{
    /**
     * Buat atau perbarui briefing operasional AI secara on-demand untuk admin aktif.
     */
    public function generate(Request $request, AiBriefingService $service): JsonResponse
    {
        $validated = $request->validate([
            'period_type' => 'nullable|in:daily,weekly',
        ]);

        $admin = $request->user('admin');
        $period = $validated['period_type'] ?? 'daily';

        $result = $service->generateForAdmin($admin, $period, force: true);

        return response()->json([
            'briefing' => $result['briefing'],
        ], 201);
    }
}
