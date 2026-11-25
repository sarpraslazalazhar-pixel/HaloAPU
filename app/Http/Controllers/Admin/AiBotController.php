<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiBriefing;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\Ai\AiAccessScope;
use App\Services\Ai\AiMarkdownRenderer;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\HaloApuAiService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class AiBotController extends Controller
{
    /**
     * Tampilkan antarmuka fullscreen HaloAPU AI Workspace.
     */
    public function index(
        Request $request,
        HaloApuAiService $aiService,
        GeminiClient $geminiClient,
        AiAccessScope $accessScope
    ): Response {
        $admin = $request->user('admin');

        $sessions = AiConversation::where('admin_id', $admin->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'admin_id', 'title', 'scope_filters', 'last_message_at', 'created_at', 'updated_at']);

        $requestedSessionId = $request->integer('session');
        $activeConversation = null;

        if ($requestedSessionId > 0) {
            $candidate = AiConversation::find($requestedSessionId);
            if ($candidate) {
                abort_unless((int) $candidate->admin_id === (int) $admin->id, 403);
                $activeConversation = $candidate;
            }
        } elseif ($sessions->isNotEmpty()) {
            $activeConversation = $sessions->first();
        }

        if ($activeConversation) {
            $activeConversation->load([
                'messages' => fn ($q) => $q->orderBy('created_at')->orderBy('id'),
            ]);
        }

        $briefings = AiBriefing::where('admin_id', $admin->id)
            ->orderByDesc('briefing_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $unreadBriefingsCount = AiBriefing::where('admin_id', $admin->id)
            ->whereNull('read_at')
            ->count();

        return Inertia::render('Admin/AiBot/Index', [
            'hasApiKey' => $geminiClient->isConfigured(),
            'geminiModel' => $geminiClient->modelName(),
            'quickMetrics' => $aiService->getQuickMetrics($admin),
            'scopeInfo' => $accessScope->describeScope($admin),
            'sessions' => $sessions,
            'activeConversation' => $activeConversation,
            'briefings' => $briefings,
            'unreadBriefingsCount' => $unreadBriefingsCount,
        ]);
    }

    /**
     * Ambil detail sesi percakapan beserta seluruh pesannya.
     */
    public function showSession(Request $request, AiConversation $conversation): JsonResponse
    {
        $admin = $request->user('admin');
        abort_unless((int) $conversation->admin_id === (int) $admin->id, 403);

        $conversation->load([
            'messages' => fn ($q) => $q->orderBy('created_at')->orderBy('id'),
        ]);

        return response()->json([
            'conversation' => $conversation,
            'messages' => $conversation->messages,
        ]);
    }

    /**
     * Buat sesi percakapan AI baru untuk admin aktif.
     */
    public function createSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:160',
            'scope_filters' => 'nullable|array',
        ]);

        $admin = $request->user('admin');
        $title = trim((string) ($validated['title'] ?? ''));

        $conversation = AiConversation::create([
            'admin_id' => $admin->id,
            'title' => $title !== '' ? $title : 'Percakapan Baru',
            'scope_filters' => $validated['scope_filters'] ?? null,
            'last_message_at' => now(),
        ]);

        return response()->json([
            'conversation' => $conversation,
        ], 201);
    }

    /**
     * Ganti judul sesi percakapan AI.
     */
    public function renameSession(Request $request, AiConversation $conversation): JsonResponse
    {
        $admin = $request->user('admin');
        abort_unless((int) $conversation->admin_id === (int) $admin->id, 403);

        $validated = $request->validate([
            'title' => 'required|string|max:160',
        ]);

        $title = trim($validated['title']);
        $conversation->update([
            'title' => $title !== '' ? $title : $conversation->title,
        ]);

        return response()->json([
            'conversation' => $conversation->fresh(),
        ]);
    }

    /**
     * Hapus sesi percakapan AI beserta seluruh pesannya.
     */
    public function deleteSession(Request $request, AiConversation $conversation): JsonResponse
    {
        $admin = $request->user('admin');
        abort_unless((int) $conversation->admin_id === (int) $admin->id, 403);

        $conversation->delete();

        return response()->json([
            'deleted' => true,
        ]);
    }

    /**
     * Kirim pesan chat ke HaloAPU AI (terbatas cakupan akses admin & rate-limited).
     */
    public function chat(Request $request, HaloApuAiService $aiService): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:3000',
            'conversation_id' => 'nullable|integer|exists:ai_conversations,id',
            'period_filter' => 'nullable|string|in:today,7d,30d,this_month,last_month,this_year,all',
        ]);

        $admin = $request->user('admin');
        $conversation = null;

        if (! empty($validated['conversation_id'])) {
            $conversation = AiConversation::findOrFail((int) $validated['conversation_id']);
            abort_unless((int) $conversation->admin_id === (int) $admin->id, 403);
        }

        $result = $aiService->sendMessage(
            $admin,
            $validated['message'],
            $conversation,
            $validated['period_filter'] ?? null
        );

        return response()->json([
            'conversation' => $result['conversation'],
            'user_message' => $result['user_message'],
            'assistant_message' => $result['assistant_message'],
            'reply' => $result['reply'],
            'is_fallback' => $result['is_fallback'],
            'model_name' => $result['model_name'],
            'tools_used' => $result['tools_used'],
            'charts' => $result['charts'],
        ]);
    }

    /**
     * Ekspor sesi percakapan (atau satu pesan balasan) menjadi laporan PDF A4.
     */
    public function exportPdf(
        Request $request,
        AiConversation $conversation,
        AiMarkdownRenderer $renderer,
        AiAccessScope $scope
    ) {
        $admin = $request->user('admin');
        abort_unless((int) $conversation->admin_id === (int) $admin->id, 403);

        $messageId = $request->integer('message_id');
        if ($messageId > 0) {
            /** @var AiMessage $single */
            $single = $conversation->messages()->whereKey($messageId)->firstOrFail();
            $messages = collect([$single]);
        } else {
            $messages = $conversation->messages()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
        }

        $entries = $messages->map(function (AiMessage $msg) use ($renderer): array {
            return [
                'id' => $msg->id,
                'role' => $msg->role,
                'html' => $renderer->toHtml((string) $msg->content),
                'is_fallback' => (bool) $msg->is_fallback,
                'model_name' => $msg->model_name,
                'charts' => is_array($msg->charts) ? $msg->charts : [],
                'created_at' => $msg->created_at?->copy()->timezone('Asia/Jakarta')->format('d M Y H:i') ?? '-',
            ];
        })->all();

        $logo = null;
        try {
            $logoPath = public_path('storage/logo.png');
            if (File::exists($logoPath)) {
                $logo = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
            }
        } catch (\Throwable) {
            // Ignore missing/unreadable logo
        }

        $scopeInfo = $scope->describeScope($admin);

        $pdf = Pdf::loadView('exports.ai-report-pdf', [
            'title' => 'Laporan Analisis HaloAPU AI - ' . $conversation->title,
            'conversation' => $conversation,
            'entries' => $entries,
            'logo' => $logo,
            'adminName' => $admin->name ?? $admin->username,
            'scopeLabel' => $scopeInfo['label'],
            'printedAt' => Carbon::now('Asia/Jakarta')->format('d-m-Y H:i'),
        ])->setOption([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ]);

        return $pdf->download('laporan-haloapu-ai-' . $conversation->id . '-' . date('Ymd-His') . '.pdf');
    }

    /**
     * Ambil detail briefing AI milik admin dan tandai sudah dibaca.
     */
    public function showBriefing(Request $request, AiBriefing $briefing): JsonResponse
    {
        $admin = $request->user('admin');
        abort_unless((int) $briefing->admin_id === (int) $admin->id, 403);

        if ($briefing->read_at === null) {
            $briefing->update(['read_at' => now()]);
        }

        return response()->json([
            'briefing' => $briefing->fresh(),
        ]);
    }
}
