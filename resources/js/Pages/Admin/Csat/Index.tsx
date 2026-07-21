import React from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Star } from 'lucide-react';
import ReactECharts from 'echarts-for-react';
import { formatTicketId } from '@/lib/utils';

export default function CsatIndex({ csats, stats, csatPerUnit, filters, units, subUnits }: any) {
    const applyFilter = (key: string, value: string) => {
        const params = { ...filters, [key]: value || undefined };
        router.get(route('admin.csat.index'), params, { });
    };

    return (
        <AdminLayout title="Laporan CSAT">
            <Head title="Laporan CSAT" />
            <div className="space-y-6">
                <h1 className="text-2xl font-bold">Laporan CSAT</h1>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">Rata-rata Rating</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold text-yellow-500">
                                {stats?.avgRating ?? '-'}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">Total Rating</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold">{stats?.totalRating ?? 0}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">Distribusi Rating</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-1">
                                {stats?.ratingDistribution?.map((r: any) => (
                                    <div key={r.rating} className="flex items-center gap-2 text-sm">
                                        <span className="w-6">{r.rating}:</span>
                                        <div className="flex-1 h-4 bg-muted rounded overflow-hidden">
                                            <div
                                                className="h-full bg-yellow-400 rounded"
                                                style={{ width: `${stats.totalRating > 0 ? (r.jumlah / stats.totalRating) * 100 : 0}%` }}
                                            />
                                        </div>
                                        <span className="text-xs text-muted-foreground w-12 text-right">{r.jumlah}</span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {csatPerUnit?.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Rata-rata CSAT per Unit</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ReactECharts option={{
                                tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                                grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
                                xAxis: { type: 'value', min: 0, max: 5 },
                                yAxis: { type: 'category', data: [...csatPerUnit].reverse().map((d: any) => d.unit_nama), axisLabel: { width: 120, overflow: 'truncate' } },
                                series: [{
                                    name: 'Rata-rata',
                                    type: 'bar',
                                    itemStyle: { color: '#eab308', borderRadius: [0, 4, 4, 0] },
                                    data: [...csatPerUnit].reverse().map((d: any) => d.rata_rata)
                                }]
                            }} style={{ height: csatPerUnit.length * 60 + 40, width: '100%' }} />
                        </CardContent>
                    </Card>
                )}

                <div className="flex flex-wrap gap-2">
                    <select
                        className="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        value={filters?.unit_id || ''}
                        onChange={(e) => applyFilter('unit_id', e.target.value)}
                    >
                        <option value="">Semua Unit</option>
                        {units?.map((u: any) => (
                            <option key={u.id} value={u.id}>{u.nama_unit}</option>
                        ))}
                    </select>
                    <select
                        className="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        value={filters?.sub_unit_id || ''}
                        onChange={(e) => applyFilter('sub_unit_id', e.target.value)}
                    >
                        <option value="">Semua Sub Unit</option>
                        {subUnits?.map((s: any) => (
                            <option key={s.id} value={s.id}>{s.nama_layanan}</option>
                        ))}
                    </select>
                    <input
                        type="month"
                        className="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        value={filters?.bulan || ''}
                        onChange={(e) => applyFilter('bulan', e.target.value)}
                    />
                    {[1,2,3,4,5].map((r) => (
                        <button
                            key={r}
                            onClick={() => applyFilter('rating_min', String(r))}
                            className={`px-2 py-1 rounded text-xs border ${
                                Number(filters?.rating_min) === r ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'
                            }`}
                        >
                            {r}+
                        </button>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Data Rating</CardTitle>
                            <span className="text-sm text-muted-foreground">{csats?.total ?? 0} total</span>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-muted-foreground">
                                        <th className="pb-2 font-medium">Tiket</th>
                                        <th className="pb-2 font-medium">User</th>
                                        <th className="pb-2 font-medium">Unit</th>
                                        <th className="pb-2 font-medium">Rating</th>
                                        <th className="pb-2 font-medium">Komentar</th>
                                        <th className="pb-2 font-medium">Tanggal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {csats?.data?.map((csat: any) => (
                                        <tr key={csat.id} className="border-b last:border-0">
                                            <td className="py-2">#TKT-{formatTicketId(csat.ticket_id)}</td>
                                            <td className="py-2">{csat.user?.username || '-'}</td>
                                            <td className="py-2">{csat.ticket?.sub_unit?.unit?.nama_unit || '-'}</td>
                                            <td className="py-2">
                                                <div className="flex items-center gap-0.5">
                                                    {[1,2,3,4,5].map((s) => (
                                                        <Star key={s} className={`h-3 w-3 ${s <= csat.rating ? 'fill-yellow-400 text-yellow-400' : 'text-gray-300'}`} />
                                                    ))}
                                                </div>
                                            </td>
                                            <td className="py-2 max-w-xs truncate">{csat.komentar || '-'}</td>
                                            <td className="py-2 text-xs">{new Date(csat.created_at).toLocaleDateString('id-ID')}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {csats?.links && (
                            <div className="flex items-center justify-between mt-4">
                                <span className="text-sm text-muted-foreground">
                                    {csats.from}-{csats.to} dari {csats.total}
                                </span>
                                <div className="flex gap-1">
                                    {csats.links.filter((l: any) => l.url).map((link: any, i: number) => (
                                        <button
                                            key={i}
                                            onClick={() => router.get(link.url, {}, { })}
                                            className={`px-2 py-1 rounded text-xs border ${
                                                link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'
                                            }`}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
