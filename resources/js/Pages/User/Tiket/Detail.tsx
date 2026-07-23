import React, { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { StatusBadge } from '@/Components/StatusBadge';
import { TicketTimeline } from '@/Components/TicketTimeline';
import { TicketAttachmentList } from '@/Components/TicketAttachmentList';
import { formatDateId, formatTicketId } from '@/lib/utils';
import { AttachmentViewer } from '@/Components/AttachmentViewer';
import { FileText, XCircle, Eye, CheckCircle2 } from 'lucide-react';
import { CsatDialog } from '@/Components/CsatDialog';
import { ConfirmDialog } from '@/Components/ConfirmDialog';

interface DetailProps {
    ticket: any;
    formFields: any[];
    maxRevisions: number;
}

export default function Detail({ ticket, formFields, maxRevisions }: DetailProps) {
    const { data: replyData, setData: setReplyData, post: postReply, processing: processingReply, errors: errorsReply, reset: resetReply } = useForm({ catatan: '', general_attachments: [] as File[], _method: 'post' });
    const { data: revData, setData: setRevData, post: postRev, processing: processingRev, errors: errorsRev, reset: resetRev } = useForm({ catatan: '', general_attachments: [] as File[], _method: 'post' });
    const [showRevForm, setShowRevForm] = useState(false);
    const [showConfirm, setShowConfirm] = useState(false);
    const showCsat = ['solve', 'selesai'].includes(String(ticket.status || '').toLowerCase());
    const canCancel = ticket.status === 'open';

    const handleCancel = () => {
        router.patch(route('tiket.batal', ticket.id));
    };

    const renderFormValue = (field: any) => {
        if (field.tipe_field === 'upload_gambar' || field.tipe_field === 'upload_file') {
            const fieldAttachments = ticket.attachments?.filter((a: any) => a.field_id == field.id);
            return fieldAttachments && fieldAttachments.length > 0 ? (
                <div className="flex flex-col gap-2 mt-1">
                    {fieldAttachments.map((attachment: any, idx: number) => (
                        <AttachmentViewer key={idx} attachment={attachment} viewRoute="tiket.view" downloadRoute="tiket.download">
                            <button type="button" className="text-blue-600 hover:underline flex items-center gap-1 text-sm text-left">
                                <Eye className="w-4 h-4 flex-shrink-0" /> <span className="truncate">{attachment.original_name}</span>
                            </button>
                        </AttachmentViewer>
                    ))}
                </div>
            ) : '-';
        }

        const value = ticket.form_data?.[field.id];
        if (value === undefined || value === null || value === '') return '-';
        if (field.tipe_field === 'nominal_rp') {
            return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value) || 0);
        }
        if (field.tipe_field === 'checkbox' && typeof value === 'boolean') return value ? 'Ya' : 'Tidak';
        if (field.tipe_field === 'multi_pilih' && Array.isArray(value)) return value.join(', ');
        
        const stringValue = String(value);
        const urlRegex = /(https?:\/\/[^\s]+)/g;
        if (urlRegex.test(stringValue)) {
            const parts = stringValue.split(urlRegex);
            return (
                <>
                    {parts.map((part, i) => {
                        if (part.match(/^https?:\/\//)) {
                            return (
                                <a key={i} href={part} target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline break-all">
                                    {part}
                                </a>
                            );
                        }
                        return <span key={i}>{part}</span>;
                    })}
                </>
            );
        }
        
        return stringValue;
    };

    return (
        <UserLayout title={`Tiket #TKT-${formatTicketId(ticket.id)}`}>
            <div className="max-w-4xl mx-auto py-8 px-4">
                <Head title={`Tiket #TKT-${formatTicketId(ticket.id)}`} />

            <div className="flex justify-between items-center mb-6">
                <div>
                    <h1 className="text-2xl font-bold flex items-center gap-3">
                        #TKT-{formatTicketId(ticket.id)}
                        <StatusBadge status={ticket.status} />
                    </h1>
                    <p className="text-slate-500 mt-1">Dibuat pada {formatDateId(ticket.created_at)}</p>
                </div>
                <div className="flex items-center gap-2">
                    {showCsat && (
                        <CsatDialog ticketId={ticket.id} existingRating={ticket.csat?.rating} existingKomentar={ticket.csat?.komentar} />
                    )}
                    {canCancel && (
                        <Button variant="destructive" onClick={() => setShowConfirm(true)}>
                            <XCircle className="h-4 w-4 mr-1" /> Batalkan
                        </Button>
                    )}
                    <Link href={route('tiket.riwayat')}>
                        <Button variant="outline">Kembali ke Riwayat</Button>
                    </Link>
                </div>
            </div>

            <ConfirmDialog
                open={showConfirm}
                onOpenChange={setShowConfirm}
                title="Batalkan Tiket?"
                message="Tiket yang dibatalkan tidak bisa dikembalikan lagi. Yakin ingin membatalkan?"
                confirmText="Ya, Batalkan"
                cancelText="Tidak"
                onConfirm={handleCancel}
            />

            {ticket.status === 'solve' && ticket.sub_unit?.is_revision_enabled && !ticket.is_result_accepted && (
                <div className="mb-6 p-4 bg-blue-50 border border-blue-200 text-blue-700 rounded-lg flex flex-col gap-4">
                    <div>
                        <p className="font-semibold text-lg flex items-center gap-2"><Eye className="w-5 h-5"/> Review Hasil</p>
                        <p className="text-sm">Tiket ini sudah diselesaikan. Silakan periksa hasil pekerjaan. Anda dapat menerima hasil akhir atau meminta revisi. Sisa revisi Anda: {maxRevisions - (ticket.revision_count || 0)} kali.</p>
                    </div>
                    <div className="flex gap-3">
                        <Button 
                            variant="default" 
                            className="bg-green-600 hover:bg-green-700 text-white"
                            onClick={() => {
                                if(confirm('Yakin ingin menerima hasil akhir ini?')) {
                                    router.post(route('tiket.accept-result', ticket.id));
                                }
                            }}
                        >
                            Terima Hasil Akhir
                        </Button>
                        {(ticket.revision_count || 0) < maxRevisions && (
                            <Button variant="outline" className="border-red-500 text-red-600 hover:bg-red-50" onClick={() => setShowRevForm(!showRevForm)}>
                                Minta Revisi ({maxRevisions - (ticket.revision_count || 0)} sisa)
                            </Button>
                        )}
                    </div>
                    
                    {showRevForm && (
                        <div className="mt-4 p-4 bg-white border rounded-lg shadow-sm">
                            <h3 className="font-semibold mb-2">Form Permintaan Revisi</h3>
                            <form onSubmit={(e) => {
                                e.preventDefault();
                                postRev(route('tiket.request-revision', ticket.id), { onSuccess: () => { resetRev(); setShowRevForm(false); } });
                            }} className="space-y-4">
                                <div className="space-y-2">
                                    <label className="text-sm font-medium">Catatan Revisi <span className="text-red-500">*</span></label>
                                    <textarea className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm min-h-[100px]" value={revData.catatan} onChange={e => setRevData('catatan', e.target.value)} placeholder="Tuliskan bagian mana yang perlu direvisi..." required />
                                    {errorsRev.catatan && <p className="text-red-500 text-sm">{errorsRev.catatan}</p>}
                                </div>
                                <div className="space-y-2">
                                    <label className="text-sm font-medium">Lampiran Pendukung Revisi (Opsional)</label>
                                    <p className="text-xs text-slate-500">Maks. 3 file, 3MB/file.</p>
                                    <input
                                        type="file" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"
                                        className="w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                                        onChange={e => {
                                            const files = Array.from(e.target.files || []);
                                            if (revData.general_attachments.length + files.length > 3) { alert('Maksimal hanya 3 lampiran.'); return; }
                                            const validFiles = files.filter(f => { if (f.size > 3 * 1024 * 1024) { alert(`${f.name} melebihi 3MB.`); return false; } return true; });
                                            setRevData('general_attachments', [...revData.general_attachments, ...validFiles]);
                                            e.target.value = '';
                                        }}
                                    />
                                    {errorsRev.general_attachments && <p className="text-red-500 text-sm">{errorsRev.general_attachments}</p>}
                                    {revData.general_attachments.length > 0 && (
                                        <div className="mt-2 space-y-2">
                                            {revData.general_attachments.map((file, idx) => (
                                                <div key={idx} className="flex justify-between items-center text-sm p-2 bg-slate-50 border rounded">
                                                    <span className="truncate max-w-[200px]">{file.name}</span>
                                                    <button type="button" onClick={() => setRevData('general_attachments', revData.general_attachments.filter((_, i) => i !== idx))} className="text-red-500">Hapus</button>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                                <div className="flex justify-end gap-2">
                                    <Button type="button" variant="ghost" onClick={() => setShowRevForm(false)}>Batal</Button>
                                    <Button type="submit" disabled={processingRev}>Kirim Permintaan Revisi</Button>
                                </div>
                            </form>
                        </div>
                    )}
                </div>
            )}

            {ticket.status === 'solve' && ticket.sub_unit?.is_revision_enabled && ticket.is_result_accepted && (
                <div className="mb-6 p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg flex items-center gap-3">
                    <CheckCircle2 className="w-5 h-5 flex-shrink-0" />
                    <p className="font-medium">Anda telah menerima hasil akhir tiket ini.</p>
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Data Pengaju</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        <div>
                            <span className="text-sm text-slate-500">Divisi:</span>
                            <p className="font-medium">{ticket.org_divisi?.nama_divisi || '-'}</p>
                        </div>
                        <div>
                            <span className="text-sm text-slate-500">Unit Organisasi:</span>
                            <p className="font-medium">{ticket.org_unit?.nama_unit_organisasi || '-'}</p>
                        </div>
                        <div>
                            <span className="text-sm text-slate-500">Jabatan:</span>
                            <p className="font-medium">{ticket.jabatan?.nama_jabatan || '-'}</p>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Layanan Tujuan</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        <div>
                            <span className="text-sm text-slate-500">Unit:</span>
                            <p className="font-medium">{ticket.unit?.nama_unit || '-'}</p>
                        </div>
                        <div>
                            <span className="text-sm text-slate-500">Sub Unit:</span>
                            <p className="font-medium">{ticket.sub_unit?.nama_layanan || '-'}</p>
                        </div>
                    </CardContent>
                </Card>

                <Card className="md:col-span-2">
                    <CardHeader>
                        <CardTitle>Isian Form</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {formFields?.length > 0 ? (
                            formFields.map((field) => (
                                <div key={field.id}>
                                    <span className="text-sm text-slate-500">{field.label}:</span>
                                    <p className="font-medium mt-1">
                                        {renderFormValue(field)}
                                    </p>
                                </div>
                            ))
                        ) : (
                            <p className="text-slate-500">Tidak ada data form yang diisi.</p>
                        )}
                    </CardContent>
                </Card>

                {ticket.attachments?.length > 0 && (
                    <Card className="md:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileText className="h-5 w-5" />
                                Lampiran
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <TicketAttachmentList attachments={ticket.attachments} downloadRoute="tiket.download" />
                        </CardContent>
                    </Card>
                )}

                {ticket.logs?.length > 0 && (
                    <Card className="md:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileText className="h-5 w-5" />
                                Timeline Respon
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <TicketTimeline logs={ticket.logs} />
                        </CardContent>
                    </Card>
                )}

                {ticket.status !== 'solve' && ticket.status !== 'reject' && ticket.status !== 'dibatalkan' && ticket.status !== 'waiting_approval' && (
                    <Card className="md:col-span-2">
                        <CardHeader>
                            <CardTitle>Balas Tiket</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={(e) => {
                                e.preventDefault();
                                postReply(route('tiket.reply', ticket.id), { onSuccess: () => resetReply() });
                            }} className="space-y-4">
                                <div className="space-y-2">
                                    <label className="text-sm font-medium">Catatan <span className="text-red-500">*</span></label>
                                    <textarea className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm min-h-[100px]" value={replyData.catatan} onChange={e => setReplyData('catatan', e.target.value)} placeholder="Tulis balasan Anda di sini..." required />
                                    {errorsReply.catatan && <p className="text-red-500 text-sm">{errorsReply.catatan}</p>}
                                </div>
                                <div className="space-y-2">
                                    <label className="text-sm font-medium">Lampiran Tambahan (Opsional)</label>
                                    <p className="text-xs text-slate-500">Maks. 3 file, 3MB/file (JPG, PNG, PDF, DOC, DOCX).</p>
                                    <input
                                        type="file"
                                        multiple
                                        accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"
                                        className="w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                                        onChange={e => {
                                            const files = Array.from(e.target.files || []);
                                            if (replyData.general_attachments.length + files.length > 3) {
                                                alert('Maksimal hanya 3 lampiran.');
                                                return;
                                            }
                                            const validFiles = files.filter(f => {
                                                if (f.size > 3 * 1024 * 1024) { alert(`${f.name} melebihi 3MB.`); return false; }
                                                return true;
                                            });
                                            setReplyData('general_attachments', [...replyData.general_attachments, ...validFiles]);
                                            e.target.value = '';
                                        }}
                                    />
                                    {errorsReply.general_attachments && <p className="text-red-500 text-sm">{errorsReply.general_attachments}</p>}
                                    {replyData.general_attachments.length > 0 && (
                                        <div className="mt-2 space-y-2">
                                            {replyData.general_attachments.map((file, idx) => (
                                                <div key={idx} className="flex justify-between items-center text-sm p-2 bg-slate-50 border rounded">
                                                    <span className="truncate max-w-[200px]">{file.name}</span>
                                                    <button type="button" onClick={() => setReplyData('general_attachments', replyData.general_attachments.filter((_, i) => i !== idx))} className="text-red-500">Hapus</button>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                                <Button type="submit" disabled={processingReply}>Kirim Balasan</Button>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
            </div>
        </UserLayout>
    );
}
