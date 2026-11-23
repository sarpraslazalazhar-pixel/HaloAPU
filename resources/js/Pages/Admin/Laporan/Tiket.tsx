import React, { useState, useEffect, useRef } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { DataTable } from '@/Components/DataTable';
import { StatusBadge } from '@/Components/StatusBadge';
import SlaBadge from '@/Components/SlaBadge';
import { Pagination } from '@/Components/Pagination';
import { Eye, Folder, Clock, Hourglass, CheckCircle, XCircle, Ban, Printer, Download, Filter, Search, X } from 'lucide-react';
import LazyECharts from '@/Components/Charts/LazyECharts';
import { formatTicketId } from '@/lib/utils';
import { DateRangePicker } from '@/Components/ui/date-range-picker';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';

const STATUS_META = {
 open: { label: 'Tiket Masuk', bg: 'from-blue-500 to-blue-600', icon: Folder, anim: 'group-hover:-translate-y-2 group-hover:rotate-12 group-hover:opacity-100' },
 on_proses: { label: 'Diproses', bg: 'from-orange-500 to-orange-600', icon: Clock, anim: 'group-hover:-rotate-12 group-hover:scale-110 group-hover:opacity-100' },
 pending: { label: 'Tertunda', bg: 'from-zinc-500 to-zinc-600', icon: Hourglass, anim: 'group-hover:rotate-180 transition-transform duration-500 group-hover:opacity-100' },
 solve: { label: 'Selesai', bg: 'from-green-500 to-green-600', icon: CheckCircle, anim: 'group-hover:scale-125 group-hover:opacity-100' },
 reject: { label: 'Ditolak', bg: 'from-red-500 to-red-600', icon: XCircle, anim: 'group-hover:rotate-90 group-hover:scale-110 group-hover:opacity-100' },
 dibatalkan: { label: 'Dibatalkan', bg: 'from-rose-500 to-rose-600', icon: Ban, anim: 'group-hover:scale-90 group-hover:opacity-100' },
} satisfies Record<string, { label: string; bg: string; icon: React.ElementType; anim: string }>;

const WORKLOAD_LABELS: Record<string, string> = {
 '1': '1/5 Sangat ringan',
 '2': '2/5 Ringan',
 '3': '3/5 Sedang',
 '4': '4/5 Berat',
 '5': '5/5 Sangat berat',
};

const WORKLOAD_ERROR = 'Penilaian gagal disimpan. Muat ulang laporan dan coba lagi.';

const toWorkloadMinutes = (hours: string) => Math.round(Number(hours) * 60);

function formatWorkloadDuration(totalMinutes: number) {
 const hours = Math.floor(totalMinutes / 60);
 const minutes = totalMinutes % 60;
 if (!hours) return `${minutes} menit`;
 const hourLabel = `${hours.toLocaleString('id-ID')} jam`;
 return minutes ? `${hourLabel} ${minutes} menit` : hourLabel;
}

const STATUS_LIST = [
 { value: 'open', label: 'Baru' },
 { value: 'on_proses', label: 'Diproses' },
 { value: 'pending', label: 'Tertunda' },
 { value: 'waiting_approval', label: 'Menunggu Review' },
 { value: 'need_revision', label: 'Butuh Revisi' },
 { value: 'solve', label: 'Selesai' },
 { value: 'reject', label: 'Ditolak' },
 { value: 'dibatalkan', label: 'Dibatalkan' },
];

