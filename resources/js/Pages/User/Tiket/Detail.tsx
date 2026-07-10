import React from 'react';
import { Head, Link } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { StatusBadge } from '@/Components/StatusBadge';
import { FileText, Download, Clock, User } from 'lucide-react';

interface DetailProps {
    ticket: any;
    formFields: any[];
}

export default function Detail({ ticket, formFields }: DetailProps) {
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
                    <p className="text-slate-500 mt-1">Dibuat pada {new Date(ticket.created_at).toLocaleString()}</p>
                </div>
                <Link href={route('tiket.riwayat')}>
                    <Button variant="outline">Kembali ke Riwayat</Button>
                </Link>
            </div>

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
                                        {ticket.form_data && ticket.form_data[field.id] !== undefined && ticket.form_data[field.id] !== null && ticket.form_data[field.id] !== ''
                                            ? String(ticket.form_data[field.id])
                                            : '-'}
                                    </p>
                                </div>
                            ))
                        ) : (
                            <p className="text-slate-500">Tidak ada data form yang diisi.</p>
                        )}
                    </CardContent>
                </Card>

                {ticket.attachments && ticket.attachments.length > 0 && (
                    <Card className="md:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileText className="h-5 w-5" />
                                Lampiran
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {ticket.attachments.map((att: any) => (
                                    <div key={att.id} className="flex items-center justify-between p-3 border rounded-lg">
                                        <div className="flex items-center gap-3">
                                            <FileText className="h-4 w-4 text-slate-400" />
                                            <span className="text-sm font-medium">{att.original_name || att.file_path?.split('/').pop()}</span>
                                        </div>
                                        <a
                                            href={route('tiket.download', att.id)}
                                            className="text-blue-600 hover:text-blue-800 flex items-center gap-1 text-sm"
                                        >
                                            <Download className="h-4 w-4" />
                                            Download
                                        </a>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {ticket.logs && ticket.logs.length > 0 && (
                    <Card className="md:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Clock className="h-5 w-5" />
                                Timeline Respon
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-4 border-l-2 border-slate-200 ml-3 pl-4">
                                {ticket.logs.map((log: any) => (
                                    <div key={log.id} className="relative">
                                        <div className="absolute -left-[23px] top-1 w-3 h-3 bg-blue-500 rounded-full border-2 border-white"></div>
                                        <div className="flex items-center gap-2 mb-1">
                                            <p className="text-sm font-semibold capitalize">{log.aksi}</p>
                                            {log.admin && (
                                                <span className="text-xs text-slate-400 flex items-center gap-1">
                                                    <User className="h-3 w-3" /> {log.admin.username}
                                                </span>
                                            )}
                                        </div>
                                        {log.catatan && <p className="text-sm text-slate-600">{log.catatan}</p>}
                                        <p className="text-xs text-slate-400 mt-1">
                                            {new Date(log.timestamp || log.created_at).toLocaleString()}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
            </div>
        </UserLayout>
    );
}
