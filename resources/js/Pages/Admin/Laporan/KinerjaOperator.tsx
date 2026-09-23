import React, { useState, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/Components/ui/dialog';
import LazyECharts from '@/Components/Charts/LazyECharts';
import {
  Users,
  Clock,
  CheckCircle2,
  Star,
  Printer,
  Download,
  Filter,
  Search,
  MessageSquare,
  AlertTriangle,
  Flame,
  ShieldCheck,
  Building,
  UserCheck,
} from 'lucide-react';
import { formatTicketId } from '@/lib/utils';

interface OperatorStat {
  id: number;
  name: string;
  username: string;
  email: string;
  no_wa: string | null;
  roles: string[];
  units: string[];
  sub_units: string[];
  total_tickets: number;
  active_tickets: number;
  solved_tickets: number;
  rejected_tickets: number;
  open_tickets: number;
  total_resolved: number;
  resolution_breaches: number;
  response_breaches: number;
  total_breaches: number;
  resolution_compliance: number;
  avg_rating: number | null;
  total_reviews: number;
}

interface ReviewItem {
  id: number;
  rating: number;
  komentar: string | null;
  created_at: string;
  ticket_id: string;
  ticket_raw_id: number;
  ticket_title: string;
  unit_nama: string;
  layanan_nama: string;
  user_name: string;
  divisi_nama: string;
}

interface Props {
  filters: {
    year?: string;
    month?: string;
    date_from?: string;
    date_to?: string;
    unit_id?: string;
    sub_unit_id?: string;
    search?: string;
  };
  units: { id: number; nama_unit: string }[];
  subUnits: { id: number; unit_id: number; nama_layanan: string }[];
  operators: OperatorStat[];
  summary: {
    totalOperators: number;
    totalAssigned: number;
    totalActive: number;
    totalSolved: number;
    avgActivePerOperator: number;
    overallAvgRating: number;
  };
  workloadChartData: { name: string; active: number; solved: number }[];
  performanceChartData: { name: string; sla: number; rating: number }[];
}

export default function KinerjaOperator({
  filters,
  units,
  subUnits: initialSubUnits,
  operators,
  summary,
  workloadChartData,
  performanceChartData,
}: Props) {
  const [year, setYear] = useState(filters?.year || new Date().getFullYear().toString());
  const [month, setMonth] = useState(filters?.month || '');
  const [dateFrom, setDateFrom] = useState(filters?.date_from || '');
  const [dateTo, setDateTo] = useState(filters?.date_to || '');
  const [unitId, setUnitId] = useState(filters?.unit_id || '');
  const [subUnitId, setSubUnitId] = useState(filters?.sub_unit_id || '');
  const [search, setSearch] = useState(filters?.search || '');
  const [subUnits, setSubUnits] = useState(initialSubUnits || []);
  const [showFilter, setShowFilter] = useState(false);

  // Modal Review States
  const [selectedOperator, setSelectedOperator] = useState<OperatorStat | null>(null);
  const [reviews, setReviews] = useState<ReviewItem[]>([]);
  const [loadingReviews, setLoadingReviews] = useState(false);
  const [modalOpen, setModalOpen] = useState(false);

  useEffect(() => {
    if (unitId && (!initialSubUnits || initialSubUnits.length === 0)) {
      fetch(`/api/sub-units/${unitId}`)
        .then((r) => r.json())
        .then(setSubUnits);
    } else if (!unitId) {
      setSubUnits([]);
      setSubUnitId('');
    }
  }, [unitId, initialSubUnits]);

  const applyFilter = () => {
    const params: Record<string, string> = {};
    if (year) params.year = year;
    if (month) params.month = month;
    if (dateFrom) params.date_from = dateFrom;
    if (dateTo) params.date_to = dateTo;
    if (unitId) params.unit_id = unitId;
    if (subUnitId) params.sub_unit_id = subUnitId;
    if (search) params.search = search;

    router.get(route('admin.laporan.operator'), params, { preserveState: true });
  };

  const handleReset = () => {
    router.get(route('admin.laporan.operator'));
  };

  const handlePrint = () => {
    window.print();
  };

  const handleExportExcel = () => {
    const params = new URLSearchParams();
    if (year) params.set('year', year);
    if (month) params.set('month', month);
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    if (unitId) params.set('unit_id', unitId);
    if (subUnitId) params.set('sub_unit_id', subUnitId);
    if (search) params.set('search', search);

    window.location.href = route('admin.laporan.operator.export') + '?' + params.toString();
  };

  const handleOpenReviews = async (operator: OperatorStat) => {
    setSelectedOperator(operator);
    setModalOpen(true);
    setLoadingReviews(true);

    try {
      const params = new URLSearchParams();
      if (year) params.set('year', year);
      if (month) params.set('month', month);
      if (dateFrom) params.set('date_from', dateFrom);
      if (dateTo) params.set('date_to', dateTo);
      if (unitId) params.set('unit_id', unitId);
      if (subUnitId) params.set('sub_unit_id', subUnitId);

      const res = await fetch(
        route('admin.laporan.operator.ulasan', operator.id) + '?' + params.toString()
      );
      const data = await res.json();
      setReviews(data.reviews || []);
    } catch {
      setReviews([]);
    } finally {
      setLoadingReviews(false);
    }
  };

  const months = [
    { value: '', label: 'Semua Bulan' },
    ...Array.from({ length: 12 }, (_, i) => ({
      value: String(i + 1),
      label: new Date(0, i).toLocaleString('id', { month: 'long' }),
    })),
  ];

  const years = Array.from({ length: 5 }, (_, i) => ({
    value: String(new Date().getFullYear() - i),
    label: String(new Date().getFullYear() - i),
  }));

  return (
    <AdminLayout title="Laporan Kinerja Operator">
      <Head title="Laporan Kinerja Operator" />

      <div className="space-y-6">
        {/* Header & Actions */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 print:hidden">
          <div>
            <div className="flex items-center gap-2">
              <span className="p-2 bg-sky-500/10 text-sky-600 rounded-lg">
                <UserCheck className="w-5 h-5" />
              </span>
              <h1 className="text-2xl font-bold tracking-tight">Laporan Beban & Kinerja Operator</h1>
            </div>
            <p className="text-sm text-muted-foreground mt-1">
              Pemantauan beban tiket berjalan, tingkat penyelesaian, kepatuhan SLA, serta kepuasan pengguna (CSAT) per operator.
            </p>
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            <Button variant="outline" onClick={() => setShowFilter(!showFilter)}>
              <Filter className="w-4 h-4 mr-2" />
              {showFilter ? 'Sembunyikan Filter' : 'Filter Laporan'}
            </Button>
            <Button variant="outline" onClick={handlePrint}>
              <Printer className="w-4 h-4 mr-2" /> Cetak / PDF
            </Button>
            <Button variant="default" onClick={handleExportExcel} className="bg-emerald-600 hover:bg-emerald-700 text-white">
              <Download className="w-4 h-4 mr-2" /> Export CSV / Excel
            </Button>
          </div>
        </div>

        {/* Filter Panel */}
        {showFilter && (
          <Card className="bg-slate-50/70 border-dashed print:hidden">
            <CardContent className="p-4 grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-slate-700">Tahun</label>
                <select
                  className="w-full rounded-md border-input bg-background text-sm h-9 px-3"
                  value={year}
                  onChange={(e) => setYear(e.target.value)}
                >
                  {years.map((y) => (
                    <option key={y.value} value={y.value}>
                      {y.label}
                    </option>
                  ))}
                </select>
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-slate-700">Bulan</label>
                <select
                  className="w-full rounded-md border-input bg-background text-sm h-9 px-3"
                  value={month}
                  onChange={(e) => setMonth(e.target.value)}
                >
                  {months.map((m) => (
                    <option key={m.value} value={m.value}>
                      {m.label}
                    </option>
                  ))}
                </select>
              </div>

              <div className="space-y-1.5 md:col-span-2">
                <label className="text-xs font-semibold text-slate-700">Rentang Tanggal (Opsional)</label>
                <div className="flex items-center gap-2">
                  <input
                    type="date"
                    className="w-full rounded-md border-input bg-background text-sm h-9 px-3"
                    value={dateFrom}
                    onChange={(e) => setDateFrom(e.target.value)}
                  />
                  <span className="text-slate-400">-</span>
                  <input
                    type="date"
                    className="w-full rounded-md border-input bg-background text-sm h-9 px-3"
                    value={dateTo}
                    onChange={(e) => setDateTo(e.target.value)}
                  />
                </div>
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-slate-700">Unit Layanan</label>
                <select
                  className="w-full rounded-md border-input bg-background text-sm h-9 px-3"
                  value={unitId}
                  onChange={(e) => setUnitId(e.target.value)}
                >
                  <option value="">Semua Unit</option>
                  {units?.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.nama_unit}
                    </option>
                  ))}
                </select>
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-slate-700">Sub Unit Layanan</label>
                <select
                  className="w-full rounded-md border-input bg-background text-sm h-9 px-3"
                  value={subUnitId}
                  onChange={(e) => setSubUnitId(e.target.value)}
                  disabled={!unitId}
                >
                  <option value="">Semua Sub Unit</option>
                  {subUnits?.map((su) => (
                    <option key={su.id} value={su.id}>
                      {su.nama_layanan}
                    </option>
                  ))}
                </select>
              </div>

              <div className="space-y-1.5 md:col-span-2">
                <label className="text-xs font-semibold text-slate-700">Cari Operator</label>
                <input
                  type="text"
                  placeholder="Nama, username, atau email operator..."
                  className="w-full rounded-md border-input bg-background text-sm h-9 px-3"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && applyFilter()}
                />
              </div>

              <div className="col-span-full flex justify-end gap-2 pt-2 border-t">
                <Button variant="outline" size="sm" onClick={handleReset}>
                  Reset
                </Button>
                <Button variant="default" size="sm" onClick={applyFilter}>
                  <Search className="w-4 h-4 mr-2" /> Terapkan Filter
                </Button>
              </div>
            </CardContent>
          </Card>
        )}

        {/* KPI Summary Cards */}
        <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div className="relative overflow-hidden rounded-xl bg-gradient-to-br from-blue-600 to-indigo-700 p-5 text-white shadow-md">
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-white/80">Total Operator</span>
              <Users className="w-6 h-6 text-white/70" />
            </div>
            <p className="mt-3 text-3xl font-bold">{summary.totalOperators}</p>
            <p className="text-xs text-white/70 mt-1">Operator menangani tiket</p>
          </div>

          <div className="relative overflow-hidden rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 p-5 text-white shadow-md">
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-white/80">Tiket Aktif Berjalan</span>
              <Flame className="w-6 h-6 text-white/70" />
            </div>
            <p className="mt-3 text-3xl font-bold">{summary.totalActive}</p>
            <p className="text-xs text-white/70 mt-1">
              Rata-rata {summary.avgActivePerOperator} tiket / operator
            </p>
          </div>

          <div className="relative overflow-hidden rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 p-5 text-white shadow-md">
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-white/80">Tiket Selesai</span>
              <CheckCircle2 className="w-6 h-6 text-white/70" />
            </div>
            <p className="mt-3 text-3xl font-bold">{summary.totalSolved}</p>
            <p className="text-xs text-white/70 mt-1">Dari {summary.totalAssigned} total tiket ditugaskan</p>
          </div>

          <div className="relative overflow-hidden rounded-xl bg-gradient-to-br from-purple-600 to-violet-700 p-5 text-white shadow-md">
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-white/80">Rata-rata Rating CSAT</span>
              <Star className="w-6 h-6 text-amber-300 fill-amber-300" />
            </div>
            <p className="mt-3 text-3xl font-bold">
              {summary.overallAvgRating > 0 ? summary.overallAvgRating : '-'}{' '}
              <span className="text-base font-normal text-white/80">/ 5.0</span>
            </p>
            <p className="text-xs text-white/70 mt-1">Kepuasan seluruh operator</p>
          </div>
        </div>

        {/* Charts Section */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 print:break-inside-avoid">
          {/* Chart 1: Beban Kerja (Aktif vs Selesai) */}
          <Card className="shadow-sm">
            <CardHeader className="pb-2">
              <CardTitle className="text-base font-semibold flex items-center justify-between">
                <span>Perbandingan Beban Tiket Operator</span>
                <span className="text-xs font-normal text-muted-foreground">Top 10 Operator</span>
              </CardTitle>
            </CardHeader>
            <CardContent>
              {workloadChartData && workloadChartData.length > 0 ? (
                <LazyECharts
                  height={280}
                  option={{
                    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                    legend: { data: ['Tiket Aktif (Menumpuk)', 'Tiket Selesai'], bottom: 0 },
                    grid: { left: '3%', right: '4%', bottom: '12%', top: '5%', containLabel: true },
                    xAxis: {
                      type: 'category',
                      data: workloadChartData.map((d) => d.name),
                      axisLabel: { interval: 0, rotate: 20, width: 90, overflow: 'truncate' },
                    },
                    yAxis: { type: 'value', name: 'Tiket' },
                    series: [
                      {
                        name: 'Tiket Aktif (Menumpuk)',
                        type: 'bar',
                        stack: 'total',
                        itemStyle: { color: '#f59e0b' },
                        data: workloadChartData.map((d) => d.active),
                      },
                      {
                        name: 'Tiket Selesai',
                        type: 'bar',
                        stack: 'total',
                        itemStyle: { color: '#10b981', borderRadius: [4, 4, 0, 0] },
                        data: workloadChartData.map((d) => d.solved),
                      },
                    ],
                  }}
                />
              ) : (
                <div className="h-[280px] flex items-center justify-center text-sm text-muted-foreground">
                  Belum ada data pengerjaan tiket pada periode ini.
                </div>
              )}
            </CardContent>
          </Card>

          {/* Chart 2: Kepatuhan SLA & Rating CSAT */}
          <Card className="shadow-sm">
            <CardHeader className="pb-2">
              <CardTitle className="text-base font-semibold flex items-center justify-between">
                <span>Kepatuhan SLA & Skor CSAT Operator</span>
                <span className="text-xs font-normal text-muted-foreground">Top 10 Operator</span>
              </CardTitle>
            </CardHeader>
            <CardContent>
              {performanceChartData && performanceChartData.length > 0 ? (
                <LazyECharts
                  height={280}
                  option={{
                    tooltip: { trigger: 'axis' },
                    legend: { data: ['Kepatuhan SLA (%)', 'Skor CSAT (1-5)'], bottom: 0 },
                    grid: { left: '3%', right: '4%', bottom: '12%', top: '5%', containLabel: true },
                    xAxis: {
                      type: 'category',
                      data: performanceChartData.map((d) => d.name),
                      axisLabel: { interval: 0, rotate: 20, width: 90, overflow: 'truncate' },
                    },
                    yAxis: [
                      { type: 'value', name: 'SLA (%)', max: 100, min: 0 },
                      { type: 'value', name: 'Rating', max: 5, min: 0 },
                    ],
                    series: [
                      {
                        name: 'Kepatuhan SLA (%)',
                        type: 'bar',
                        itemStyle: { color: '#3b82f6', borderRadius: [4, 4, 0, 0] },
                        data: performanceChartData.map((d) => d.sla),
                      },
                      {
                        name: 'Skor CSAT (1-5)',
                        type: 'line',
                        yAxisIndex: 1,
                        smooth: true,
                        itemStyle: { color: '#eab308' },
                        data: performanceChartData.map((d) => d.rating),
                      },
                    ],
                  }}
                />
              ) : (
                <div className="h-[280px] flex items-center justify-center text-sm text-muted-foreground">
                  Belum ada data evaluasi performa pada periode ini.
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Tabel Kinerja & Beban Operator */}
        <Card className="shadow-sm print:shadow-none print:border-none">
          <CardHeader className="pb-3 border-b flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
              <CardTitle className="text-lg font-bold flex items-center gap-2">
                <span className="h-4 w-1 rounded-full bg-sky-500"></span>
                Rincian Kinerja & Beban Kerja per Operator
              </CardTitle>
              <p className="text-xs text-muted-foreground mt-0.5">
                Urutan teratas menampilkan operator dengan jumlah tiket aktif tertinggi.
              </p>
            </div>
            <div className="text-xs text-slate-500">
              Total <strong>{operators.length}</strong> operator ditemukan
            </div>
          </CardHeader>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <table className="w-full text-sm text-left">
                <thead className="bg-slate-50 text-slate-700 font-semibold text-xs border-b">
                  <tr>
                    <th className="py-3 px-4">Operator</th>
                    <th className="py-3 px-4">Unit Layanan</th>
                    <th className="py-3 px-3 text-center">Tiket Aktif</th>
                    <th className="py-3 px-3 text-center">Selesai</th>
                    <th className="py-3 px-3 text-center">Total Tiket</th>
                    <th className="py-3 px-4 text-center">Kepatuhan SLA</th>
                    <th className="py-3 px-4 text-center">CSAT (Rating)</th>
                    <th className="py-3 px-4 text-center print:hidden">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {operators.map((op) => {
                    const initials = op.name
                      ? op.name
                          .split(' ')
                          .map((n) => n[0])
                          .slice(0, 2)
                          .join('')
                          .toUpperCase()
                      : op.username.substring(0, 2).toUpperCase();

                    const isHighLoad = op.active_tickets >= 5;

                    return (
                      <tr key={op.id} className="hover:bg-slate-50/60 transition-colors">
                        {/* Operator Info */}
                        <td className="py-3.5 px-4">
                          <div className="flex items-center gap-3">
                            <div className="w-9 h-9 rounded-full bg-sky-100 text-sky-700 font-bold flex items-center justify-center text-xs shrink-0">
                              {initials}
                            </div>
                            <div>
                              <p className="font-semibold text-slate-900 leading-snug">{op.name}</p>
                              <p className="text-xs text-slate-500 font-mono">@{op.username}</p>
                            </div>
                          </div>
                        </td>

                        {/* Units */}
                        <td className="py-3.5 px-4">
                          {op.units && op.units.length > 0 ? (
                            <div className="flex flex-wrap gap-1 max-w-[200px]">
                              {op.units.map((u, idx) => (
                                <Badge key={idx} variant="secondary" className="text-[11px] font-normal py-0">
                                  {u}
                                </Badge>
                              ))}
                            </div>
                          ) : (
                            <span className="text-xs text-slate-400">-</span>
                          )}
                        </td>

                        {/* Active Tickets (Workload highlight) */}
                        <td className="py-3.5 px-3 text-center">
                          <span
                            className={`inline-flex items-center justify-center px-2.5 py-1 rounded-full text-xs font-bold ${
                              isHighLoad
                                ? 'bg-amber-100 text-amber-800 border border-amber-200 animate-pulse'
                                : op.active_tickets > 0
                                ? 'bg-blue-50 text-blue-700 border border-blue-200'
                                : 'bg-slate-100 text-slate-600'
                            }`}
                          >
                            {op.active_tickets}
                          </span>
                        </td>

                        {/* Solved Tickets */}
                        <td className="py-3.5 px-3 text-center">
                          <span className="inline-flex items-center justify-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            {op.solved_tickets}
                          </span>
                        </td>

                        {/* Total Tickets */}
                        <td className="py-3.5 px-3 text-center font-semibold text-slate-700">
                          {op.total_tickets}
                        </td>

                        {/* SLA Compliance */}
                        <td className="py-3.5 px-4 text-center">
                          <div className="flex flex-col items-center">
                            <span
                              className={`text-xs font-bold ${
                                op.resolution_compliance >= 90
                                  ? 'text-emerald-600'
                                  : op.resolution_compliance >= 70
                                  ? 'text-amber-600'
                                  : 'text-rose-600'
                              }`}
                            >
                              {op.resolution_compliance}%
                            </span>
                            {op.total_breaches > 0 ? (
                              <span className="text-[11px] text-rose-500 font-medium flex items-center gap-0.5 mt-0.5">
                                <AlertTriangle className="w-3 h-3" /> {op.total_breaches} telat
                              </span>
                            ) : (
                              <span className="text-[11px] text-emerald-500 font-medium">0 breach</span>
                            )}
                          </div>
                        </td>

                        {/* CSAT Rating */}
                        <td className="py-3.5 px-4 text-center">
                          {op.avg_rating !== null ? (
                            <div className="flex flex-col items-center">
                              <div className="flex items-center gap-1 font-bold text-amber-600 text-xs">
                                <Star className="w-3.5 h-3.5 fill-amber-400 text-amber-400" />
                                <span>{op.avg_rating}</span>
                              </div>
                              <span className="text-[11px] text-slate-400">({op.total_reviews} ulasan)</span>
                            </div>
                          ) : (
                            <span className="text-xs text-slate-400">Belum ada rating</span>
                          )}
                        </td>

                        {/* Actions */}
                        <td className="py-3.5 px-4 text-center print:hidden">
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handleOpenReviews(op)}
                            className="h-8 text-xs gap-1.5"
                          >
                            <MessageSquare className="w-3.5 h-3.5 text-sky-600" />
                            <span>Lihat Ulasan</span>
                          </Button>
                        </td>
                      </tr>
                    );
                  })}

                  {operators.length === 0 && (
                    <tr>
                      <td colSpan={8} className="py-10 text-center text-muted-foreground">
                        Tidak ada operator yang cocok dengan filter yang dipilih.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Modal Dialog Ulasan CSAT Operator */}
      <Dialog open={modalOpen} onOpenChange={setModalOpen}>
        <DialogContent className="max-w-2xl max-h-[85vh] flex flex-col p-6">
          <DialogHeader className="border-b pb-4">
            <DialogTitle className="flex items-center justify-between pr-6">
              <div>
                <span className="text-lg font-bold">Ulasan & Komentar Pengguna</span>
                {selectedOperator && (
                  <p className="text-xs text-muted-foreground font-normal mt-0.5">
                    Operator: <strong>{selectedOperator.name}</strong> (@{selectedOperator.username})
                  </p>
                )}
              </div>
              {selectedOperator?.avg_rating && (
                <div className="flex items-center gap-1.5 bg-amber-50 px-3 py-1 rounded-full border border-amber-200">
                  <Star className="w-4 h-4 text-amber-500 fill-amber-500" />
                  <span className="text-sm font-bold text-amber-700">{selectedOperator.avg_rating} / 5.0</span>
                </div>
              )}
            </DialogTitle>
          </DialogHeader>

          <div className="flex-1 overflow-y-auto py-4 space-y-3">
            {loadingReviews ? (
              <div className="py-12 text-center text-sm text-slate-500 flex flex-col items-center justify-center gap-2">
                <Clock className="w-5 h-5 animate-spin text-sky-500" />
                <span>Memuat data ulasan...</span>
              </div>
            ) : reviews.length > 0 ? (
              reviews.map((rev) => (
                <div key={rev.id} className="p-4 rounded-xl border border-slate-100 bg-slate-50/50 space-y-2">
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <div className="flex items-center gap-2">
                        <span className="font-semibold text-sm text-slate-900">{rev.user_name}</span>
                        <span className="text-xs text-slate-400">•</span>
                        <span className="text-xs text-slate-500">{rev.divisi_nama}</span>
                      </div>
                      <p className="text-xs text-slate-500 mt-0.5">
                        Tiket <span className="font-mono font-medium text-slate-700">#{rev.ticket_id}</span> ({rev.layanan_nama})
                      </p>
                    </div>

                    <div className="flex flex-col items-end shrink-0">
                      <div className="flex items-center gap-0.5">
                        {Array.from({ length: 5 }, (_, i) => (
                          <Star
                            key={i}
                            className={`w-3.5 h-3.5 ${
                              i < rev.rating
                                ? 'text-amber-400 fill-amber-400'
                                : 'text-slate-200 fill-slate-100'
                            }`}
                          />
                        ))}
                      </div>
                      <span className="text-[11px] text-slate-400 mt-1">{rev.created_at}</span>
                    </div>
                  </div>

                  {rev.komentar ? (
                    <div className="p-3 bg-white rounded-lg border border-slate-100 text-xs text-slate-700 leading-relaxed italic">
                      "{rev.komentar}"
                    </div>
                  ) : (
                    <p className="text-xs text-slate-400 italic">Pengguna tidak meninggalkan komentar teks.</p>
                  )}
                </div>
              ))
            ) : (
              <div className="py-12 text-center text-sm text-slate-500">
                Belum ada komentar atau ulasan untuk operator ini pada periode yang dipilih.
              </div>
            )}
          </div>
        </DialogContent>
      </Dialog>

      {/* Print Styles */}
      <style>{`
        @media print {
          @page { size: landscape; margin: 10mm; }
          body { -webkit-print-color-adjust: exact; print-color-adjust: exact; background-color: white !important; }
          .print\\:hidden { display: none !important; }
          .print\\:break-inside-avoid { break-inside: avoid; }
          .print\\:shadow-none { box-shadow: none !important; border: 1px solid #e2e8f0; }
          main { padding: 0 !important; margin: 0 !important; background: transparent !important; }
          header, nav, footer { display: none !important; }
        }
      `}</style>
    </AdminLayout>
  );
}