export default function LaporanTiket({
 filters, units, subUnits: initialSubUnits, divisiList,
 totalTickets: _totalTickets, statusCounts, slaStats, slaPieChartData,
 monthlyTrend, ticketsByUnit, ticketsByStatus: _ticketsByStatus, ticketsByLayanan, tickets
}: any) {
 const maxLayanan = ticketsByLayanan?.length > 0 ? Math.max(...ticketsByLayanan.map((t: any) => t.count)) : 1;
 const pageProps = usePage<any>().props;
 const auth = pageProps.auth;
 const canAssess = !!auth?.can_assess_ticket_workload;
 const canExport = pageProps.canExport !== false;

 const [month, setMonth] = useState(filters?.month || '');
 const [year, setYear] = useState(filters?.year ?? '');
 const [dateFrom, setDateFrom] = useState(filters?.date_from || '');
 const [dateTo, setDateTo] = useState(filters?.date_to || '');
 const [unitId, setUnitId] = useState(filters?.unit_id || '');
 const [subUnitId, setSubUnitId] = useState(filters?.sub_unit_id || '');
 const [status, setStatus] = useState(filters?.status || '');
 const [divisiId, setDivisiId] = useState(filters?.divisi_id || '');
 const [subUnits, setSubUnits] = useState<any[]>(initialSubUnits || []);
 const [showFilter, setShowFilter] = useState(false);
 const [exporting, setExporting] = useState(false);
 const [search, setSearch] = useState(filters?.search || '');
 const workload = filters?.workload === 'unassessed' ? 'unassessed' : '';
 const [selectedTicket, setSelectedTicket] = useState<any | null>(null);
 const [generalError, setGeneralError] = useState('');
 const openerRef = useRef<HTMLElement | null>(null);
 const { data, setData, transform, put, processing, errors, setError, clearErrors, reset } = useForm({
 score: '',
 effort_hours: '',
 effort_minutes: '',
 note: '',
 });
 const effortDescriptionId = errors.effort_hours ? 'workload-effort-error' : 'workload-effort-help';

 useEffect(() => {
 if (unitId && (!subUnits || subUnits.length === 0)) {
 fetch(`/api/sub-units/${unitId}`).then(r => r.json()).then(setSubUnits);
 }
 }, []);

 useEffect(() => {
 setYear(filters?.year ?? '');
 }, [filters?.year]);

 useEffect(() => {
 if (window.location.hash === '#rincian-tiket') {
 document.getElementById('rincian-tiket')?.scrollIntoView();
 }
 }, []);

 const applyFilter = () => {
 const params: any = {};

 if (month) params.month = month;

 if (year) params.year = year;

 if (dateFrom) params.date_from = dateFrom;

 if (dateTo) params.date_to = dateTo;

 if (unitId) params.unit_id = unitId;

 if (subUnitId) params.sub_unit_id = subUnitId;

 if (status) params.status = status;

 if (divisiId) params.divisi_id = divisiId;

 if (search) params.search = search;

 if (canAssess && workload === 'unassessed') params.workload = 'unassessed';
 router.get(route('admin.laporan.tiket'), params, { preserveState: true });
 };

 const applyWorkloadFilter = (value: string) => {
 const source = filters || {};
 const params: Record<string, string> = {};
 ['year', 'month', 'date_from', 'date_to', 'unit_id', 'sub_unit_id', 'status', 'divisi_id', 'search'].forEach(key => {
 if (source[key] !== null && source[key] !== undefined && source[key] !== '') params[key] = String(source[key]);
 });
 if (value) params.workload = value;
 router.get(route('admin.laporan.tiket'), params, { preserveState: true, preserveScroll: true });
 };

 const openAssessment = (ticket: any) => {
 clearErrors();
 reset();
 setGeneralError('');
 const existing = ticket.workload_assessment;
 if (existing) {
 const minutes = toWorkloadMinutes(existing.effort_hours ?? '');
 setData({
 score: String(existing.score ?? ''),
 effort_hours: minutes ? String(Math.floor(minutes / 60)) : '',
 effort_minutes: minutes ? String(minutes % 60) : '',
 note: existing.note ?? '',
 });
 }
 setSelectedTicket(ticket);
 };

 const closeAssessment = () => {
 if (processing) return;
 setSelectedTicket(null);
 setGeneralError('');
 };

 const submitAssessment = (e: React.FormEvent) => {
 e.preventDefault();
 if (!selectedTicket) return;
 setGeneralError('');
 const totalMinutes = Number(data.effort_hours) * 60 + Number(data.effort_minutes);
 const hasDuration = data.effort_hours !== '' || data.effort_minutes !== '';
 if (hasDuration && totalMinutes === 0) {
 setError('effort_hours', 'Isi minimal 1 menit atau kosongkan kedua kolom.');
 return;
 }
 clearErrors('effort_hours');
 const savedHours = selectedTicket.workload_assessment?.effort_hours;
 const effortHours = savedHours && toWorkloadMinutes(savedHours) === totalMinutes
 ? savedHours
 : (totalMinutes / 60).toFixed(2);
 transform(({ effort_minutes: _, ...fields }) => ({
 ...fields,
 effort_hours: hasDuration ? effortHours : '',
 }));
 put(route('admin.laporan.tiket.penilaian-beban', { ticket: selectedTicket.id }), {
 preserveScroll: true,
 onSuccess: () => {
 setSelectedTicket(null);
 setGeneralError('');
 reset();
 },
 onNetworkError: () => {
 setGeneralError(WORKLOAD_ERROR);
 return false;
 },
 onHttpException: () => {
 setGeneralError(WORKLOAD_ERROR);
 return false;
 },
 });
 };

 const handlePrint = () => {
 window.print();
 };

 const handleExportExcel = () => {
   setExporting(true);
   const params = new URLSearchParams();
   if (year) params.set('year', year);
   if (month) params.set('month', month);
   if (dateFrom) params.set('date_from', dateFrom);
   if (dateTo) params.set('date_to', dateTo);
   if (unitId) params.set('unit_id', unitId);
   if (subUnitId) params.set('sub_unit_id', subUnitId);
   if (status) params.set('status', status);
   if (divisiId) params.set('divisi_id', divisiId);
   if (search) params.set('search', search);
   const qs = params.toString();
   const url = `/admin/laporan/tiket/export` + (qs ? '?' + qs : '');
   window.location.href = url;
   setTimeout(() => { setExporting(false); }, 5000);
 };

 const handleExportPdf = async () => {
   setExporting(true);
   try {
     const params = new URLSearchParams();
     if (year) params.set('year', year);
     if (month) params.set('month', month);
     if (dateFrom) params.set('date_from', dateFrom);
     if (dateTo) params.set('date_to', dateTo);
     if (unitId) params.set('unit_id', unitId);
     if (subUnitId) params.set('sub_unit_id', subUnitId);
     if (status) params.set('status', status);
     if (divisiId) params.set('divisi_id', divisiId);
     if (search) params.set('search', search);
     const qs = params.toString();
     const url = `/admin/laporan/tiket/export-pdf` + (qs ? '?' + qs : '');
     const response = await fetch(url);

     if (!response.ok) {
       const data = await response.json();
       if (data.error) {
         alert(data.error);
       } else {
         alert('Terjadi kesalahan saat membuat PDF');
       }
       setExporting(false);
       return;
     }

     const blob = await response.blob();
     const downloadUrl = window.URL.createObjectURL(blob);
     const link = document.createElement('a');
     link.href = downloadUrl;
     link.download = `laporan-tiket-${new Date().getTime()}.pdf`;
     document.body.appendChild(link);
     link.click();
     window.URL.revokeObjectURL(downloadUrl);
     document.body.removeChild(link);
   } catch (error) {
     alert('Gagal mengunduh PDF: ' + (error instanceof Error ? error.message : 'Unknown error'));
   } finally {
     setExporting(false);
   }
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
 priority={t.priority || 'low'}
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
 { key: 'id', header: 'ID Tiket', render: (t: any) =>`#TKT-${formatTicketId(t.id)}`},
 { key: 'created_at', header: 'Tgl Pengajuan', render: (t: any) => new Date(t.created_at).toLocaleDateString('id-ID') },
 {
 key: 'user',
 header: 'Pengaju',
 render: (t: any) => (
 <div>
 <p className="font-medium">{t.user?.name || t.created_by_admin?.name || t.user?.username || t.created_by_admin?.username || '-'}</p>
 <p className="text-xs text-slate-500">{t.user?.divisi?.nama_divisi || (t.created_by_admin ? 'Internal Admin' : '-')}</p>
 </div>
 ),
 },
 {
 key: 'layanan',
 header: 'Layanan',
 render: (t: any) => (
 <div>
 <p className="font-medium">{t.sub_unit?.unit?.nama_unit || '-'}</p>
 <p className="text-xs text-slate-500">{t.sub_unit?.nama_layanan || '-'}</p>
 </div>
 ),
 },
 { key: 'status', header: 'Status', render: (t: any) => <StatusBadge status={t.status} /> },
 ...(canAssess ? [{
 key: 'workload',
 header: 'Beban Kerja',
 className: 'print:hidden min-w-36',
 render: (t: any) => {
 const finished = ['solve', 'selesai'].includes(String(t.status ?? '').toLowerCase());
 const assessment = t.workload_assessment;
 const openDialog = (e: React.MouseEvent<HTMLButtonElement>) => {
 openerRef.current = e.currentTarget;
 openAssessment(t);
 };
 if (!assessment) {
 return finished ? (
 <div className="space-y-1">
 <p className="text-xs text-muted-foreground">Belum dinilai</p>
 <Button variant="outline" size="sm" className="h-11" onClick={openDialog}>Nilai Kinerja</Button>
 </div>
 ) : (
 <span className="text-muted-foreground">
 <span aria-hidden="true">-</span>
 <span className="sr-only">Penilaian tersedia setelah tiket selesai</span>
 </span>
 );
 }
 return (
 <div className="space-y-1">
 <p className="font-medium text-sm">{WORKLOAD_LABELS[String(assessment.score)] || `${assessment.score}/5`}</p>
 {assessment.effort_hours !== null && assessment.effort_hours !== undefined && (
 <p className="text-xs text-slate-500">Waktu pengerjaan: {formatWorkloadDuration(toWorkloadMinutes(assessment.effort_hours))}</p>
 )}
 {finished && <Button variant="outline" size="sm" className="h-11" onClick={openDialog}>Ubah nilai</Button>}
 </div>
 );
 },
 }] : []),
 {
 key: 'aksi',
 header: 'Aksi',
 className: 'w-[100px] print:hidden',
 render: (t: any) => (
 <Button variant="outline" size="sm" onClick={() => router.get(route('admin.tiket.show', t.id))}>
 <Eye className="w-4 h-4 mr-1" /> Detail
 </Button>
 ),
 },
 ];

 const months = [
 { value: '', label: 'Semua Bulan' },
 ...Array.from({ length: 12 }, (_, i) => ({ value: String(i + 1), label: new Date(0, i).toLocaleString('id', { month: 'long' }) })),
 ];

 const years = Array.from({ length: 5 }, (_, i) => ({ value: String(new Date().getFullYear() - i), label: String(new Date().getFullYear() - i) }));

 return (
 <AdminLayout title="Laporan Tiket">
 <Head title="Laporan Tiket" />
 <div className="space-y-6">
 
 {/* Header & Actions */}
 <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 print:hidden">
 <div>
 <h1 className="text-2xl font-bold tracking-tight">Laporan Tiket</h1>
 <p className="text-sm text-muted-foreground mt-1">
 Analitik dan statistik ringkasan tiket masuk berdasarkan filter.
 </p>
 </div>
 <div className="flex items-center gap-2">
 <Button variant="outline" onClick={() => setShowFilter(!showFilter)}>
 <Filter className="w-4 h-4 mr-2" />
 {showFilter ? 'Sembunyikan Filter' : 'Tampilkan Filter'}
 </Button>
 {canExport && (
 <>
 <Button variant="outline" onClick={handleExportPdf} disabled={exporting}>
 <Printer className="w-4 h-4 mr-2" /> {exporting ? 'Membuat PDF...' : 'Export PDF'}
 </Button>
 <Button variant="default" onClick={handleExportExcel} disabled={exporting}>
 <Download className={`w-4 h-4 mr-2 ${exporting ? 'animate-bounce' : ''}`} /> {exporting ? 'Mengunduh...' : 'Export Excel'}
 </Button>
 </>
 )}
 </div>
 </div>

 {/* Filter Bar */}
 {showFilter && (
 <Card className="bg-slate-50/50 border-dashed print:hidden">
 <CardContent className="p-4 grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
 <div className="space-y-1.5">
 <label className="text-xs font-semibold" htmlFor="filter-tahun-laporan">Tahun</label>
 <select id="filter-tahun-laporan" className="w-full rounded-md border-input bg-background text-sm h-9" value={year} onChange={e => setYear(e.target.value)}>
 {workload === 'unassessed' && <option value="">Semua tahun</option>}
 {years.map(y => <option key={y.value} value={y.value}>{y.label}</option>)}
 </select>
 </div>
 <div className="space-y-1.5">
 <label className="text-xs font-semibold">Bulan</label>
 <select className="w-full rounded-md border-input bg-background text-sm h-9" value={month} onChange={e => setMonth(e.target.value)}>
 {months.map(m => <option key={m.value} value={m.value}>{m.label}</option>)}
 </select>
 </div>
 <div className="space-y-1.5 md:col-span-2">
 <label className="text-xs font-semibold">Rentang Tanggal (Opsional)</label>
 <DateRangePicker dateFrom={dateFrom} dateTo={dateTo} onDateFromChange={setDateFrom} onDateToChange={setDateTo} />
 </div>
 <div className="space-y-1.5">
 <label className="text-xs font-semibold">Unit Layanan</label>
 <select className="w-full rounded-md border-input bg-background text-sm h-9" value={unitId} onChange={e => {
 const val = e.target.value;
 setUnitId(val);
 setSubUnitId('');
 setSubUnits([]);
 if (val) fetch(`/api/sub-units/${val}`).then(r => r.json()).then(setSubUnits);
 }}>
 <option value="">Semua Unit</option>
 {units?.map((u: any) => <option key={u.id} value={u.id}>{u.nama_unit}</option>)}
 </select>
 </div>
 <div className="space-y-1.5">
 <label className="text-xs font-semibold">Sub Unit</label>
 <select className="w-full rounded-md border-input bg-background text-sm h-9" value={subUnitId} onChange={e => setSubUnitId(e.target.value)}>
 <option value="">Semua Sub Unit</option>
 {subUnits?.map((su: any) => <option key={su.id} value={su.id}>{su.nama_layanan}</option>)}
 </select>
 </div>
 <div className="space-y-1.5">
 <label className="text-xs font-semibold">Status Tiket</label>
 <select className="w-full rounded-md border-input bg-background text-sm h-9" value={status} onChange={e => setStatus(e.target.value)}>
 <option value="">Semua Status</option>
 {STATUS_LIST.map(s => <option key={s.value} value={s.value}>{s.label}</option>)}
 </select>
 </div>
 {canAssess && (
 <div className="space-y-1.5">
 <label className="text-xs font-semibold" htmlFor="filter-penilaian-beban">Penilaian beban</label>
 <select
 id="filter-penilaian-beban"
 className="w-full rounded-md border-input bg-background text-sm h-11"
 value={workload}
 onChange={e => applyWorkloadFilter(e.target.value)}
 >
 <option value="">Semua tiket</option>
 <option value="unassessed">Belum dinilai</option>
 </select>
 </div>
 )}
 <div className="space-y-1.5">
 <label className="text-xs font-semibold">Divisi Pengaju</label>
 <select className="w-full rounded-md border-input bg-background text-sm h-9" value={divisiId} onChange={e => setDivisiId(e.target.value)}>
 <option value="">Semua Divisi</option>
 {divisiList?.map((d: any) => <option key={d.id} value={d.id}>{d.nama_divisi}</option>)}
 </select>
 </div>
 <div className="col-span-full flex justify-end mt-2 gap-2">
 <div className="relative flex-1 max-w-sm mr-auto">
 <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
 <input
 type="text"
 value={search}
 onChange={e => setSearch(e.target.value)}
 onKeyDown={e => e.key === 'Enter' && applyFilter()}
 placeholder="Cari ID tiket, pengaju, layanan..."
 className="w-full rounded-md border border-input bg-background pl-9 pr-8 text-sm h-9"
 />
 {search && (
 <button onClick={() => setSearch('')} className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
 <X className="h-4 w-4" />
 </button>
 )}
 </div>
 <Button variant="outline" size="sm" onClick={() => router.get(route('admin.laporan.tiket'))}>Reset</Button>
 <Button variant="default" size="sm" onClick={applyFilter}>
 <Search className="w-4 h-4 mr-2" /> Terapkan Filter
 </Button>
 </div>
 </CardContent>
 </Card>
 )}

 {/* Status Cards */}
 <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
 {Object.entries(STATUS_META).map(([key, meta]) => {
 const Icon = meta.icon;

 return (
 <div key={key} className={`group relative overflow-hidden rounded-xl bg-gradient-to-br ${meta.bg} shadow-md transition-all duration-300 hover:shadow-lg hover:-translate-y-0.5 print:break-inside-avoid print:shadow-none`}>
 <div className="absolute -top-6 -right-6 h-24 w-24 rounded-full bg-white/20 blur-xl pointer-events-none transition-transform duration-500 group-hover:scale-150" />
 <div className="absolute -bottom-4 -left-4 h-20 w-20 rounded-full bg-white/5 blur-lg pointer-events-none" />
 <div className="relative p-5 text-white">
 <div className="flex items-start justify-between">
 <span className="text-sm font-medium text-white/80">{meta.label}</span>
 <Icon className={`h-6 w-6 text-white opacity-70 transition-all duration-300 ${meta.anim} print:hidden`} />
 </div>
 <p className="mt-3 text-3xl font-bold">{statusCounts?.[key] ?? 0}</p>
 </div>
 </div>
 );
 })}
 </div>

 <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
 {/* SLA Stats Card */}
 <Card className="flex flex-col print:break-inside-avoid">
 <CardHeader className="pb-2">
 <CardTitle className="text-base">Kepatuhan SLA</CardTitle>
 </CardHeader>
 <CardContent className="flex-1 flex flex-col justify-center">
 <div className="grid grid-cols-2 gap-4 text-center mb-6">
 <div className="space-y-1">
 <p className="text-3xl font-bold text-green-500">{slaStats?.responseCompliance ?? 0}%</p>
 <p className="text-xs text-muted-foreground uppercase tracking-wider font-semibold">SLA Respon</p>
 </div>
 <div className="space-y-1">
 <p className="text-3xl font-bold text-primary">{slaStats?.resolutionCompliance ?? 0}%</p>
 <p className="text-xs text-muted-foreground uppercase tracking-wider font-semibold">SLA Penyelesaian</p>
 </div>
 </div>
 <div className="rounded-lg border bg-slate-50 p-3 text-center ">
 <p className="text-sm font-medium">Total Pelanggaran SLA</p>
 <p className="text-2xl font-bold text-red-500 mt-1">{slaStats?.totalBreach ?? 0} <span className="text-sm text-muted-foreground font-normal">dari {slaStats?.totalAll ?? 0} tiket</span></p>
 </div>
 </CardContent>
 </Card>

 {/* Tren Bulanan */}
 <Card className="lg:col-span-2 print:break-inside-avoid">
 <CardHeader className="pb-2">
 <CardTitle className="text-base">Tren Tiket Berdasarkan Bulan</CardTitle>
 </CardHeader>
 <CardContent>
 {monthlyTrend?.length > 0 ? (
 <LazyECharts option={{
 tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
 grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
 xAxis: { type: 'category', data: monthlyTrend.map((d: any) => d.bulan) },
 yAxis: { type: 'value' },
 series: [{
 name: 'Total Tiket',
 type: 'line',
 smooth: true,
 areaStyle: {
 color: {
 type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
 colorStops: [{ offset: 0, color: 'rgba(59, 130, 246, 0.5)' }, { offset: 1, color: 'rgba(59, 130, 246, 0.05)' }]
 }
 },
 itemStyle: { color: '#3b82f6' },
 data: monthlyTrend.map((d: any) => d.total)
 }]
 }} height={280} />
 ) : (
 <p className="text-sm text-muted-foreground text-center py-8">Belum ada data tren.</p>
 )}
 </CardContent>
 </Card>
 </div>

 <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
 {/* SLA Pie Chart */}
 <Card className="print:break-inside-avoid">
 <CardHeader className="pb-2">
 <CardTitle className="text-base">Distribusi Kepatuhan SLA</CardTitle>
 </CardHeader>
 <CardContent>
 {slaPieChartData?.length > 0 && slaStats?.totalAll > 0 ? (
 <LazyECharts option={{
 tooltip: { trigger: 'item', formatter: '{b} : {c} ({d}%)' },
 legend: { bottom: 0 },
 series: [{
 type: 'pie',
 radius: ['40%', '70%'],
 center: ['50%', '45%'],
 itemStyle: { borderRadius: 5, borderColor: '#fff', borderWidth: 2 },
 data: [
 { value: slaPieChartData.find((d: any) => d.name === 'Dalam SLA')?.value || 0, name: 'Dalam SLA', itemStyle: { color: '#22c55e' } },
 { value: slaPieChartData.find((d: any) => d.name === 'Pelanggaran Respon')?.value || 0, name: 'Pelanggaran Respon', itemStyle: { color: '#f59e0b' } },
 { value: slaPieChartData.find((d: any) => d.name === 'Pelanggaran Penyelesaian')?.value || 0, name: 'Pelanggaran Penyelesaian', itemStyle: { color: '#ef4444' } },
 ]
 }]
 }} height={280} />
 ) : (
 <p className="text-sm text-muted-foreground text-center py-8">Belum ada data SLA.</p>
 )}
 </CardContent>
 </Card>

 {/* Top Divisi / Layanan (Diganti Tabel) */}
 </div>

 <div className="grid gap-4 grid-cols-1">
 <Card className="print:break-inside-avoid shadow-sm hover:shadow-md transition-shadow">
 <CardHeader className="pb-3 border-b border-slate-100 ">
 <CardTitle className="text-base font-bold flex items-center gap-2">
 <span className="h-4 w-1 rounded-full bg-blue-500"></span>
 Tiket Berdasarkan Jenis Layanan
 </CardTitle>
 </CardHeader>
 <CardContent className="p-0">
 {/* Custom scrollbar via tailwind utilities + smooth scrolling */}
 <div className="overflow-x-auto max-h-[350px] relative scrollbar-thin scrollbar-thumb-slate-200 scrollbar-track-transparent hover:scrollbar-thumb-slate-300 transition-all">
 <table className="w-full text-sm text-left">
 <thead className="sticky top-0 bg-slate-50/95 backdrop-blur-sm z-10">
 <tr>
 <th className="py-3 px-4 font-semibold text-slate-600 ">Jenis Layanan</th>
 <th className="py-3 px-4 font-semibold text-right text-slate-600 w-1/3 md:w-1/4">Distribusi Tiket</th>
 </tr>
 </thead>
 <tbody className="divide-y divide-slate-100 ">
 {ticketsByLayanan?.map((t: any, idx: number) => {
 const percent = (t.count / maxLayanan) * 100;

 return (
 <tr key={idx} className="hover:bg-slate-50/50 transition-colors group">
 <td className="py-3 px-4 font-medium">{t.layanan}</td>
 <td className="py-3 px-4">
 <div className="flex items-center justify-end gap-3">
 <div className="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden max-w-[200px]">
 <div 
 className="h-full bg-blue-500 rounded-full group-hover:bg-blue-400 transition-all duration-500 ease-out" 
 style={{ width:`${percent}%`}}
 ></div>
 </div>
 <span className="font-bold min-w-[2rem] text-right">{t.count}</span>
 </div>
 </td>
 </tr>
 );
 })}
 {(!ticketsByLayanan || ticketsByLayanan.length === 0) && (
 <tr>
 <td colSpan={2} className="py-8 text-center text-muted-foreground">Tidak ada data layanan.</td>
 </tr>
 )}
 </tbody>
 </table>
 </div>
 </CardContent>
 </Card>
 </div>
 
 {/* Distribusi per Unit */}
 <Card className="print:break-inside-avoid">
 <CardHeader className="pb-2">
 <CardTitle className="text-base">Distribusi Tiket Berdasarkan Unit Layanan</CardTitle>
 </CardHeader>
 <CardContent>
 {ticketsByUnit?.length > 0 ? (
 <LazyECharts option={{
 tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
 grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
 xAxis: { type: 'category', data: ticketsByUnit.map((d: any) => d.name), axisLabel: { interval: 0, rotate: 15 } },
 yAxis: { type: 'value' },
 series: [{
 name: 'Total Tiket',
 type: 'bar',
 itemStyle: { color: '#0ea5e9', borderRadius: [4, 4, 0, 0] },
 data: ticketsByUnit.map((d: any) => d.value)
 }]
 }} height={300} />
 ) : (
 <p className="text-sm text-muted-foreground text-center py-8">Belum ada data Distribusi Unit.</p>
 )}
 </CardContent>
 </Card>

 {/* Rincian Tiket Table */}
 <div className="mt-8 space-y-4 print:break-before-page">
 <h2 id="rincian-tiket" tabIndex={-1} className="text-xl font-bold tracking-tight focus:outline-2 focus:outline-offset-2 focus:outline-ring">Rincian Tiket</h2>
 {workload === 'unassessed' && (
 <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 print:hidden">
 <p className="text-sm text-muted-foreground">
 Tiket selesai yang belum dinilai{!filters.year && !filters.date_from && !filters.date_to && !filters.month ? ' dari semua tahun. Ringkasan di atas tetap untuk tahun berjalan.' : '.'}
 </p>
 <Link href={route('admin.laporan.tiket')} className="inline-flex min-h-11 items-center rounded-md text-sm font-medium text-foreground underline underline-offset-4">
 Lihat semua tiket
 </Link>
 </div>
 )}
 <div className="overflow-x-auto rounded-md border">
 <DataTable
 columns={columns}
 data={tickets.data || []}
 keyExtractor={(t: any) => t.id}
 emptyMessage={canAssess && workload === 'unassessed' ? 'Tidak ada tiket selesai yang belum dinilai untuk filter ini.' : undefined}
 />
 </div>
 <div className="print:hidden">
 <Pagination links={tickets.links} />
 </div>
 </div>
 </div>

 {/* Print Styles */}
 <Dialog open={selectedTicket !== null} onOpenChange={(open) => { if (!open) closeAssessment(); }}>
 <DialogContent
 className="max-h-[calc(100dvh-2rem)] overflow-y-auto"
 showCloseButton={false}
 finalFocus={() => (openerRef.current?.isConnected ? openerRef.current : document.getElementById('rincian-tiket'))}
 >
 <DialogHeader>
 <DialogTitle>Penilaian Beban Tiket</DialogTitle>
 <DialogDescription>
 #TKT-{formatTicketId(selectedTicket?.id)} · {selectedTicket?.assigned_admin?.name || selectedTicket?.assigned_admin?.username || 'Belum ditugaskan'}
 </DialogDescription>
 </DialogHeader>
 {selectedTicket?.workload_assessment && (
 <p className="text-xs text-muted-foreground">
 Dinilai oleh {selectedTicket.workload_assessment.assessor?.name || selectedTicket.workload_assessment.assessor?.username || 'Akun penilai tidak tersedia'} · {new Date(selectedTicket.workload_assessment.updated_at).toLocaleString('id-ID')}
 </p>
 )}
 <form onSubmit={submitAssessment} className="space-y-4">
 <div className="space-y-1.5">
 <Label htmlFor="workload-score">Tingkat beban</Label>
 <select
 id="workload-score"
 required
 className="w-full rounded-md border-input bg-background text-sm h-11"
 value={data.score}
 onChange={e => setData('score', e.target.value)}
 aria-invalid={!!errors.score}
 aria-describedby={errors.score ? 'workload-score-error' : undefined}
 >
 <option value="">Pilih tingkat beban</option>
 {Object.entries(WORKLOAD_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
 </select>
 {errors.score && <p id="workload-score-error" className="text-xs text-destructive">{errors.score}</p>}
 </div>
 <fieldset className="min-w-0 space-y-2">
 <legend className="text-sm font-medium">Waktu pengerjaan (opsional)</legend>
 <div className="grid grid-cols-2 gap-3">
 <div className="space-y-1.5">
 <Label htmlFor="workload-effort-hours">Jam</Label>
 <Input
 id="workload-effort-hours"
 type="number"
 inputMode="numeric"
 min={0}
 max={9999}
 step={1}
 className="h-11"
 value={data.effort_hours}
 onChange={e => setData('effort_hours', e.target.value)}
 aria-invalid={!!errors.effort_hours}
 aria-describedby={effortDescriptionId}
 />
 </div>
 <div className="space-y-1.5">
 <Label htmlFor="workload-effort-minutes">Menit</Label>
 <Input
 id="workload-effort-minutes"
 type="number"
 inputMode="numeric"
 min={0}
 max={59}
 step={1}
 className="h-11"
 value={data.effort_minutes}
 onChange={e => setData('effort_minutes', e.target.value)}
 aria-invalid={!!errors.effort_hours}
 aria-describedby={effortDescriptionId}
 />
 </div>
 </div>
 {errors.effort_hours ? (
 <p id="workload-effort-error" role="alert" className="text-xs text-destructive">{errors.effort_hours}</p>
 ) : (
 <p id="workload-effort-help" className="text-xs text-muted-foreground">Isi waktu kerja saja, tanpa waktu menunggu.</p>
 )}
 </fieldset>
 <div className="space-y-1.5">
 <Label htmlFor="workload-note">Catatan (opsional)</Label>
 <Textarea
 id="workload-note"
 maxLength={1000}
 value={data.note}
 onChange={e => setData('note', e.target.value)}
 aria-invalid={!!errors.note}
 aria-describedby={errors.note ? 'workload-note-error' : undefined}
 />
 {errors.note && <p id="workload-note-error" className="text-xs text-destructive">{errors.note}</p>}
 </div>
 {generalError && <p role="alert" className="text-sm text-destructive">{generalError}</p>}
 <DialogFooter>
 <Button type="button" variant="outline" className="h-11" disabled={processing} onClick={closeAssessment}>Batal</Button>
 <Button type="submit" className="h-11" disabled={processing}>{processing ? 'Menyimpan…' : 'Simpan penilaian'}</Button>
 </DialogFooter>
 </form>
 </DialogContent>
 </Dialog>

 <style>{`
 @media print {
 @page { size: landscape; margin: 10mm; }
 body { -webkit-print-color-adjust: exact; print-color-adjust: exact; background-color: white !important; }
 .print\\:hidden { display: none !important; }
 .print\\:break-inside-avoid { break-inside: avoid; }
 .print\\:break-before-page { break-before: page; }
 .print\\:shadow-none { box-shadow: none !important; border: 1px solid #e2e8f0; }
 main { padding: 0 !important; margin: 0 !important; background: transparent !important; }
 header, nav, footer { display: none !important; }
 .card { border: 1px solid #e2e8f0 !important; box-shadow: none !important; }
 }
`}</style>
 </AdminLayout>
 );
}
