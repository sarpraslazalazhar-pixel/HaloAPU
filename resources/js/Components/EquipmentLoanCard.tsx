import React, { useState } from 'react';
import { AlertTriangle, CalendarDays, CheckCircle2, Clock, PackageCheck, Paperclip, Send, UserCheck } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { TicketAttachmentList, type Attachment } from '@/Components/TicketAttachmentList';
import { formatDateId } from '@/lib/utils';
import type { EquipmentLoanSummary, TicketAdminActor } from '@/types';
import {
  HandoverEquipmentModal,
  ReportReturnEquipmentModal,
  ReturnEquipmentModal,
  resolveConditionMeta,
  type EquipmentLoanModalTarget,
} from '@/Components/EquipmentLoanModals';

export interface EquipmentLoanCardTicket {
  id: number;
  formatted_id?: string;
  status: string;
  diserahkan_at?: string | null;
  penerima_alat_user_id?: number | null;
  penerima_alat?: string | null;
  kondisi_serah?: string | null;
  catatan_serah?: string | null;
  pengembalian_diajukan_at?: string | null;
  catatan_pengajuan_kembali?: string | null;
  dikembalikan_at?: string | null;
  kondisi_kembali?: string | null;
  catatan_kembali?: string | null;
  diserahkan_oleh_admin?: TicketAdminActor | null;
  diterima_kembali_oleh_admin?: TicketAdminActor | null;
  user?: { name?: string | null; username?: string | null } | null;
  created_by_admin?: { name?: string | null; username?: string | null } | null;
}

interface EquipmentLoanCardProps {
  ticket: EquipmentLoanCardTicket;
  loanSummary?: EquipmentLoanSummary | null;
  loanAttachments?: {
    serah: Attachment[];
    pengajuan_kembali?: Attachment[];
    kembali: Attachment[];
  } | null;
  downloadRoute?: string;
  canManage?: boolean;
  canReportReturn?: boolean;
  reportReturnRoute?: string;
}

function resolveActorName(actor: TicketAdminActor | null | undefined): string | null {
  if (!actor) return null;

  return actor.name || actor.username || null;
}

