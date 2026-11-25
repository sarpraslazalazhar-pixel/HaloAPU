import React, { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable } from '@/Components/DataTable';
import { StatusBadge } from '@/Components/StatusBadge';
import SlaBadge from '@/Components/SlaBadge';
import { Pagination } from '@/Components/Pagination';
import { Button } from '@/Components/ui/button';
import { DateRangePicker } from '@/Components/ui/date-range-picker';
import { ArrowDown, ArrowUp, CheckCircle2, ChevronDown, Eye, Filter, Inbox, PackageCheck, Paperclip, Play, Plus, Search, Send, UserCheck, X, XCircle } from 'lucide-react';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import {
  HandoverEquipmentModal,
  ReturnEquipmentModal,
  resolveConditionMeta,
  type EquipmentLoanModalTarget,
} from '@/Components/EquipmentLoanModals';
import { formatDateId, formatTicketId } from '@/lib/utils';
import type { EquipmentLoanSummary, TicketAdminActor } from '@/types';

const STATUS_LIST = [
  { value: 'open', label: 'Baru' },
  { value: 'on_proses', label: 'Diproses' },
  { value: 'pending', label: 'Tertunda' },
  { value: 'need_revision', label: 'Butuh Revisi' },
  { value: 'solve', label: 'Selesai' },
];

interface Ticket {
 id: number;
 formatted_id?: string;
 user?: { id: number; username: string; name?: string; divisi?: { nama_divisi: string } } | null;
 created_by_admin?: { id: number; name: string; username: string } | null;
 unit?: { nama_unit: string };
 sub_unit?: { nama_layanan: string };
 assigned_admin_id?: number | null;
 assigned_admin?: { id: number; name: string; username: string };
 source?: 'web' | 'mobile' | string;
 status: string;
 created_at: string;
 assignment_queued_at?: string | null;
 booking?: { id?: number; status: string } | null;
 can_cancel?: boolean;
 csat?: { id?: number; rating: number; komentar?: string | null } | null;
 diserahkan_at?: string | null;
 penerima_alat_user_id?: number | null;
 penerima_alat?: string | null;
 kondisi_serah?: string | null;
 catatan_serah?: string | null;
 diserahkan_oleh_admin?: TicketAdminActor | null;
 dikembalikan_at?: string | null;
 kondisi_kembali?: string | null;
 catatan_kembali?: string | null;
 lampiran_serah_count?: number;
 lampiran_kembali_count?: number;
 diterima_kembali_oleh_admin?: TicketAdminActor | null;
 loan_summary?: EquipmentLoanSummary | null;
 can_serahkan?: boolean;
 can_kembalikan?: boolean;
}

interface OperatorLoadTicket {
 id: number;
 formatted_id: string;
 service: string;
 status: string;
 urgent: boolean;
 position: number | null;
 is_work_hours_paused?: boolean;
 is_manual_pending?: boolean;
 waiting_for_work_hours?: boolean;
}

interface OperatorLoad {
 admin_id: number;
 name: string;
 total?: number;
 working: number;
 paused: number;
 queued: number;
 work_hours_open: boolean;
 tickets: OperatorLoadTicket[];
}

type TicketFilterParams = {
 tab: string;
 loan_stage?: string;
 unit_id?: string;
 sub_unit_id?: string;
 status?: string[];
 date_from?: string;
 date_to?: string;
 divisi_id?: string;
 org_unit_id?: string;
 search?: string;
 assignment?: string;
};

const TABS = [
 { key: 'masuk', label: 'Tiket Masuk', Icon: Inbox },
 { key: 'saya_ajukan', label: 'Tiket Saya Ajukan', Icon: Send },
 { key: 'peminjaman_alat', label: 'Peminjaman Alat', Icon: PackageCheck },
];

const LOAN_STAGES = [
 { key: 'aktif', label: 'Perlu Tindakan' },
 { key: 'menunggu_diserahkan', label: 'Menunggu Dikasih' },
 { key: 'sedang_dipinjam', label: 'Sedang Dipinjam' },
 { key: 'dikembalikan', label: 'Sudah Dikembalikan' },
 { key: 'semua', label: 'Semua' },
];

interface OperatorOption {
 id: number;
 name?: string;
 username?: string;
}

