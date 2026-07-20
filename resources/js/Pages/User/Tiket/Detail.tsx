import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { StatusBadge } from '@/Components/StatusBadge';
import { TicketTimeline } from '@/Components/TicketTimeline';
import { TicketAttachmentList } from '@/Components/TicketAttachmentList';
import { formatDateId } from '@/lib/utils';
import { AttachmentViewer } from '@/Components/AttachmentViewer';
import { FileText, XCircle, Eye } from 'lucide-react';
import { CsatDialog } from '@/Components/CsatDialog';
import { ConfirmDialog } from '@/Components/ConfirmDialog';

interface DetailProps {
    ticket: any;
    formFields: any[];
}

export default function Detail({ ticket, formFields }: DetailProps) {
    const [showConfirm, setShowConfirm] = useState(false);
    const showCsat = ['solve', 'selesai'].includes(String(ticket.status || '').toLowerCase());
    const canCancel = ticket.status === 'open';

    const handleCancel = () => {
        router.patch(route('tiket.batal', ticket.id));
    };

    const renderFormValue = (field: any) => {
        if (field.tipe_field === 'upload_gambar' || field.tipe_field === 'upload_file') {
            const attachment = ticket.attachments?.find((a: any) => a.field_id == field.id);
            return attachment ? (
                <AttachmentViewer attachment={attachment} viewRoute="tiket.view" downloadRoute="tiket.download">
                    <button type="button" className="text-blue-600 hover:underline flex items-center gap-1">
                        <Eye className="w-4 h-4" /> {attachment.original_name}
                    </button>
                </AttachmentViewer>
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
        <UserLayout title={`Tiket #TKT-${ticket.id}`}>
            <div className="max-w-4xl mx-auto py-8 px-4">
                <Head title={`Tiket #TKT-${ticket.id}`} />

            <div className="flex justify-between items-center mb-6">
                <div>
                    <h1 className="text-2xl font-bold flex items-center gap-3">
                        #TKT-{ticket.id}
                        <StatusBadge status={ticket.status} />
                    </h1>
                    <p className="text-slate-500 mt-1">Dibuat pada {formatDateId(ticket.created_at)}</p>
                </div>
                <div className="flex items-center gap-2">
                    {showCsat && (
                        <CsatDialog ticketId={ticket.id} existingRating={ticket.csat?.rating} />
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
            </div>
            </div>
        </UserLayout>
    );
}
