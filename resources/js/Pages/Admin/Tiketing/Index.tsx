import React, { useState, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable } from '@/Components/DataTable';
import { StatusBadge } from '@/Components/StatusBadge';
import SlaBadge from '@/Components/SlaBadge';
import { Pagination } from '@/Components/Pagination';
import { Button } from '@/Components/ui/button';
import { DateRangePicker } from '@/Components/ui/date-range-picker';
import { ArrowDown, ArrowUp, ChevronDown, Eye, Filter, Inbox, Plus, Search, Send, X } from 'lucide-react';
import { formatTicketId } from '@/lib/utils';

const STATUS_LIST = [
  { value: 'open', label: 'Baru' },
  { value: 'on_proses', label: 'Diproses' },
  { value: 'pending', label: 'Tertunda' },
  { value: 'need_revision', label: 'Butuh Revisi' },
  { value: 'solve', label: 'Selesai' },
];

interface Ticket {
 id: number;
 user?: { id: number; username: string; divisi?: { nama_divisi: string } } | null;
 created_by_admin?: { id: number; name: string; username: string } | null;
 unit?: { nama_unit: string };
 sub_unit?: { nama_layanan: string };
 assigned_admin?: { id: number; name: string; username: string };
 source?: 'web' | 'mobile' | string;
 status: string;
 created_at: string;
 assignment_queued_at?: string | null;
}

const TABS = [
 { key: 'masuk', label: 'Tiket Masuk', Icon: Inbox },
 { key: 'saya_ajukan', label: 'Tiket Saya Ajukan', Icon: Send },
];

export default function TicketIndex({ tickets, filters, units, divisiList, orgUnitList, currentTab = 'masuk', tabCounts, operatorLoads = [], canBulkCreate = false }: any) {
 const [showFilter, setShowFilter] = useState(() => {
 return !!(filters?.unit_id || filters?.sub_unit_id || filters?.status || filters?.date_from || filters?.date_to || filters?.divisi_id || filters?.org_unit_id);
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
 const [subUnits, setSubUnits] = useState<any[]>([]);
 const [search, setSearch] = useState(filters?.search || '');
 const [openOperator, setOpenOperator] = useState<number | null>(null);

 useEffect(() => {
 if (unitId) {
 fetch(`/api/sub-units/${unitId}`).then(r => r.json()).then(setSubUnits);
 }
 }, []);

 const toggleStatus = (val: string) => {
 setSelectedStatuses(prev =>
 prev.includes(val) ? prev.filter(s => s !== val) : [...prev, val]
 );
 };

 const changeTab = (tab: string) => {
 router.get(route('admin.tiket.index'), { tab }, {});
 };

  const applyFilter = (searchOverride?: string) => {
    const params: { [key: string]: string | string[] | undefined } = { tab: currentTab };
    const keyword = searchOverride ?? search;

 if (unitId) params.unit_id = unitId;

 if (subUnitId) params.sub_unit_id = subUnitId;

 if (selectedStatuses.length > 0) params.status = selectedStatuses;

 if (dateFrom) params.date_from = dateFrom;

 if (dateTo) params.date_to = dateTo;

 if (divisiId) params.divisi_id = divisiId;

 if (orgUnitId) params.org_unit_id = orgUnitId;

 if (keyword) params.search = keyword;
 router.get(route('admin.tiket.index'), params, { });
 };

 const columns = [
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
 render: (t: Ticket) => (
 <p className="font-medium">{t.assigned_admin?.name || t.assigned_admin?.username || '-'}</p>
 ),
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
 render: (t: Ticket) => (
 <div className="flex flex-col items-start gap-1">
 <StatusBadge status={t.status} />
 {t.assignment_queued_at && (
 <span className="text-[11px] font-medium text-amber-700">Antrean petugas</span>
 )}
 </div>
 ),
 },
 {
 key: 'aksi',
 header: 'Aksi',
 className: 'w-[100px]',
 render: (t: Ticket) => (
 <Button variant="outline" size="sm" onClick={() => router.get(route('admin.tiket.show', t.id))}>
 <Eye className="w-4 h-4 mr-1" /> Detail
 </Button>
 ),
 },
 ].filter(c => !(currentTab === 'saya_ajukan' && c.key === 'user'));

 return (
 <AdminLayout title="Daftar Tiket">
 <Head title="Daftar Tiket" />

 {operatorLoads.length > 0 && (
 <div className="mb-4 rounded-lg border bg-white p-3">
 <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Beban Kerja Petugas</p>
 <ul className="flex flex-col gap-1">
 {operatorLoads.map((op: any) => {
 const expanded = openOperator === op.admin_id;
 const hasDetail = (op.tickets?.length ?? 0) > 0;
 return (
 <li key={op.admin_id} className="rounded-md border">
 <button
 type="button"
 onClick={() => hasDetail && setOpenOperator(expanded ? null : op.admin_id)}
 aria-expanded={expanded}
 className="flex w-full items-center gap-2 px-2.5 py-1.5 text-xs text-left hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-md"
 >
 <span className="font-medium text-slate-700">{op.name}</span>
 <span className="rounded bg-sky-100 px-1.5 py-0.5 font-semibold text-sky-800">{op.working} dikerjakan</span>
 {op.paused > 0 && (
 <span className="rounded bg-orange-100 px-1.5 py-0.5 font-semibold text-orange-800">{op.paused} tertunda</span>
 )}
 {op.queued > 0 && (
 <span className="rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-800">{op.queued} antre</span>
 )}
 {hasDetail && (
 <ChevronDown className={`ml-auto h-3.5 w-3.5 text-slate-400 transition-transform ${expanded ? 'rotate-180' : ''}`} />
 )}
 </button>
 {expanded && hasDetail && (
 <ul className="space-y-1 border-t px-2.5 py-2">
 {op.tickets.map((tk: any) => (
 <li key={tk.id} className="flex flex-wrap items-center gap-2 text-xs">
 <span className="font-mono font-medium">#TKT-{tk.formatted_id}</span>
 <span className="text-slate-500">{tk.service}</span>
 <StatusBadge status={tk.status} />
 <span className={`font-medium ${tk.position === null ? 'text-sky-700' : 'text-amber-700'}`}>
 {tk.position !== null
   ? op.work_hours_open ? `Antrean ke-${tk.position}` : `Antrean ke-${tk.position} • menunggu jam kerja`
   : tk.is_work_hours_paused ? 'Tertunda' : 'Sedang dikerjakan'}
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
 </>
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
                  <button onClick={() => { setSearch(''); applyFilter(''); }} className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                    <X className="h-4 w-4" />
                  </button>
 )}
 </div>
 <div className="flex flex-wrap items-center gap-2">
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

 <div className="flex gap-2 mb-4 border-b">
 {TABS.map(({ key, label, Icon }) => (
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
 
 <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
 <div className="flex flex-col gap-1.5">
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
 
 <div className="flex flex-col gap-1.5">
 <span className="text-sm font-medium text-slate-700 ">Tanggal</span>
 <DateRangePicker dateFrom={dateFrom} dateTo={dateTo} onDateFromChange={setDateFrom} onDateToChange={setDateTo} />
 </div>
 </div>

 <div className="flex justify-end pt-2">
 <Button onClick={() => applyFilter()} className="w-full md:w-auto">Terapkan Filter</Button>
 </div>
 </div>
 )}

 <DataTable columns={columns} data={tickets.data || []} keyExtractor={(t: Ticket) => t.id} />
 <Pagination links={tickets.links} />
 </AdminLayout>
 );
}