export default function TicketIndex({ tickets, filters, units, divisiList, orgUnitList, operators = [], currentTab = 'masuk', canViewEquipmentLoans = false, loanStage = 'aktif', loanStageCounts = {}, tabCounts, operatorLoads = [], canBulkCreate = false, assignedToMeCount = 0 }: { tickets: any; filters: any; units: any; divisiList: any; orgUnitList: any; operators?: OperatorOption[]; currentTab?: string; canViewEquipmentLoans?: boolean; loanStage?: string | null; loanStageCounts?: Record<string, number>; tabCounts: any; operatorLoads?: OperatorLoad[]; canBulkCreate?: boolean; assignedToMeCount?: number }) {
 const { auth } = usePage<{ auth?: { admin?: { id?: number } } }>().props;
 const currentAdminId = auth?.admin?.id ?? null;

 const isAssignedToMeFilter = (value: string | number | null | undefined): boolean => {
 if (value === 'me') return true;

 return currentAdminId !== null && value !== '' && value !== null && value !== undefined && String(value) === String(currentAdminId);
 };

 const normalizeAssignment = (value: string | number | null | undefined): string => {
 if (value === 'me' && currentAdminId !== null) return String(currentAdminId);

 return value ? String(value) : '';
 };

 const [showFilter, setShowFilter] = useState(() => {
 return !!(filters?.unit_id || filters?.sub_unit_id || filters?.status || filters?.date_from || filters?.date_to || filters?.divisi_id || filters?.org_unit_id || (filters?.assignment && !isAssignedToMeFilter(filters.assignment)));
 });

 const [unitId, setUnitId] = useState(filters?.unit_id || '');
 const [subUnitId, setSubUnitId] = useState(filters?.sub_unit_id || '');

 const [selectedStatuses, setSelectedStatuses] = useState<string[]>(() => {
 const s = filters?.status;

 return Array.isArray(s) ? s : s ? [s] : [];
 });

 const [dateFrom, setDateFrom] = useState(filters?.date_from || '');
 const [dateTo, setDateTo] = useState(filters?.date_to || '');
 const [divisiId, setDivisiId] = useState(filters?.divisi_id || '');
 const [orgUnitId, setOrgUnitId] = useState(filters?.org_unit_id || '');
 const [assignment, setAssignment] = useState(() => normalizeAssignment(filters?.assignment));
 const [subUnits, setSubUnits] = useState<any[]>([]);
 const [search, setSearch] = useState(filters?.search || '');
 const [openOperator, setOpenOperator] = useState<number | null>(null);
 const [enqueuingTicketId, setEnqueuingTicketId] = useState<number | null>(null);
 const [swapTarget, setSwapTarget] = useState<{ ticket: OperatorLoadTicket; hasActive: boolean } | null>(null);
 const [swapping, setSwapping] = useState(false);
 const [cancelTargetTicket, setCancelTargetTicket] = useState<Ticket | null>(null);
 const [handoverModalTicket, setHandoverModalTicket] = useState<EquipmentLoanModalTarget | null>(null);
 const [returnModalTicket, setReturnModalTicket] = useState<EquipmentLoanModalTarget | null>(null);

 const activeLoanStage = loanStage || 'aktif';

 const buildLoanModalTarget = (t: Ticket): EquipmentLoanModalTarget => ({
  id: t.id,
  formatted_id: t.formatted_id || formatTicketId(t.id),
  borrowerName: t.user?.name || t.user?.username || t.created_by_admin?.name || t.created_by_admin?.username || '-',
  equipmentText: t.loan_summary?.alat_text && t.loan_summary.alat_text !== '-' ? t.loan_summary.alat_text : (t.sub_unit?.nama_layanan || 'Peminjaman Alat'),
  scheduleText: t.loan_summary?.rentang_waktu || undefined,
  hasHandedOver: Boolean(t.diserahkan_at),
 });

 const isMyAssignmentActive = isAssignedToMeFilter(assignment);

 const isAssignedToMe = (ticket: Ticket): boolean => {
 if (currentAdminId === null) return false;

 const assignedId = ticket.assigned_admin_id ?? ticket.assigned_admin?.id;

 return assignedId !== null && assignedId !== undefined && Number(assignedId) === Number(currentAdminId);
 };

 const enqueueTicket = (ticketId: number) => {
  if (enqueuingTicketId !== null) return;

  setEnqueuingTicketId(ticketId);
  router.post(
   route('admin.tiket.enqueue', ticketId),
   {},
   {
    preserveScroll: true,
    preserveState: true,
    onFinish: () => setEnqueuingTicketId(null),
   },
  );
 };

 useEffect(() => {
 if (unitId) {
 fetch(`/api/sub-units/${unitId}`).then(r => r.json()).then(setSubUnits);
 }
 }, []);

 useEffect(() => {
 setAssignment(normalizeAssignment(filters?.assignment));
 }, [filters?.assignment, currentAdminId]);

 const toggleStatus = (val: string) => {
 setSelectedStatuses(prev =>
 prev.includes(val) ? prev.filter(s => s !== val) : [...prev, val]
 );
 };

 const changeTab = (tab: string) => {
 router.get(route('admin.tiket.index'), { tab }, {});
 };

  const applyFilter = (overrides?: { search?: string; assignment?: string; loan_stage?: string }) => {
    const params: TicketFilterParams = { tab: currentTab };
    const keyword = overrides?.search !== undefined ? overrides.search : search;
    const nextAssignment = overrides?.assignment !== undefined ? overrides.assignment : assignment;
    const nextLoanStage = overrides?.loan_stage !== undefined ? overrides.loan_stage : activeLoanStage;

 if (currentTab === 'peminjaman_alat' && nextLoanStage) params.loan_stage = nextLoanStage;

 if (unitId) params.unit_id = unitId;

 if (subUnitId) params.sub_unit_id = subUnitId;

 if (currentTab !== 'peminjaman_alat' && selectedStatuses.length > 0) params.status = selectedStatuses;

 if (dateFrom) params.date_from = dateFrom;

 if (dateTo) params.date_to = dateTo;

 if (divisiId) params.divisi_id = divisiId;

 if (orgUnitId) params.org_unit_id = orgUnitId;

 if (keyword) params.search = keyword;

 if (nextAssignment) params.assignment = nextAssignment;

 router.get(route('admin.tiket.index'), params, { });
 };

 const toggleAssignedToMe = () => {
 const next = isMyAssignmentActive ? '' : (currentAdminId !== null ? String(currentAdminId) : 'me');

 setAssignment(next);
 applyFilter({ assignment: next });
 };

 const resetFilter = () => {
 setUnitId('');
 setSubUnitId('');
 setSubUnits([]);
 setSelectedStatuses([]);
 setDateFrom('');
 setDateTo('');
 setDivisiId('');
 setOrgUnitId('');
 setAssignment('');
 setSearch('');
 router.get(route('admin.tiket.index'), { tab: currentTab }, {});
 };

 const defaultColumns = [
 {
 key: 'sla_tracking',
 header: 'SLA',
 render: (t: any) => {
 const sla = t.sla_tracking;

 if (!sla) return <span className="text-slate-400">-</span>;

 return (
 <SlaBadge
 priority={t.priority}
 isBreached={sla.is_response_breached || sla.is_resolution_breached}
 deadline={sla.sla_resolution_deadline}
 resolutionStartsAt={sla.sla_resolution_starts_at}
 resolutionBaseDeadline={sla.sla_resolution_base_deadline}
 respondedAt={sla.responded_at}
 resolvedAt={sla.resolved_at}
 pausedAt={sla.paused_at}
 totalPausedMinutes={sla.total_paused_minutes}
 ticketStatus={t.status}
 />
 );
 },
 },
 { key: 'id', header: 'ID Tiket', render: (t: Ticket) =>`#TKT-${formatTicketId(t.id)}`},
 { key: 'created_at', header: 'Tgl Pengajuan', render: (t: Ticket) => new Date(t.created_at).toLocaleDateString('id-ID') },
 {
 key: 'user',
 header: 'Pengaju',
 render: (t: Ticket) => (
 <div>
 <p className="font-medium">{t.user?.username || (t.created_by_admin ? `${t.created_by_admin.name || t.created_by_admin.username} (Admin)` : '-')}</p>
 <p className="text-xs text-slate-500">{t.user?.divisi?.nama_divisi || (t.created_by_admin ? 'Internal Admin' : '-')}</p>
 </div>
 ),
 },
 {
 key: 'layanan',
 header: 'Layanan',
 render: (t: Ticket) => (
 <div>
 <p className="font-medium">{t.unit?.nama_unit || '-'}</p>
 <p className="text-xs text-slate-500">{t.sub_unit?.nama_layanan || '-'}</p>
 </div>
 ),
 },
 {
 key: 'operator',
 header: 'Operator',
 render: (t: Ticket) => {
 const assignedToMe = isAssignedToMe(t);
 const operatorName = t.assigned_admin?.name || t.assigned_admin?.username || '-';

 return (
 <div className="flex flex-col items-start gap-1">
 <span className={assignedToMe ? 'font-semibold text-indigo-950' : 'font-medium'}>
 {operatorName}
 </span>
 {assignedToMe && (
 <span className="inline-flex items-center gap-1 rounded-full border border-indigo-200 bg-indigo-100 px-2 py-0.5 text-[11px] font-semibold text-indigo-700">
 <UserCheck className="h-3 w-3" />
 Ditugaskan ke Saya
 </span>
 )}
 </div>
 );
 },
 },
 {
 key: 'source',
 header: 'Sumber',
 render: (t: Ticket) => {
 const isMobile = (t.source || 'web') === 'mobile';

 return (
 <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${isMobile ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-600'}`}>
 {isMobile ? 'Mobile' : 'Web'}
 </span>
 );
 },
 },
 {
 key: 'status',
 header: 'Status',
 render: (t: Ticket) => {
 const isFinished = ['solve', 'selesai'].includes(String(t.status || '').toLowerCase());

 return (
 <div className="flex flex-col items-start gap-1">
 <StatusBadge status={t.status} />
 {t.assignment_queued_at && (
 <span className="text-[11px] font-medium text-amber-700">Antrean petugas</span>
 )}
 {currentTab === 'saya_ajukan' && isFinished && (
 t.csat?.rating ? (
 <span className="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 border border-emerald-200">
 ★ {t.csat.rating}/5
 </span>
 ) : (
 <span className="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 border border-amber-200">
 Perlu Rating
 </span>
 )
 )}
 </div>
 );
 },
 },
 {
 key: 'aksi',
 header: 'Aksi',
 className: currentTab === 'saya_ajukan' ? 'w-[190px]' : 'w-[100px]',
 render: (t: Ticket) => (
 <div className="flex items-center gap-1.5">
 <Button variant="outline" size="sm" onClick={() => router.get(route('admin.tiket.show', t.id))}>
 <Eye className="w-4 h-4 mr-1" /> Detail
 </Button>
 {currentTab === 'saya_ajukan' && t.can_cancel && (
 <Button
 type="button"
 variant="outline"
 size="sm"
 className="text-red-600 border-red-200 hover:bg-red-50 hover:text-red-700"
 onClick={() => setCancelTargetTicket(t)}
 >
 <XCircle className="w-4 h-4 mr-1" /> Batalkan
 </Button>
 )}
 </div>
 ),
 },
 ].filter(c => !(currentTab === 'saya_ajukan' && c.key === 'user'));

 const loanColumns = [
  {
   key: 'id',
   header: 'ID Tiket',
   render: (t: Ticket) => (
    <div className="space-y-1">
     <div className="font-mono text-xs font-semibold text-slate-800">#TKT-{formatTicketId(t.id)}</div>
     <StatusBadge status={t.status} />
    </div>
   ),
  },
  {
   key: 'peminjam',
   header: 'Peminjam',
   render: (t: Ticket) => (
    <div>
     <p className="font-medium text-slate-900">
      {t.user?.name || t.user?.username || (t.created_by_admin ? `${t.created_by_admin.name || t.created_by_admin.username} (Admin)` : '-')}
     </p>
     <p className="text-xs text-slate-500">
      {t.user?.divisi?.nama_divisi || (t.created_by_admin ? 'Internal Admin' : '-')}
     </p>
    </div>
   ),
  },
  {
   key: 'alat_jadwal',
   header: 'Alat & Jadwal Pakai',
   render: (t: Ticket) => (
    <div className="space-y-1 max-w-xs">
     <p className="font-medium text-slate-800 text-xs sm:text-sm">
      {t.loan_summary?.alat_text || t.sub_unit?.nama_layanan || '-'}
     </p>
     <p className="text-xs text-slate-500">
      Jadwal: {t.loan_summary?.rentang_waktu || '-'}
     </p>
     {t.loan_summary?.is_overdue_schedule && (
      <span className="inline-flex items-center rounded bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700 border border-rose-200">
       Jadwal pakai sudah lewat
      </span>
     )}
     {!t.loan_summary?.is_overdue_schedule && t.loan_summary?.is_due_today && (
      <span className="inline-flex items-center rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800 border border-amber-200">
       Jadwal aktif hari ini
      </span>
     )}
    </div>
   ),
  },
  {
   key: 'status_serah_kembali',
   header: 'Status & Keterangan Serah/Kembali',
   render: (t: Ticket) => {
    const statusPinjam = t.loan_summary?.status_peminjaman || 'Menunggu Persetujuan';

    const badgeStyle =
     statusPinjam === 'Dikembalikan'
      ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
      : statusPinjam === 'Belum Dikembalikan'
      ? 'bg-rose-100 text-rose-800 border-rose-200'
      : statusPinjam === 'Sedang Dipinjam'
      ? 'bg-sky-100 text-sky-800 border-sky-200'
      : statusPinjam === 'Menunggu Diserahkan'
      ? 'bg-amber-100 text-amber-800 border-amber-200'
      : 'bg-purple-100 text-purple-800 border-purple-200';

    const penyerah = t.diserahkan_oleh_admin?.name || t.diserahkan_oleh_admin?.username;
    const penerimaKembali = t.diterima_kembali_oleh_admin?.name || t.diterima_kembali_oleh_admin?.username;
    const handoverCondition = t.kondisi_serah ? resolveConditionMeta(t.kondisi_serah) : null;
    const returnCondition = t.kondisi_kembali ? resolveConditionMeta(t.kondisi_kembali) : null;

    return (
     <div className="space-y-1.5 text-xs">
      <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border ${badgeStyle}`}>
       {statusPinjam}
      </span>
      {t.diserahkan_at && (
       <div className="text-slate-600 space-y-0.5">
        <div>
         Diserahkan: <span className="font-medium">{formatDateId(t.diserahkan_at)}</span>
         {penyerah ? ` (${penyerah})` : ''}
        </div>
        {(handoverCondition || (t.lampiran_serah_count ?? 0) > 0) && (
         <div className="flex flex-wrap items-center gap-1.5">
          {handoverCondition && (
           <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold ${handoverCondition.className}`}>
            {handoverCondition.label}
           </span>
          )}
          {(t.lampiran_serah_count ?? 0) > 0 && (
           <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 border border-slate-200 text-[10px] font-medium">
            <Paperclip className="w-2.5 h-2.5" />
            {t.lampiran_serah_count}
           </span>
          )}
         </div>
        )}
        {t.penerima_alat && <div>Penerima: {t.penerima_alat}</div>}
        {t.catatan_serah && <div className="italic text-slate-500">&ldquo;{t.catatan_serah}&rdquo;</div>}
       </div>
      )}
      {t.dikembalikan_at && (
       <div className="text-slate-600 space-y-0.5 pt-1 border-t border-slate-100">
        <div>
         Dikembalikan: <span className="font-medium">{formatDateId(t.dikembalikan_at)}</span>
         {penerimaKembali ? ` (${penerimaKembali})` : ''}
        </div>
        {(returnCondition || (t.lampiran_kembali_count ?? 0) > 0) && (
         <div className="flex flex-wrap items-center gap-1.5">
          {returnCondition && (
           <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold ${returnCondition.className}`}>
            {returnCondition.label}
           </span>
          )}
          {(t.lampiran_kembali_count ?? 0) > 0 && (
           <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 border border-slate-200 text-[10px] font-medium">
            <Paperclip className="w-2.5 h-2.5" />
            {t.lampiran_kembali_count}
           </span>
          )}
         </div>
        )}
        {t.catatan_kembali && <div className="italic text-slate-500">&ldquo;{t.catatan_kembali}&rdquo;</div>}
       </div>
      )}
     </div>
    );
   },
  },
  {
   key: 'aksi',
   header: 'Aksi',
   className: 'w-[280px]',
   render: (t: Ticket) => (
    <div className="flex flex-wrap items-center gap-1.5">
     {t.can_serahkan && (
      <Button
       type="button"
       size="sm"
       className="bg-sky-600 hover:bg-sky-700 text-white"
       onClick={() => setHandoverModalTicket(buildLoanModalTarget(t))}
      >
       <PackageCheck className="w-3.5 h-3.5 mr-1" /> Serahkan
      </Button>
     )}
     {t.can_kembalikan && (
      <Button
       type="button"
       size="sm"
       variant={t.can_serahkan ? 'outline' : 'default'}
       className={
        t.can_serahkan
         ? 'border-emerald-300 text-emerald-700 hover:bg-emerald-50'
         : 'bg-emerald-600 hover:bg-emerald-700 text-white'
       }
       onClick={() => setReturnModalTicket(buildLoanModalTarget(t))}
      >
       <CheckCircle2 className="w-3.5 h-3.5 mr-1" /> Kembalikan
      </Button>
     )}
     <Button variant="outline" size="sm" onClick={() => router.get(route('admin.tiket.show', t.id))}>
      <Eye className="w-4 h-4 mr-1" /> Detail
     </Button>
    </div>
   ),
  },
 ];

 const columns = currentTab === 'peminjaman_alat' ? loanColumns : defaultColumns;

 const visibleTabs = canViewEquipmentLoans ? TABS : TABS.filter((t) => t.key !== 'peminjaman_alat');

 return (
 <AdminLayout title="Daftar Tiket">
 <Head title="Daftar Tiket" />

 {operatorLoads.length > 0 && (
 <div className="mb-4 rounded-lg border bg-white p-3">
 <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Beban Kerja Petugas</p>
 <ul className="flex flex-col gap-1">
 {operatorLoads.map((op: OperatorLoad) => {
 const expanded = openOperator === op.admin_id;
 const hasDetail = (op.tickets?.length ?? 0) > 0;

 return (
 <li key={op.admin_id} className="rounded-md border">
 <button
 type="button"
 onClick={() => hasDetail && setOpenOperator(expanded ? null : op.admin_id)}
 aria-expanded={expanded}
 className="flex w-full flex-wrap items-center gap-2 px-2.5 py-1.5 text-xs text-left hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-md"
 >
 <span className="font-medium text-slate-700">{op.name}</span>
 <span className="rounded bg-slate-100 px-1.5 py-0.5 font-semibold text-slate-700">
 {op.total ?? (op.working + op.paused + op.queued)} tiket
 </span>
 <span className="rounded bg-sky-100 px-1.5 py-0.5 font-semibold text-sky-800">{op.working} dikerjakan</span>
 {op.queued > 0 && (
 <span className="rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-800">{op.queued} antre</span>
 )}
 {op.paused > 0 && (
 <span className="rounded bg-orange-100 px-1.5 py-0.5 font-semibold text-orange-800">{op.paused} tertunda</span>
 )}
 {hasDetail && (
 <ChevronDown className={`ml-auto h-3.5 w-3.5 text-slate-400 transition-transform ${expanded ? 'rotate-180' : ''}`} />
 )}
 </button>
 {expanded && hasDetail && (
 <ul className="space-y-1 border-t px-2.5 py-2">
 {op.tickets.map((tk: OperatorLoadTicket) => (
 <li key={tk.id} className="flex flex-wrap items-center gap-2 text-xs">
 <span className="font-mono font-medium">#TKT-{tk.formatted_id}</span>
 <span className="text-slate-500">{tk.service}</span>
 <StatusBadge status={tk.status} />
 <span
 className={`font-medium ${
 tk.position !== null
 ? 'text-amber-700'
 : tk.is_work_hours_paused || tk.is_manual_pending || tk.status === 'pending'
 ? 'text-orange-700'
 : 'text-sky-700'
 }`}
 >
 {tk.position !== null
 ? op.work_hours_open ? `Antrean ke-${tk.position}` : `Antrean ke-${tk.position} • menunggu jam kerja`
 : tk.is_work_hours_paused
 ? 'Tertunda • jeda jam kerja'
 : tk.status === 'pending'
 ? 'Tertunda'
 : 'Sedang dikerjakan'}
 </span>
 {tk.urgent && (
 <span className="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-700">Urgen</span>
 )}
 <div className="ml-auto flex items-center gap-1">
 {tk.position !== null && (
 <>
 <Button
 variant="outline"
 size="icon-sm"
 aria-label={`Naikkan antrean #TKT-${tk.formatted_id}`}
 disabled={tk.position <= 1}
 onClick={() => router.patch(route('admin.tiket.queue-move', tk.id), { direction: 'up' }, { preserveScroll: true, preserveState: true })}
 >
 <ArrowUp className="size-3.5" />
 </Button>
 <Button
 variant="outline"
 size="icon-sm"
 aria-label={`Turunkan antrean #TKT-${tk.formatted_id}`}
 disabled={tk.position >= op.queued}
 onClick={() => router.patch(route('admin.tiket.queue-move', tk.id), { direction: 'down' }, { preserveScroll: true, preserveState: true })}
 >
 <ArrowDown className="size-3.5" />
 </Button>
 <Button
 type="button"
 variant="outline"
 size="sm"
 disabled={swapping}
 title="Ganti tiket yang sedang dikerjakan dengan tiket antrean ini"
 className="h-7 border-blue-300 bg-blue-50 px-2 text-xs font-medium text-blue-700 hover:bg-blue-100"
 onClick={() => setSwapTarget({ ticket: tk, hasActive: op.working > 0 })}
 >
 <Play className="mr-1 size-3" /> Kerjakan
 </Button>
 </>
 )}
 {tk.position === null && !tk.is_work_hours_paused && tk.status === 'pending' && (
 <Button
 variant="outline"
 size="sm"
 disabled={enqueuingTicketId === tk.id}
 className="h-7 border-amber-300 bg-amber-50 px-2 text-xs font-medium text-amber-800 hover:bg-amber-100"
 onClick={() => enqueueTicket(tk.id)}
 >
 {enqueuingTicketId === tk.id ? 'Memproses...' : 'Masuk Antrean'}
 </Button>
 )}
 <Button variant="ghost" size="sm" onClick={() => router.get(route('admin.tiket.show', tk.id))}>
 Lihat
 </Button>
 </div>
 </li>
 ))}
 </ul>
 )}
 </li>
 );
 })}
 </ul>
 </div>
 )}

 <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
 <div className="relative w-full sm:max-w-sm">
 <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
 <input
 type="text"
 value={search}
 onChange={e => setSearch(e.target.value)}
 onKeyDown={e => e.key === 'Enter' && applyFilter()}
 placeholder="Cari ID tiket, pengaju, layanan..."
 className="w-full rounded-md border border-input bg-transparent pl-9 pr-8 py-2 text-sm"
 />
 {search && (
                  <button onClick={() => { setSearch(''); applyFilter({ search: '' }); }} className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                    <X className="h-4 w-4" />
                  </button>
 )}
 </div>
 <div className="flex flex-wrap items-center gap-2">
 <Button
 type="button"
 variant={isMyAssignmentActive ? 'default' : 'outline'}
 aria-pressed={isMyAssignmentActive}
 onClick={toggleAssignedToMe}
 className={`flex items-center gap-2 ${
 isMyAssignmentActive
 ? 'border-indigo-600 bg-indigo-600 text-white hover:bg-indigo-700'
 : 'border-indigo-200 text-indigo-700 hover:bg-indigo-50 hover:text-indigo-800'
 }`}
 >
 <UserCheck className="w-4 h-4" />
 <span>Ditugaskan ke Saya</span>
 <span
 className={`rounded-full px-1.5 py-0.5 text-xs font-semibold ${
 isMyAssignmentActive ? 'bg-white/20 text-white' : 'bg-indigo-100 text-indigo-700'
 }`}
 >
 {assignedToMeCount}
 </span>
 </Button>
 <Button onClick={() => setShowFilter(!showFilter)} variant="outline" className="flex items-center gap-2">
 <Filter className="w-4 h-4" />
 {showFilter ? 'Sembunyikan Filter' : 'Tampilkan Filter'}
 </Button>
 {canBulkCreate && (
 <Button variant="outline" onClick={() => router.get(route('admin.tickets.bulk.create'))} className="flex items-center gap-2">
 <Send className="w-4 h-4" /> Ajukan Banyak Tiket
 </Button>
 )}
 <Button onClick={() => router.get(route('admin.tiket.create'))} className="flex items-center gap-2">
 <Plus className="w-4 h-4" /> Ajukan Tiket
 </Button>
 </div>
 </div>

 <div className="flex flex-wrap gap-2 mb-4 border-b">
 {visibleTabs.map(({ key, label, Icon }) => (
 <button
 key={key}
 onClick={() => changeTab(key)}
 className={`flex items-center gap-2 px-4 py-2 text-sm font-medium border-b-2 -mb-px ${currentTab === key ? 'border-primary text-primary' : 'border-transparent text-slate-500 hover:text-slate-700'}`}
 >
 <Icon className="w-4 h-4" /> {label}
 <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{tabCounts?.[key] ?? 0}</span>
 </button>
 ))}
 </div>

 {currentTab === 'peminjaman_alat' && (
 <div className="flex flex-wrap items-center gap-2 mb-4">
 {LOAN_STAGES.map(({ key, label }) => {
 const active = activeLoanStage === key;
 const count = loanStageCounts?.[key] ?? 0;

 return (
 <button
 key={key}
 type="button"
 onClick={() => applyFilter({ loan_stage: key })}
 className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors ${
 active
 ? 'border-teal-600 bg-teal-600 text-white shadow-2xs'
 : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
 }`}
 >
 <span>{label}</span>
 <span
 className={`rounded-full px-1.5 py-0.5 text-[11px] ${
 active ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-700'
 }`}
 >
 {count}
 </span>
 </button>
 );
 })}
 </div>
 )}

 {showFilter && (
 <div className="bg-white rounded-lg border p-4 mb-4 space-y-4">
 <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
 <select className="rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={unitId} onChange={e => { setUnitId(e.target.value); setSubUnitId(''); setSubUnits([]);

 if (e.target.value) fetch(`/api/sub-units/${e.target.value}`).then(r => r.json()).then(setSubUnits); }}>
 <option value="">Semua Unit</option>
 {units.map((u: any) => <option key={u.id} value={u.id}>{u.nama_unit}</option>)}
 </select>
 <select className="rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={subUnitId} onChange={e => setSubUnitId(e.target.value)}>
 <option value="">Semua Sub Unit</option>
 {subUnits.map((s: any) => <option key={s.id} value={s.id}>{s.nama_layanan}</option>)}
 </select>
 <select className="rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={divisiId} onChange={e => setDivisiId(e.target.value)}>
 <option value="">Semua Divisi</option>
 {divisiList.map((d: any) => <option key={d.id} value={d.id}>{d.nama_divisi}</option>)}
 </select>
 <select className="rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={orgUnitId} onChange={e => setOrgUnitId(e.target.value)}>
 <option value="">Semua Unit Organisasi</option>
 {orgUnitList.map((o: any) => <option key={o.id} value={o.id}>{o.nama_unit_organisasi}</option>)}
 </select>
 </div>
 
 <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 gap-4">
 {currentTab !== 'peminjaman_alat' && (
 <div className="flex flex-col gap-1.5 lg:col-span-5">
 <span className="text-sm font-medium text-slate-700 ">Status</span>
 <div className="flex flex-wrap items-center gap-3 p-2.5 border rounded-md bg-slate-50/50 ">
 {STATUS_LIST.map(s => (
 <label key={s.value} className="flex items-center gap-1.5 text-sm cursor-pointer">
 <input type="checkbox" checked={selectedStatuses.includes(s.value)} onChange={() => toggleStatus(s.value)} className="rounded border-slate-300 text-primary focus:ring-primary" />
 {s.label}
 </label>
 ))}
 </div>
 </div>
 )}

 <div className="flex flex-col gap-1.5 lg:col-span-4">
 <span className="text-sm font-medium text-slate-700 ">Tanggal</span>
 <DateRangePicker dateFrom={dateFrom} dateTo={dateTo} onDateFromChange={setDateFrom} onDateToChange={setDateTo} />
 </div>

 <div className="flex flex-col gap-1.5 lg:col-span-3">
 <label htmlFor="filter-penugasan" className="text-sm font-medium text-slate-700">Penugasan</label>
 <select
 id="filter-penugasan"
 className="h-[42px] rounded-md border border-input bg-transparent px-3 py-2 text-sm"
 value={assignment}
 onChange={e => setAssignment(e.target.value)}
 >
 <option value="">Semua Penugasan</option>
 {operators.map((op) => {
 const isSelf = currentAdminId !== null && Number(op.id) === Number(currentAdminId);

 return (
 <option key={op.id} value={String(op.id)}>
 {op.name || op.username}{isSelf ? ' (Saya)' : ''}
 </option>
 );
 })}
 </select>
 </div>
 </div>

 <div className="flex flex-wrap justify-end gap-2 pt-2">
 <Button type="button" variant="outline" onClick={resetFilter} className="w-full md:w-auto">Reset Filter</Button>
 <Button onClick={() => applyFilter()} className="w-full md:w-auto">Terapkan Filter</Button>
 </div>
 </div>
 )}

 <DataTable
 columns={columns}
 data={tickets.data || []}
 keyExtractor={(t: Ticket) => t.id}
 rowClassName={(t: Ticket) =>
 isAssignedToMe(t)
 ? 'bg-indigo-50/70 hover:bg-indigo-100/60 [&_td:first-child]:shadow-[inset_3px_0_0_0_#4f46e5]'
 : undefined
 }
 />
 <Pagination links={tickets.links} />

 <ConfirmDialog
 open={cancelTargetTicket !== null}
 onOpenChange={(open) => {
 if (!open) setCancelTargetTicket(null);
 }}
 title="Batalkan Tiket?"
 message={
 cancelTargetTicket
 ? (cancelTargetTicket.booking
 ? `Tiket #TKT-${formatTicketId(cancelTargetTicket.id)} beserta jadwal peminjamannya akan dibatalkan dan aset kembali tersedia. Yakin ingin membatalkan?`
 : `Tiket #TKT-${formatTicketId(cancelTargetTicket.id)} yang dibatalkan tidak dapat diproses lagi oleh tim penangan. Yakin ingin membatalkan?`)
 : ''
 }
 confirmText="Ya, Batalkan Tiket"
 cancelText="Tutup"
 onConfirm={() => {
 if (!cancelTargetTicket) return;

 router.patch(route('admin.tiket.cancel', cancelTargetTicket.id), {}, {
 preserveScroll: true,
 onSuccess: () => setCancelTargetTicket(null),
 });
 }}
 />

 <ConfirmDialog
 open={swapTarget !== null}
 variant="default"
 onOpenChange={(open) => {
 if (!open && !swapping) setSwapTarget(null);
 }}
 title="Kerjakan Tiket Ini Sekarang?"
 message={
 swapTarget
 ? swapTarget.hasActive
 ? `Tiket yang sedang dikerjakan akan dijeda sementara ke antrean terdepan, dan tiket #TKT-${swapTarget.ticket.formatted_id} akan langsung menjadi tiket aktif. Lanjutkan?`
 : `Tiket #TKT-${swapTarget.ticket.formatted_id} akan langsung diaktifkan dari antrean untuk dikerjakan. Lanjutkan?`
 : ''
 }
 confirmText={swapping ? 'Memproses...' : 'Ya, Kerjakan Sekarang'}
 cancelText="Batal"
 onConfirm={() => {
 if (!swapTarget || swapping) return;

 setSwapping(true);
 router.post(route('admin.tiket.activate-from-queue', swapTarget.ticket.id), {}, {
 preserveScroll: true,
 preserveState: true,
 onSuccess: () => setSwapTarget(null),
 onFinish: () => setSwapping(false),
 });
 }}
 />

 <HandoverEquipmentModal
 open={handoverModalTicket !== null}
 onOpenChange={(open) => {
 if (!open) setHandoverModalTicket(null);
 }}
 ticket={handoverModalTicket}
 />

 <ReturnEquipmentModal
 open={returnModalTicket !== null}
 onOpenChange={(open) => {
 if (!open) setReturnModalTicket(null);
 }}
 ticket={returnModalTicket}
 />
 </AdminLayout>
 );
}
