import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { StatusBadge } from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import MonthlyUnitChart from '@/Components/Charts/MonthlyUnitChart';
import SubUnitChart from '@/Components/Charts/SubUnitChart';
import { AlertTriangle, Eye, Clock } from 'lucide-react';
import { PieChart, Pie, Cell, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip as RechartsTooltip, Legend, ResponsiveContainer, LineChart, Line } from 'recharts';

const PIE_COLORS = ['#22c55e', '#ef4444', '#f97316'];

const STATUS_META: Record<string, { label: string; color: string }> = {
    open: { label: 'Open', color: 'text-blue-600' },
    on_proses: { label: 'On Proses', color: 'text-yellow-600' },
    pending: { label: 'Pending', color: 'text-gray-600' },
    solve: { label: 'Selesai', color: 'text-green-600' },
    reject: { label: 'Ditolak', color: 'text-red-600' },
};

export default function DashboardIndex({ totalTickets, statusCounts, topUsers, followUpTickets, monthlyChartData, yearlyChartData, subUnitChartData, units, filters, slaStats, slaPieChartData, slaBarChartData, slaTrendData, slaFilters }: any) {
    const [month, setMonth] = useState(filters?.month !== null && filters?.month !== undefined ? String(filters.month) : '');
    const [year, setYear] = useState(filters?.year !== null && filters?.year !== undefined ? String(filters.year) : '');
    const [selectedUnit, setSelectedUnit] = useState('');
    const [chartMode, setChartMode] = useState<'bulanan' | 'tahunan'>('bulanan');

    const chartData = chartMode === 'tahunan' ? yearlyChartData : monthlyChartData;

    const applyFilter = () => {
        const params: any = {};
        if (month) params.month = month;
        if (year) params.year = year;
        router.get(route('admin.dashboard'), params, { preserveState: true });
    };

    const currentSubUnitData = selectedUnit
        ? (subUnitChartData?.[selectedUnit] || [])
        : (subUnitChartData?.['_all'] || []);

    const months = [
        { value: '', label: 'Semua Bulan' },
        ...Array.from({ length: 12 }, (_, i) => ({ value: String(i + 1), label: new Date(0, i).toLocaleString('id', { month: 'long' }) })),
    ];

    const years = [
        { value: '', label: 'Semua Tahun' },
        ...Array.from({ length: 5 }, (_, i) => ({ value: String(new Date().getFullYear() - i), label: String(new Date().getFullYear() - i) })),
    ];

    return (
        <AdminLayout title="Dashboard Admin">
            <Head title="Dashboard Admin" />

            <div className="flex items-center justify-between mb-4">
                <h2 className="text-2xl font-bold">Dashboard Admin</h2>
                <div className="flex items-center gap-2">
                    <select className="rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={month} onChange={e => setMonth(e.target.value)}>
                        {months.map(m => <option key={m.value} value={m.value}>{m.label}</option>)}
                    </select>
                    <select className="rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={year} onChange={e => setYear(e.target.value)}>
                        {years.map(y => <option key={y.value} value={y.value}>{y.label}</option>)}
                    </select>
                    <Button onClick={applyFilter}>Terapkan</Button>
                </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-5 gap-3 mb-6">
                {Object.entries(STATUS_META).map(([key, meta]) => (
                    <Card key={key}>
                        <CardHeader className="py-3"><CardTitle className="text-sm font-medium">{meta.label}</CardTitle></CardHeader>
                        <CardContent className="py-2"><p className={`text-3xl font-bold ${meta.color}`}>{statusCounts?.[key] ?? 0}</p></CardContent>
                    </Card>
                ))}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <Card>
                    <CardHeader className="py-3 flex flex-row items-center gap-2">
                        <AlertTriangle className="w-5 h-5 text-orange-500" />
                        <CardTitle className="text-base">Tiket Perlu Ditindak Lanjuti</CardTitle>
                    </CardHeader>
                    <CardContent className="py-2">
                        {followUpTickets?.length > 0 ? (
                            <div className="space-y-2">
                                {followUpTickets.map((t: any) => (
                                    <div key={t.id} className="flex items-center justify-between p-2 border rounded-lg text-sm">
                                        <div className="flex items-center gap-2 min-w-0">
                                            <span className="font-medium shrink-0">#TKT-{t.id}</span>
                                            <StatusBadge status={t.status} />
                                            <span className="text-slate-500 truncate">{t.user?.username || '-'}</span>
                                            <span className="text-xs text-slate-400 truncate">{t.unit?.nama_unit} / {t.sub_unit?.nama_layanan}</span>
                                        </div>
                                        <Button variant="ghost" size="sm" onClick={() => router.get(route('admin.tiket.show', t.id))}>
                                            <Eye className="w-4 h-4" />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-slate-500 py-4 text-center">Semua tiket sudah ditindak lanjuti.</p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="py-3"><CardTitle className="text-base">Top Pengaju Tiket</CardTitle></CardHeader>
                    <CardContent className="py-2">
                        {topUsers?.length > 0 ? (
                            <div className="space-y-2">
                                {topUsers.map((u: any, i: number) => (
                                    <div key={u.id} className="flex items-center gap-3 p-2 border rounded-lg text-sm">
                                        <span className="w-6 h-6 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xs font-bold shrink-0">{i + 1}</span>
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium truncate">{u.username}</p>
                                            <p className="text-xs text-slate-500">{u.nama_divisi || '-'}</p>
                                        </div>
                                        <span className="text-lg font-bold text-slate-700">{u.total_tiket}</span>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-slate-500 py-4 text-center">Belum ada data tiket.</p>
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <Card>
                    <CardHeader className="py-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Grafik {chartMode === 'tahunan' ? 'Tahunan' : `Bulanan${year ? ` (${year})` : ''}`}</CardTitle>
                            <div className="flex gap-1">
                                <Button variant={chartMode === 'bulanan' ? 'default' : 'outline'} size="sm" onClick={() => setChartMode('bulanan')}>Bulanan</Button>
                                <Button variant={chartMode === 'tahunan' ? 'default' : 'outline'} size="sm" onClick={() => setChartMode('tahunan')}>Tahunan</Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {chartData?.length > 0
                            ? <MonthlyUnitChart data={chartData} xKey={chartMode === 'tahunan' ? 'tahun' : 'bulan'} />
                            : <p className="text-sm text-slate-400 text-center py-8">{chartMode === 'tahunan' ? 'Belum ada data tahunan.' : 'Pilih tahun untuk menampilkan grafik bulanan.'}</p>
                        }
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="py-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Grafik per Sub Unit</CardTitle>
                            <select className="rounded-md border border-input bg-transparent px-2 py-1 text-xs" value={selectedUnit} onChange={e => setSelectedUnit(e.target.value)}>
                                <option value="">Semua Unit</option>
                                {units?.map((u: any) => <option key={u.id} value={u.id}>{u.nama_unit}</option>)}
                            </select>
                        </div>
                    </CardHeader>
                    <CardContent><SubUnitChart data={currentSubUnitData} /></CardContent>
                </Card>
            </div>

            {/* Kepatuhan SLA */}
            <section className="mt-8 space-y-6">
                <div className="flex items-center gap-2">
                    <Clock className="w-5 h-5" />
                    <h2 className="text-xl font-semibold">Kepatuhan SLA</h2>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-slate-500">Kepatuhan SLA Respon</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold text-green-500">{slaStats?.responseCompliance ?? 100}%</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-slate-500">Kepatuhan SLA Penyelesaian</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold text-blue-500">{slaStats?.resolutionCompliance ?? 100}%</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-slate-500">Total Breach</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold text-red-500">{slaStats?.totalBreach ?? 0}</p>
                            <p className="text-sm text-slate-500">dari {slaStats?.totalAll ?? 0} tiket</p>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <Card>
                        <CardHeader><CardTitle className="text-base">Distribusi Kepatuhan SLA</CardTitle></CardHeader>
                        <CardContent>
                            {slaPieChartData?.length > 0 ? (
                                <ResponsiveContainer width="100%" height={300}>
                                    <PieChart>
                                        <Pie data={slaPieChartData} dataKey="value" nameKey="name" cx="50%" cy="50%" outerRadius={100} label>
                                            {(slaPieChartData || []).map((_: any, i: number) => (
                                                <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                                            ))}
                                        </Pie>
                                        <RechartsTooltip />
                                        <Legend />
                                    </PieChart>
                                </ResponsiveContainer>
                            ) : (
                                <p className="text-sm text-slate-400 text-center py-8">Belum ada data SLA.</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-base">Tren Kepatuhan SLA (12 Bulan)</CardTitle></CardHeader>
                        <CardContent>
                            {slaTrendData?.length > 0 ? (
                                <ResponsiveContainer width="100%" height={300}>
                                    <LineChart data={slaTrendData}>
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis dataKey="bulan" />
                                        <YAxis domain={[0, 100]} unit="%" />
                                        <RechartsTooltip />
                                        <Line type="monotone" dataKey="persentase_sla" name="Kepatuhan SLA (%)" stroke="#22c55e" strokeWidth={2} dot={{ r: 4 }} />
                                    </LineChart>
                                </ResponsiveContainer>
                            ) : (
                                <p className="text-sm text-slate-400 text-center py-8">Belum ada data tren SLA.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {slaBarChartData?.length > 0 && (
                    <Card>
                        <CardHeader><CardTitle className="text-base">Kepatuhan SLA per Unit (Bulan Ini)</CardTitle></CardHeader>
                        <CardContent>
                            <ResponsiveContainer width="100%" height={300}>
                                <BarChart data={slaBarChartData}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="unit_nama" />
                                    <YAxis />
                                    <RechartsTooltip />
                                    <Legend />
                                    <Bar dataKey="dalam_sla" name="Dalam SLA" fill="#22c55e" stackId="a" />
                                    <Bar dataKey="breach" name="Breach" fill="#ef4444" stackId="a" />
                                </BarChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                )}
            </section>
        </AdminLayout>
    );
}
