import React from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { StatusBadge } from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { TicketTimeline } from '@/Components/TicketTimeline';
import { TicketAttachmentList } from '@/Components/TicketAttachmentList';
import { formatDateId } from '@/lib/utils';
import { AttachmentViewer } from '@/Components/AttachmentViewer';
import { FileText, ArrowLeft, Timer, AlertTriangle, PauseCircle, CheckCircle2, XCircle, Shield, Download, Eye } from 'lucide-react';

const validTransitions: Record<string, string[]> = {
    open: ['on_proses', 'reject', 'pending'],
    on_proses: ['solve', 'pending'],
    pending: ['on_proses'],
};

const statusLabels: Record<string, string> = {
    open: 'Open', on_proses: 'On Proses', pending: 'Pending', solve: 'Selesai', reject: 'Ditolak', dibatalkan: 'Dibatalkan',
};

export default function TicketDetail({ ticket, formFields }: any) {
    const { data: statusData, setData: setStatusData, patch: patchStatus, processing: processingStatus, errors: errorsStatus } = useForm({ status: '', catatan: '' });
    const { data: priorityData, setData: setPriorityData, patch: patchPriority, processing: processingPriority, errors: errorsPriority } = useForm({ priority: ticket.priority || '' });

    const transitions = validTransitions[ticket.status] || [];

    const handleStatusSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        patchStatus(route('admin.tiket.status', ticket.id));
    };

    const handlePrioritySubmit = (e: React.FormEvent) => {
        e.preventDefault();
        patchPriority(route('admin.tiket.priority', ticket.id));
    };

    const renderFormValue = (field: any) => {
        if (field.tipe_field === 'upload_gambar' || field.tipe_field === 'upload_file') {
            const attachment = ticket.attachments?.find((a: any) => a.field_id == field.id);
            return attachment ? (
                <AttachmentViewer attachment={attachment} viewRoute="admin.tiket.view" downloadRoute="admin.tiket.download">
                    <button type="button" className="text-blue-600 hover:underline flex items-center gap-1">
                        <Eye className="w-4 h-4" /> {attachment.original_name}
                    </button>
                </AttachmentViewer>
            ) : '-';
        }

        const value = ticket.form_data?.[field.id];
        if (value === undefined || value === null) return '-';
        if (field.tipe_field === 'nominal_rp') {
            return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value) || 0);
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
                                <TicketAttachmentList attachments={ticket.attachments} downloadRoute="admin.tiket.download" />
                            </CardContent>
                        </Card>
                    )}

                    {ticket.logs?.length > 0 && (
                        <Card>
                            <CardHeader><CardTitle className="flex items-center gap-2"><FileText className="w-5 h-5" /> Timeline</CardTitle></CardHeader>
                            <CardContent>
                                <TicketTimeline logs={ticket.logs} />
                            </CardContent>
                        </Card>
                    )}
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader><CardTitle>Aksi Status</CardTitle></CardHeader>
                        <CardContent>
                            {transitions.length > 0 ? (
                                <form onSubmit={handleStatusSubmit} className="space-y-4">
                                    <div className="space-y-2">
                                        <span className="text-sm text-slate-500">Status Saat Ini:</span>
                                        <div><StatusBadge status={ticket.status} /></div>
                                    </div>
                                    <div className="space-y-2">
                                        <label className="text-sm font-medium">Ubah ke</label>
                                        <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={statusData.status} onChange={e => setStatusData('status', e.target.value)}>
                                            <option value="">Pilih status</option>
                                            {transitions.map((s: string) => (
                                                <option key={s} value={s}>{statusLabels[s] || s}</option>
                                            ))}
                                        </select>
                                        {errorsStatus.status && <p className="text-red-500 text-sm">{errorsStatus.status}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <label className="text-sm font-medium">Catatan Admin <span className="text-red-500">*</span></label>
                                        <textarea className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm min-h-[100px]" value={statusData.catatan} onChange={e => setStatusData('catatan', e.target.value)} placeholder="Wajib diisi..." />
                                        {errorsStatus.catatan && <p className="text-red-500 text-sm">{errorsStatus.catatan}</p>}
                                    </div>
                                    <Button type="submit" className="w-full" disabled={processingStatus}>Simpan Perubahan Status</Button>
                                </form>
                            ) : (
                                <p className="text-sm text-slate-500">Tidak ada transisi status yang tersedia.</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Prioritas SLA</CardTitle></CardHeader>
                        <CardContent>
                            <form onSubmit={handlePrioritySubmit} className="space-y-4">
                                <div className="space-y-2">
                                    <label className="text-sm font-medium">Ubah Prioritas</label>
                                    <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={priorityData.priority} onChange={e => setPriorityData('priority', e.target.value)}>
                                        <option value="">Pilih Prioritas (Default: Sedang)</option>
                                        <option value="Rendah">Rendah</option>
                                        <option value="Sedang">Sedang</option>
                                        <option value="Tinggi">Tinggi</option>
                                        <option value="Urgen">Urgen</option>
                                    </select>
                                    {errorsPriority.priority && <p className="text-red-500 text-sm">{errorsPriority.priority}</p>}
                                </div>
                                <Button type="submit" variant="secondary" className="w-full" disabled={processingPriority}>Set Prioritas</Button>
                            </form>
                        </CardContent>
                    </Card>

                    {/* SLA Tracking Card */}
                    {ticket.sla_tracking && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Shield className="w-5 h-5" /> Status SLA
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {/* Prioritas */}
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-slate-500">Prioritas Saat Ini</span>
                                    <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold ${
                                        ticket.priority === 'Urgen' ? 'bg-red-100 text-red-700' :
                                        ticket.priority === 'Tinggi' ? 'bg-orange-100 text-orange-700' :
                                        ticket.priority === 'Sedang' ? 'bg-yellow-100 text-yellow-700' :
                                        'bg-green-100 text-green-700'
                                    }`}>
                                        <AlertTriangle className="w-3 h-3" />
                                        {ticket.priority || 'Belum Diset'}
                                    </span>
                                </div>

                                {/* Respon */}
                                <div className="space-y-1">
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm text-slate-500">SLA Respon</span>
                                        {ticket.sla_tracking.responded_at ? (
                                            new Date(ticket.sla_tracking.responded_at) > new Date(ticket.sla_tracking.sla_response_deadline) ? (
                                                <span className="inline-flex items-center gap-1 text-xs text-red-600 font-medium">
                                                    <XCircle className="w-3 h-3" /> Terlanggar
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1 text-xs text-green-600 font-medium">
                                                    <CheckCircle2 className="w-3 h-3" /> Tercapai
                                                </span>
                                            )
                                        ) : ticket.sla_tracking.is_response_breached || (ticket.sla_tracking.sla_response_deadline && new Date() > new Date(ticket.sla_tracking.sla_response_deadline)) ? (
                                            <span className="inline-flex items-center gap-1 text-xs text-red-600 font-medium">
                                                <XCircle className="w-3 h-3" /> Terlanggar
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 text-xs text-blue-600 font-medium">
                                                <Timer className="w-3 h-3" /> Berjalan
                                            </span>
                                        )}
                                    </div>
                                    {ticket.sla_tracking.sla_response_deadline && (
                                        <p className="text-xs text-slate-400">
                                            Deadline: {formatDateId(ticket.sla_tracking.sla_response_deadline)}
                                        </p>
                                    )}
                                    {ticket.sla_tracking.responded_at && (
                                        <div className="text-xs mt-1 text-slate-500">
                                            Direspon: {formatDateId(ticket.sla_tracking.responded_at)}
                                        </div>
                                    )}
                                </div>

                                {/* Penyelesaian */}
                                <div className="space-y-1">
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm text-slate-500">SLA Penyelesaian</span>
                                        {ticket.sla_tracking.resolved_at ? (
                                            new Date(ticket.sla_tracking.resolved_at) > new Date(ticket.sla_tracking.sla_resolution_deadline) ? (
                                                <span className="inline-flex items-center gap-1 text-xs text-red-600 font-medium">
                                                    <XCircle className="w-3 h-3" /> Terlanggar
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1 text-xs text-green-600 font-medium">
                                                    <CheckCircle2 className="w-3 h-3" /> Tercapai
                                                </span>
                                            )
                                        ) : ticket.sla_tracking.is_resolution_breached || (ticket.sla_tracking.sla_resolution_deadline && new Date() > new Date(ticket.sla_tracking.sla_resolution_deadline)) ? (
                                            <span className="inline-flex items-center gap-1 text-xs text-red-600 font-medium">
                                                <XCircle className="w-3 h-3" /> Terlanggar
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 text-xs text-blue-600 font-medium">
                                                <Timer className="w-3 h-3" /> Berjalan
                                            </span>
                                        )}
                                    </div>
                                    {ticket.sla_tracking.sla_resolution_deadline && (
                                        <p className="text-xs text-slate-400">
                                            Deadline: {formatDateId(ticket.sla_tracking.sla_resolution_deadline)}
                                        </p>
                                    )}
                                    {ticket.sla_tracking.resolved_at && (
                                        <div className="text-xs mt-1 text-slate-500">
                                            Diselesaikan: {formatDateId(ticket.sla_tracking.resolved_at)}
                                        </div>
                                    )}
                                </div>

                                {/* Pause Status */}
                                {ticket.sla_tracking.paused_at && (
                                    <div className="flex items-center gap-2 p-2 bg-amber-50 rounded-md border border-amber-200">
                                        <PauseCircle className="w-4 h-4 text-amber-600" />
                                        <span className="text-xs text-amber-700 font-medium">SLA sedang di-pause</span>
                                    </div>
                                )}

                                {/* Total Paused */}
                                {ticket.sla_tracking.total_paused_minutes > 0 && (
                                    <div className="text-xs text-slate-400">
                                        Total waktu di-pause: {ticket.sla_tracking.total_paused_minutes} menit
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
