<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkStoreTicketRequest;
use App\Models\Unit;
use App\Services\AdminTicketCreator;
use App\Services\SparcIntegrationService;
use App\Services\TicketFormEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BulkTicketController extends Controller
{
    public function create(): Response
    {
        $units = Unit::aktif()
            ->select('id', 'nama_unit')
            ->orderBy('nama_unit')
            ->with([
                'subUnits' => fn ($query) => $query
                    ->aktif()
                    ->select('id', 'unit_id', 'nama_layanan')
                    ->orderBy('nama_layanan'),
                'subUnits.formFields' => fn ($query) => $query->orderBy('urutan'),
            ])
            ->get();

        return Inertia::render('Admin/Tiketing/BulkCreate', [
            'unitList' => $units,
            'maxTickets' => BulkStoreTicketRequest::MAX_TICKETS,
            'priorities' => TicketFormEditor::PRIORITIES,
        ]);
    }

    public function store(BulkStoreTicketRequest $request, AdminTicketCreator $creator): RedirectResponse
    {
        $admin = $request->user('admin');

        try {
            $tickets = $creator->create($admin, $request->validatedRows(), bulk: true);
        } catch (ValidationException $e) {
            return redirect()
                ->route('admin.tickets.bulk.create')
                ->withErrors($e->errors())
                ->withInput();
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.tickets.bulk.create')
                ->withInput()
                ->with('error', 'Gagal menyimpan tiket. Silakan coba lagi.');
        }

        $ticketIds = array_map(fn ($ticket) => $ticket->id, $tickets);
        $count = count($ticketIds);

        $queueConnection = config('queue.default') === 'sync' ? 'deferred' : config('queue.default');

        try {
            dispatch(function () use ($creator, $ticketIds, $admin): void {
                foreach ($ticketIds as $ticketId) {
                    $ticket = \App\Models\Ticket::find($ticketId);

                    if ($ticket) {
                        $creator->notifyAdmins($ticket, (int) $admin->id);
                    }
                }
            })->onConnection($queueConnection);
        } catch (Throwable $e) {
            report($e);
        }

        foreach ($tickets as $ticket) {
            try {
                app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.created');
            } catch (Throwable $e) {
                report($e);
            }
        }

        return redirect()
            ->route('admin.tiket.index', ['tab' => 'saya_ajukan'])
            ->with('success', "{$count} tiket berhasil diajukan.");
    }
}