export function EquipmentLoanCard({
  ticket,
  loanSummary = null,
  loanAttachments = null,
  downloadRoute = 'tiket.download',
  canManage = false,
  canReportReturn = false,
  reportReturnRoute = 'tiket.lapor-kembali-alat',
}: EquipmentLoanCardProps) {
  const [handoverModalOpen, setHandoverModalOpen] = useState(false);
  const [returnModalOpen, setReturnModalOpen] = useState(false);
  const [reportReturnModalOpen, setReportReturnModalOpen] = useState(false);

  const normalizedStatus = String(ticket.status || '').toLowerCase();
  const isSolved = normalizedStatus === 'solve' || normalizedStatus === 'selesai';
  const hasHandedOver = Boolean(ticket.diserahkan_at);
  const isReturnReported = Boolean(ticket.pengembalian_diajukan_at);
  const isReturned = Boolean(ticket.dikembalikan_at);
  const skippedHandover = !hasHandedOver && isReturned;

  const borrowerName =
    ticket.user?.name ||
    ticket.user?.username ||
    ticket.created_by_admin?.name ||
    ticket.created_by_admin?.username ||
    '-';

  const modalTarget: EquipmentLoanModalTarget = {
    id: ticket.id,
    formatted_id: ticket.formatted_id,
    borrowerName,
    equipmentText: loanSummary?.alat_text && loanSummary.alat_text !== '-' ? loanSummary.alat_text : undefined,
    scheduleText: loanSummary?.rentang_waktu || undefined,
    hasHandedOver,
  };

  const handoverAdminName = resolveActorName(ticket.diserahkan_oleh_admin);
  const returnAdminName = resolveActorName(ticket.diterima_kembali_oleh_admin);
  const handoverConditionBadge = ticket.kondisi_serah ? resolveConditionMeta(ticket.kondisi_serah) : null;
  const conditionBadge = ticket.kondisi_kembali ? resolveConditionMeta(ticket.kondisi_kembali) : null;
  const serahAttachments = loanAttachments?.serah ?? [];
  const pengajuanKembaliAttachments = loanAttachments?.pengajuan_kembali ?? [];
  const kembaliAttachments = loanAttachments?.kembali ?? [];

  const statusPill = (() => {
    const statusLabel = loanSummary?.status_peminjaman;

    if (isReturned || statusLabel === 'Dikembalikan') {
      return {
        label: 'Dikembalikan',
        className: 'bg-emerald-100 text-emerald-800 border-emerald-200',
      };
    }

    if (!isSolved || statusLabel === 'Menunggu Persetujuan') {
      return {
        label: 'Menunggu Persetujuan',
        className: 'bg-purple-100 text-purple-800 border-purple-200',
      };
    }

    if (isReturnReported || statusLabel === 'Menunggu Konfirmasi Kembali') {
      return {
        label: 'Menunggu Konfirmasi Kembali',
        className: 'bg-indigo-100 text-indigo-800 border-indigo-200',
      };
    }

    if (statusLabel === 'Belum Dikembalikan') {
      return {
        label: 'Belum Dikembalikan',
        className: 'bg-rose-100 text-rose-800 border-rose-200',
      };
    }

    if (hasHandedOver || statusLabel === 'Sedang Dipinjam') {
      return {
        label: 'Sedang Dipinjam',
        className: 'bg-sky-100 text-sky-800 border-sky-200',
      };
    }

    return {
      label: 'Menunggu Diserahkan',
      className: 'bg-amber-100 text-amber-800 border-amber-200',
    };
  })();

  return (
    <>
      <Card className="border-slate-200 bg-white shadow-sm">
        <CardHeader className="pb-3 border-b border-slate-100">
          <CardTitle className="text-sm font-semibold flex flex-wrap items-center justify-between gap-2">
            <span className="flex items-center gap-2 text-slate-800">
              <PackageCheck className="w-4 h-4 text-teal-600" />
              Siklus Peminjaman Alat
            </span>
            <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border ${statusPill.className}`}>
              {statusPill.label}
            </span>
          </CardTitle>
          {loanSummary && (loanSummary.alat_text !== '-' || loanSummary.rentang_waktu) && (
            <div className="pt-1.5 space-y-0.5 text-xs text-slate-600">
              {loanSummary.alat_text !== '-' && (
                <p className="font-medium text-slate-800">{loanSummary.alat_text}</p>
              )}
              {loanSummary.rentang_waktu && (
                <p className="flex items-center gap-1 text-[11px] text-slate-500">
                  <CalendarDays className="w-3.5 h-3.5 text-slate-400" />
                  <span>Jadwal pakai: {loanSummary.rentang_waktu}</span>
                </p>
              )}
            </div>
          )}
        </CardHeader>

        <CardContent className="pt-4 space-y-4 text-xs">
          {/* Step 1: Pengajuan Disetujui */}
          <div className="flex items-start gap-3">
            <div
              className={`mt-0.5 w-6 h-6 rounded-full flex items-center justify-center shrink-0 ${
                isSolved ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400'
              }`}
            >
              {isSolved ? <CheckCircle2 className="w-3.5 h-3.5" /> : <Clock className="w-3.5 h-3.5" />}
            </div>
            <div className="min-w-0 flex-1">
              <p className="font-semibold text-slate-800">1. Pengajuan Disetujui</p>
              <p className="text-slate-500 mt-0.5">
                {isSolved
                  ? 'Tiket telah disetujui dan siap masuk tahap serah-terima fisik alat.'
                  : 'Menunggu persetujuan operator sebelum alat dapat diserahkan.'}
              </p>
            </div>
          </div>

          {/* Step 2: Alat Diserahkan (Dikasih) */}
          <div className="flex items-start gap-3">
            <div
              className={`mt-0.5 w-6 h-6 rounded-full flex items-center justify-center shrink-0 ${
                hasHandedOver
                  ? 'bg-sky-100 text-sky-700'
                  : skippedHandover
                  ? 'bg-slate-100 text-slate-500'
                  : isSolved
                  ? 'bg-amber-100 text-amber-700'
                  : 'bg-slate-100 text-slate-400'
              }`}
            >
              {hasHandedOver ? (
                <UserCheck className="w-3.5 h-3.5" />
              ) : (
                <PackageCheck className="w-3.5 h-3.5" />
              )}
            </div>
            <div className="min-w-0 flex-1 space-y-1">
              <div className="flex flex-wrap items-center gap-1.5">
                <p className="font-semibold text-slate-800">2. Alat Diserahkan (Dikasih)</p>
                {skippedHandover && (
                  <span className="px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 text-[10px] font-medium">
                    Langsung dikembalikan
                  </span>
                )}
                {isSolved && !hasHandedOver && !isReturned && loanSummary?.is_overdue_schedule && (
                  <span className="px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 text-[10px] font-semibold">
                    Jadwal lewat
                  </span>
                )}
                {isSolved && !hasHandedOver && !isReturned && !loanSummary?.is_overdue_schedule && loanSummary?.is_due_today && (
                  <span className="px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 text-[10px] font-semibold">
                    Jadwal aktif hari ini
                  </span>
                )}
              </div>

              {hasHandedOver ? (
                <div className="rounded-lg border border-sky-100 bg-sky-50/60 p-2.5 space-y-1.5 text-slate-700">
                  <p>
                    Diserahkan pada <span className="font-semibold">{formatDateId(ticket.diserahkan_at)}</span>
                    {handoverAdminName ? ` oleh ${handoverAdminName}` : ''}.
                  </p>
                  {handoverConditionBadge && (
                    <div className="flex flex-wrap items-center gap-1.5">
                      <span className="text-slate-500">Kondisi:</span>
                      <span className={`inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold ${handoverConditionBadge.className}`}>
                        {handoverConditionBadge.label}
                      </span>
                    </div>
                  )}
                  {ticket.penerima_alat && (
                    <p>
                      Penerima: <span className="font-semibold text-slate-900">{ticket.penerima_alat}</span>
                    </p>
                  )}
                  {ticket.catatan_serah && (
                    <p className="italic text-slate-600">&ldquo;{ticket.catatan_serah}&rdquo;</p>
                  )}
                  {serahAttachments.length > 0 && (
                    <div className="pt-1.5 border-t border-sky-200/60 space-y-1.5">
                      <p className="text-[11px] font-semibold text-slate-600 flex items-center gap-1">
                        <Paperclip className="w-3 h-3 text-sky-600" />
                        <span>Lampiran Serah-Terima ({serahAttachments.length})</span>
                      </p>
                      <TicketAttachmentList
                        attachments={serahAttachments}
                        downloadRoute={downloadRoute}
                        grouped={false}
                      />
                    </div>
                  )}
                </div>
              ) : skippedHandover ? (
                <p className="text-slate-500">
                  Pengembalian langsung dicatat operator tanpa catatan serah-terima terpisah.
                </p>
              ) : isSolved ? (
                <p className="text-slate-600">
                  Menunggu alat diambil / diserahkan oleh operator
                  {loanSummary?.rentang_waktu ? ` (jadwal: ${loanSummary.rentang_waktu})` : ''}.
                </p>
              ) : (
                <p className="text-slate-400">Aktif setelah pengajuan disetujui.</p>
              )}
            </div>
          </div>

          {/* Step 3: Alat Dikembalikan */}
          <div className="flex items-start gap-3">
            <div
              className={`mt-0.5 w-6 h-6 rounded-full flex items-center justify-center shrink-0 ${
                isReturned
                  ? 'bg-emerald-100 text-emerald-700'
                  : isReturnReported
                  ? 'bg-indigo-100 text-indigo-700'
                  : loanSummary?.status_peminjaman === 'Belum Dikembalikan'
                  ? 'bg-rose-100 text-rose-700'
                  : 'bg-slate-100 text-slate-400'
              }`}
            >
              {isReturned ? (
                <CheckCircle2 className="w-3.5 h-3.5" />
              ) : isReturnReported ? (
                <Clock className="w-3.5 h-3.5" />
              ) : loanSummary?.status_peminjaman === 'Belum Dikembalikan' ? (
                <AlertTriangle className="w-3.5 h-3.5" />
              ) : (
                <Clock className="w-3.5 h-3.5" />
              )}
            </div>
            <div className="min-w-0 flex-1 space-y-1.5">
              <p className="font-semibold text-slate-800">3. Alat Dikembalikan</p>

              {isReturnReported && !isReturned && (
                <div className="rounded-lg border border-indigo-200 bg-indigo-50/70 p-2.5 space-y-1.5 text-indigo-950">
                  <div className="flex flex-wrap items-center justify-between gap-1.5">
                    <span className="font-semibold text-indigo-900">
                      Pemohon melaporkan alat sudah dikembalikan
                    </span>
                    <span className="px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-800 text-[10px] font-semibold">
                      Menunggu Konfirmasi Operator
                    </span>
                  </div>
                  <p className="text-indigo-800">
                    Dilaporkan pada <span className="font-semibold">{formatDateId(ticket.pengembalian_diajukan_at)}</span>.
                  </p>
                  {ticket.catatan_pengajuan_kembali && (
                    <p className="italic text-indigo-900">&ldquo;{ticket.catatan_pengajuan_kembali}&rdquo;</p>
                  )}
                  {pengajuanKembaliAttachments.length > 0 && (
                    <div className="pt-1.5 border-t border-indigo-200/70 space-y-1.5">
                      <p className="text-[11px] font-semibold text-indigo-800 flex items-center gap-1">
                        <Paperclip className="w-3 h-3 text-indigo-600" />
                        <span>Lampiran Laporan Pemohon ({pengajuanKembaliAttachments.length})</span>
                      </p>
                      <TicketAttachmentList
                        attachments={pengajuanKembaliAttachments}
                        downloadRoute={downloadRoute}
                        grouped={false}
                      />
                    </div>
                  )}
                </div>
              )}

              {isReturned ? (
                <div className="rounded-lg border border-emerald-100 bg-emerald-50/60 p-2.5 space-y-1.5 text-slate-700">
                  <p>
                    Dikembalikan pada <span className="font-semibold">{formatDateId(ticket.dikembalikan_at)}</span>
                    {returnAdminName ? ` (diterima oleh ${returnAdminName})` : ''}.
                  </p>
                  {conditionBadge && (
                    <div className="flex flex-wrap items-center gap-1.5">
                      <span className="text-slate-500">Kondisi:</span>
                      <span className={`inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold ${conditionBadge.className}`}>
                        {conditionBadge.label}
                      </span>
                    </div>
                  )}
                  {ticket.catatan_kembali && (
                    <p className="italic text-slate-600">&ldquo;{ticket.catatan_kembali}&rdquo;</p>
                  )}
                  {pengajuanKembaliAttachments.length > 0 && (
                    <div className="pt-1.5 border-t border-emerald-200/60 space-y-1.5">
                      <p className="text-[11px] font-semibold text-slate-600 flex items-center gap-1">
                        <Paperclip className="w-3 h-3 text-indigo-600" />
                        <span>Lampiran Laporan Pemohon ({pengajuanKembaliAttachments.length})</span>
                      </p>
                      <TicketAttachmentList
                        attachments={pengajuanKembaliAttachments}
                        downloadRoute={downloadRoute}
                        grouped={false}
                      />
                    </div>
                  )}
                  {kembaliAttachments.length > 0 && (
                    <div className="pt-1.5 border-t border-emerald-200/60 space-y-1.5">
                      <p className="text-[11px] font-semibold text-slate-600 flex items-center gap-1">
                        <Paperclip className="w-3 h-3 text-emerald-600" />
                        <span>Lampiran Pengembalian ({kembaliAttachments.length})</span>
                      </p>
                      <TicketAttachmentList
                        attachments={kembaliAttachments}
                        downloadRoute={downloadRoute}
                        grouped={false}
                      />
                    </div>
                  )}
                </div>
              ) : !isReturnReported ? (
                <p className={loanSummary?.status_peminjaman === 'Belum Dikembalikan' ? 'text-rose-700 font-medium' : 'text-slate-500'}>
                  {loanSummary?.status_peminjaman === 'Belum Dikembalikan'
                    ? 'Batas jadwal peminjaman telah lewat dan alat belum ditandai kembali.'
                    : 'Menunggu konfirmasi pengembalian oleh operator/admin.'}
                </p>
              ) : null}
            </div>
          </div>

          {canReportReturn && isSolved && !isReturned && !isReturnReported && (
            <div className="pt-3 border-t border-slate-100">
              <Button
                type="button"
                size="sm"
                className="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-xs"
                onClick={() => setReportReturnModalOpen(true)}
              >
                <Send className="w-3.5 h-3.5 mr-1.5" />
                Lapor Sudah Kembalikan
              </Button>
            </div>
          )}

          {canManage && isSolved && !isReturned && (
            <div className="pt-3 border-t border-slate-100 flex flex-col gap-2">
              {!hasHandedOver && !isReturnReported ? (
                <>
                  <Button
                    type="button"
                    size="sm"
                    className="w-full bg-sky-600 hover:bg-sky-700 text-white text-xs"
                    onClick={() => setHandoverModalOpen(true)}
                  >
                    <PackageCheck className="w-3.5 h-3.5 mr-1.5" />
                    Tandai Sudah Dikasih
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="w-full border-emerald-300 text-emerald-700 hover:bg-emerald-50 text-xs"
                    onClick={() => setReturnModalOpen(true)}
                  >
                    <CheckCircle2 className="w-3.5 h-3.5 mr-1.5" />
                    Langsung Tandai Dikembalikan
                  </Button>
                </>
              ) : (
                <Button
                  type="button"
                  size="sm"
                  className="w-full bg-emerald-600 hover:bg-emerald-700 text-white text-xs"
                  onClick={() => setReturnModalOpen(true)}
                >
                  <CheckCircle2 className="w-3.5 h-3.5 mr-1.5" />
                  {isReturnReported ? 'Konfirmasi Pengembalian Alat' : 'Tandai Sudah Dikembalikan'}
                </Button>
              )}
            </div>
          )}
        </CardContent>
      </Card>

      {canReportReturn && (
        <ReportReturnEquipmentModal
          open={reportReturnModalOpen}
          onOpenChange={setReportReturnModalOpen}
          ticket={modalTarget}
          submitRouteName={reportReturnRoute}
        />
      )}

      {canManage && (
        <>
          <HandoverEquipmentModal
            open={handoverModalOpen}
            onOpenChange={setHandoverModalOpen}
            ticket={modalTarget}
          />
          <ReturnEquipmentModal
            open={returnModalOpen}
            onOpenChange={setReturnModalOpen}
            ticket={modalTarget}
          />
        </>
      )}
    </>
  );
}
