<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkStoreTicketRequest;
use App\Models\Ticket;
use App\Models\Unit;
use App\Services\AdminTicketCreator;
use App\Services\SparcIntegrationService;
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

        return Inertia::render('User/Tiket/BulkCreate', [
            'unitList' => $units,
            'maxTickets' => BulkStoreTicketRequest::MAX_TICKETS,
        ]);
    }

    public function store(BulkStoreTicketRequest $request, AdminTicketCreator $creator): RedirectResponse
    {
        $user = $request->user('web') ?? auth()->user();

        try {
            $tickets = $creator->createForUser($user, $request->validatedRows(), bulk: true);
        } catch (ValidationException $e) {
            return redirect()
                ->route('tiket.bulk.create')
                ->withErrors($e->errors())
                ->withInput();
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('tiket.bulk.create')
                ->withInput()
                ->with('error', 'Gagal menyimpan tiket. Silakan coba lagi.');
        }

        $ticketIds = array_map(fn ($ticket) => $ticket->id, $tickets);
        $count = count($ticketIds);

        $queueConnection = config('queue.default') === 'sync' ? 'deferred' : config('queue.default');

        try {
            dispatch(function () use ($creator, $ticketIds): void {
                foreach ($ticketIds as $ticketId) {
                    $ticket = Ticket::find($ticketId);

                    if ($ticket) {
                        $creator->notifyForUserTicket($ticket);
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
            ->route('tiket.riwayat')
            ->with('success', "{$count} tiket berhasil diajukan.");
    }
}
