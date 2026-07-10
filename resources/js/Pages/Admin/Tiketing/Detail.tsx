import React from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { StatusBadge } from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { FileText, Download, Clock, User, ArrowLeft } from 'lucide-react';

const validTransitions: Record<string, string[]> = {
    open: ['on_proses', 'reject', 'pending'],
    on_proses: ['solve', 'pending'],
    pending: ['on_proses'],
};

const statusLabels: Record<string, string> = {
    open: 'Open', on_proses: 'On Proses', pending: 'Pending', solve: 'Selesai', reject: 'Ditolak',
};

export default function TicketDetail({ ticket, formFields }: any) {
    const { data, setData, patch, processing, errors } = useForm({ status: '', catatan: '' });

    const transitions = validTransitions[ticket.status] || [];

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        patch(route('admin.tiket.status', ticket.id));
    };

    const renderFormValue = (field: any) => {
        const value = ticket.form_data?.[field.id];
        if (value === undefined || value === null) return '-';
        if (field.tipe_field === 'upload_gambar' || field.tipe_field === 'upload_file') {
            const attachment = ticket.attachments?.find((a: any) => a.field_id == field.id);
            return attachment ? (
                <a href={route('admin.tiket.download', attachment.id)} className="text-blue-600 hover:underline flex items-center gap-1">
                    <Download className="w-4 h-4" /> {attachment.original_name}
                </a>
            ) : '-';
        }
        if (field.tipe_field === 'checkbox' && typeof value === 'boolean') return value ? 'Ya' : 'Tidak';
        if (field.tipe_field === 'multi_pilih' && Array.isArray(value)) return value.join(', ');
        return String(value);
    };

    return (
        <AdminLayout title={`Detail Tiket #TKT-${ticket.id}`}>
            <Head title={`Tiket #TKT-${ticket.id}`} />

            <div className="flex items-center gap-3 mb-6">
                <Button variant="outline" size="sm" onClick={() => router.get(route('admin.tiket.index'))}>
                    <ArrowLeft className="w-4 h-4 mr-1" /> Kembali
                </Button>
                <h1 className="text-2xl font-bold flex items-center gap-3">
                    #TKT-{ticket.id} <StatusBadge status={ticket.status} />
                </h1>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2 space-y-6">
                    <Card>
                        <CardHeader><CardTitle>Data Pengaju</CardTitle></CardHeader>
                        <CardContent className="grid grid-cols-2 gap-4">
                            <div><span className="text-sm text-slate-500">Nama</span><p className="font-medium">{ticket.user?.username || '-'}</p></div>
                            <div><span className="text-sm text-slate-500">Email</span><p className="font-medium">{ticket.user?.email || '-'}</p></div>
                            <div><span className="text-sm text-slate-500">No. WA</span><p className="font-medium">{ticket.user?.no_wa || '-'}</p></div>
                            <div><span className="text-sm text-slate-500">Divisi</span><p className="font-medium">{ticket.org_divisi?.nama_divisi || '-'}</p></div>
                            <div><span className="text-sm text-slate-500">Unit Organisasi</span><p className="font-medium">{ticket.org_unit?.nama_unit_organisasi || '-'}</p></div>
                            <div><span className="text-sm text-slate-500">Jabatan</span><p className="font-medium">{ticket.jabatan?.nama_jabatan || '-'}</p></div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Data Pengajuan</CardTitle></CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid grid-cols-2 gap-3 pb-3 border-b">
                                <div><span className="text-sm text-slate-500">Unit</span><p className="font-medium">{ticket.unit?.nama_unit || '-'}</p></div>
                                <div><span className="text-sm text-slate-500">Sub Unit</span><p className="font-medium">{ticket.sub_unit?.nama_layanan || '-'}</p></div>
                            </div>
                            {formFields?.map((field: any) => (
                                <div key={field.id}>
                                    <span className="text-sm text-slate-500">{field.label}:</span>
                                    <p className="font-medium mt-0.5">{renderFormValue(field)}</p>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    {ticket.attachments?.length > 0 && (
                        <Card>
                            <CardHeader><CardTitle className="flex items-center gap-2"><FileText className="w-5 h-5" /> Lampiran</CardTitle></CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    {ticket.attachments.map((att: any) => (
                                        <div key={att.id} className="flex items-center justify-between p-3 border rounded-lg">
                                            <span className="text-sm font-medium">{att.original_name}</span>
                                            <a href={route('admin.tiket.download', att.id)} className="text-blue-600 hover:underline flex items-center gap-1 text-sm">
                                                <Download className="w-4 h-4" /> Download
                                            </a>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {ticket.logs?.length > 0 && (
                        <Card>
                            <CardHeader><CardTitle className="flex items-center gap-2"><Clock className="w-5 h-5" /> Timeline</CardTitle></CardHeader>
                            <CardContent>
                                <div className="space-y-4 border-l-2 border-slate-200 ml-3 pl-4">
                                    {ticket.logs.map((log: any) => (
                                        <div key={log.id} className="relative">
                                            <div className="absolute -left-[23px] top-1 w-3 h-3 bg-blue-500 rounded-full border-2 border-white" />
                                            <div className="flex items-center gap-2 mb-1">
                                                <p className="text-sm font-semibold capitalize">{log.aksi}</p>
                                                {log.admin && (
                                                    <span className="text-xs text-slate-400 flex items-center gap-1">
                                                        <User className="w-3 h-3" /> {log.admin.username}
                                                    </span>
                                                )}
                                            </div>
                                            {log.catatan && <p className="text-sm text-slate-600">{log.catatan}</p>}
                                            <p className="text-xs text-slate-400 mt-1">{new Date(log.timestamp).toLocaleString()}</p>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader><CardTitle>Aksi Status</CardTitle></CardHeader>
                        <CardContent>
                            {transitions.length > 0 ? (
                                <form onSubmit={handleSubmit} className="space-y-4">
                                    <div className="space-y-2">
                                        <span className="text-sm text-slate-500">Status Saat Ini:</span>
                                        <div><StatusBadge status={ticket.status} /></div>
                                    </div>
                                    <div className="space-y-2">
                                        <label className="text-sm font-medium">Ubah ke</label>
                                        <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={data.status} onChange={e => setData('status', e.target.value)}>
                                            <option value="">Pilih status</option>
                                            {transitions.map((s: string) => (
                                                <option key={s} value={s}>{statusLabels[s] || s}</option>
                                            ))}
                                        </select>
                                        {errors.status && <p className="text-red-500 text-sm">{errors.status}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <label className="text-sm font-medium">Catatan Admin <span className="text-red-500">*</span></label>
                                        <textarea className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm min-h-[100px]" value={data.catatan} onChange={e => setData('catatan', e.target.value)} placeholder="Wajib diisi..." />
                                        {errors.catatan && <p className="text-red-500 text-sm">{errors.catatan}</p>}
                                    </div>
                                    <Button type="submit" className="w-full" disabled={processing}>Simpan Perubahan</Button>
                                </form>
                            ) : (
                                <p className="text-sm text-slate-500">Tidak ada transisi status yang tersedia.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
