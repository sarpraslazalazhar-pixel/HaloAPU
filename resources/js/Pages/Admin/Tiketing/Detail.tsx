import React, { useState, useEffect } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { StatusBadge } from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { TicketTimeline } from '@/Components/TicketTimeline';
import { TicketAttachmentList } from '@/Components/TicketAttachmentList';
import { formatDateId, formatTicketId } from '@/lib/utils';
import { AttachmentViewer } from '@/Components/AttachmentViewer';
import { FileText, ArrowLeft, Timer, AlertTriangle, PauseCircle, CheckCircle2, XCircle, Shield, Eye, Clock, Edit2, Paperclip, Info } from 'lucide-react';
import ImageEditorModal from '@/Components/FormBuilder/ImageEditorModal';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/Components/ui/tabs';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import Swal from 'sweetalert2';

const validTransitions = {
  open: ['on_proses', 'reject', 'pending'],
  on_proses: ['solve', 'pending', 'reject'],
  pending: ['on_proses'],
  need_revision: ['solve', 'pending', 'reject'],
} satisfies Record<string, string[]>;

const statusLabels = {
  open: 'Baru', on_proses: 'Diproses', pending: 'Tertunda', solve: 'Selesai', reject: 'Ditolak', dibatalkan: 'Dibatalkan', need_revision: 'Butuh Revisi', accepted: 'Diterima User',
} satisfies Record<string, string>;

export default function TicketDetail({ ticket, formFields, operators }: any) {
  // SAFETY: Inertia page props contain auth user and validation errors.
  const { auth, errors: pageErrors } = usePage().props as any;
  const canAssignOperator = auth?.permissions?.includes('akses-assign-operator');

  const [editorOpen, setEditorOpen] = useState(false);
  const [fileToEdit, setFileToEdit] = useState<{file: File, index: number, form: 'admin'} | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [showCancelBookingConfirm, setShowCancelBookingConfirm] = useState(false);
  const [showKembaliModal, setShowKembaliModal] = useState(false);
  const [kondisiKembali, setKondisiKembali] = useState<'baik' | 'rusak' | 'tidak_lengkap'>('baik');
  const [catatanKembali, setCatatanKembali] = useState('');
  const [isSubmittingKembali, setIsSubmittingKembali] = useState(false);
  const [isReturned, setIsReturned] = useState(Boolean(ticket.dikembalikan_at));

  const bookingServiceTitle = (() => {
    const rawName = ticket.sub_unit?.nama_layanan || ticket.booking?.tipe || 'Aset';
    if (/^peminjaman\s+/i.test(rawName)) {
      return rawName;
    }
    if (/^penggunaan\s+/i.test(rawName)) {
      return rawName.replace(/^penggunaan\s+/i, 'Peminjaman ');
    }
    return `Peminjaman ${rawName}`;
  })();

  const isBookingPast = Boolean(
    ticket.booking?.tanggal_selesai && new Date(ticket.booking.tanggal_selesai) <= new Date()
  );
  const canCancelBooking = Boolean(
    ticket.booking &&
    !['dibatalkan', 'reject', 'selesai'].includes(ticket.booking.status) &&
    !isBookingPast
  );

  // SAFETY: general_attachments is initialized as an empty array of uploaded File instances.
  const { data: statusData, setData: setStatusData, post: postStatus, processing: processingStatus, errors: errorsStatus, reset: resetStatus } = useForm({ status: '', catatan: '', general_attachments: [] as File[], _method: 'patch' });
  const { data: priorityData, setData: setPriorityData, patch: patchPriority, processing: processingPriority, errors: errorsPriority } = useForm({ priority: ticket.priority || '' });
  const { data: assignData, setData: setAssignData, errors: errorsAssign } = useForm({ assigned_admin_id: ticket.assigned_admin_id || '' });

  // SAFETY: Look up ticket transitions by status using keyof; fallback to empty array if unknown.
  const transitions = (ticket?.status ? validTransitions[ticket.status as keyof typeof validTransitions] : undefined) || [];

  useEffect(() => {
    setAssignData('assigned_admin_id', ticket.assigned_admin_id || '');
  }, [ticket.assigned_admin_id]);

  useEffect(() => {
    setPriorityData('priority', ticket.priority || '');
  }, [ticket.priority]);

  const handlePrioritySubmit = (e: React.FormEvent) => {
    e.preventDefault();
    patchPriority(route('admin.tiket.priority', ticket.id), {
      preserveScroll: true,
    });
  };

  const handleCancelBooking = () => {
    router.patch(route('admin.tiket.cancel-booking', ticket.id), {}, {
      preserveScroll: true,
      onSuccess: () => setShowCancelBookingConfirm(false),
    });
  };

  const handleMainSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    const currentAssignedId = ticket.assigned_admin_id ? String(ticket.assigned_admin_id) : '';
    const selectedAssignedId = assignData.assigned_admin_id ? String(assignData.assigned_admin_id) : '';
    const isOperatorChanged = canAssignOperator && selectedAssignedId !== '' && selectedAssignedId !== currentAssignedId;

    const currentPriority = ticket.priority || '';
    const selectedPriority = priorityData.priority || '';
    const isPriorityChanged = selectedPriority !== '' && selectedPriority !== currentPriority;

    // Skenario 1: Ubah status dipilih
    if (statusData.status) {
      setIsSubmitting(true);
      postStatus(route('admin.tiket.status', ticket.id), {
        preserveScroll: true,
        onSuccess: () => {
          resetStatus();

          if (isOperatorChanged) {
            router.patch(route('admin.tiket.assign', ticket.id), {
              assigned_admin_id: selectedAssignedId,
            }, {
              preserveScroll: true,
              onSuccess: () => {
                if (isPriorityChanged) {
                  router.patch(route('admin.tiket.priority', ticket.id), {
                    priority: selectedPriority,
                  }, {
                    preserveScroll: true,
                    onFinish: () => setIsSubmitting(false),
                  });
                } else {
                  setIsSubmitting(false);
                }
              },
              onError: () => setIsSubmitting(false),
            });
          } else if (isPriorityChanged) {
            router.patch(route('admin.tiket.priority', ticket.id), {
              priority: selectedPriority,
            }, {
              preserveScroll: true,
              onFinish: () => setIsSubmitting(false),
            });
          } else {
            setIsSubmitting(false);
          }
        },
        onError: () => setIsSubmitting(false),
      });

      return;
    }

    // Skenario 2: Status tidak diubah, tapi Operator diubah
    if (isOperatorChanged) {
      setIsSubmitting(true);
      router.patch(route('admin.tiket.assign', ticket.id), {
        assigned_admin_id: selectedAssignedId,
      }, {
        preserveScroll: true,
        onSuccess: () => {
          if (isPriorityChanged) {
            router.patch(route('admin.tiket.priority', ticket.id), {
              priority: selectedPriority,
            }, {
              preserveScroll: true,
              onFinish: () => setIsSubmitting(false),
            });
          } else {
            setIsSubmitting(false);
          }
        },
        onError: () => setIsSubmitting(false),
      });

      return;
    }

    // Skenario 3: Hanya Prioritas yang diubah
    if (isPriorityChanged) {
      setIsSubmitting(true);
      router.patch(route('admin.tiket.priority', ticket.id), {
        priority: selectedPriority,
      }, {
        preserveScroll: true,
        onFinish: () => setIsSubmitting(false),
      });

      return;
    }

    alert('Silakan pilih status, operator, atau prioritas yang ingin diubah terlebih dahulu.');
  };

 const renderFormValue = (field: any) => {
   if (field.tipe_field === 'upload_gambar' || field.tipe_field === 'upload_file') {
     const fieldAttachments = ticket.attachments?.filter((a: any) => a.field_id == field.id);

     return fieldAttachments && fieldAttachments.length > 0 ? (
       <div className="flex flex-col gap-2 mt-1">
         {fieldAttachments.map((attachment: any, idx: number) => (
           <AttachmentViewer key={idx} attachment={attachment} viewRoute="admin.tiket.view" downloadRoute="admin.tiket.download">
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

   if (field.tipe_field === 'checkbox') return value ? 'Ya' : 'Tidak';

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
   <AdminLayout title={`Detail Tiket #TKT-${formatTicketId(ticket.id)}`}>
     <Head title={`Tiket #TKT-${formatTicketId(ticket.id)}`} />

     <div className="flex items-center gap-3 mb-6">
       <Button variant="outline" size="sm" onClick={() => router.get(route('admin.tiket.index'))}>
         <ArrowLeft className="w-4 h-4 mr-1" /> Kembali
       </Button>
       <h1 className="text-2xl font-bold flex items-center gap-3">
         #TKT-{formatTicketId(ticket.id)} <StatusBadge status={ticket.status} />
       </h1>
     </div>

     <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
       <div className="lg:col-span-2 space-y-6">
         <Tabs defaultValue="informasi" className="w-full space-y-6">
           <TabsList className="grid grid-cols-3 w-full max-w-md bg-zinc-100 p-1 rounded-xl mb-6">
             <TabsTrigger value="informasi" className="text-xs font-semibold gap-1.5 rounded-lg">
               <Info className="h-3.5 w-3.5" />
               <span>Informasi</span>
             </TabsTrigger>
             <TabsTrigger value="timeline" className="text-xs font-semibold gap-1.5 rounded-lg">
               <Clock className="h-3.5 w-3.5" />
               <span>Jejak Tiket</span>
             </TabsTrigger>
             <TabsTrigger value="lampiran" className="text-xs font-semibold gap-1.5 rounded-lg">
               <Paperclip className="h-3.5 w-3.5" />
               <span>Lampiran</span>
             </TabsTrigger>
           </TabsList>

           {/* TAB 1: INFORMASI */}
           <TabsContent value="informasi" className="space-y-6 mt-0">
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
           </TabsContent>

           {/* TAB 2: TIMELINE */}
           <TabsContent value="timeline" className="space-y-6 mt-0">
             <Card>
               <CardHeader><CardTitle className="flex items-center gap-2"><FileText className="w-5 h-5" /> Timeline</CardTitle></CardHeader>
               <CardContent>
                 {ticket.logs?.length > 0 ? (
                   <TicketTimeline logs={ticket.logs} downloadRoute="admin.tiket.download" />
                 ) : (
                   <p className="text-xs text-slate-500">Belum ada aktivitas timeline.</p>
                 )}
               </CardContent>
             </Card>
           </TabsContent>

           {/* TAB 3: LAMPIRAN */}
           <TabsContent value="lampiran" className="space-y-6 mt-0">
             <Card>
               <CardHeader><CardTitle className="flex items-center gap-2"><Paperclip className="w-5 h-5" /> Daftar Lampiran Tiket</CardTitle></CardHeader>
               <CardContent>
                 {ticket.attachments?.length > 0 ? (
                   <TicketAttachmentList attachments={ticket.attachments} downloadRoute="admin.tiket.download" grouped={true} />
                 ) : (
                   <p className="text-xs text-slate-500">Tidak ada lampiran file pada tiket ini.</p>
                 )}
               </CardContent>
             </Card>
           </TabsContent>
         </Tabs>
       </div>

       <div className="space-y-6">
              {ticket.booking && (
                <Card className="border-blue-200 bg-blue-50/20">
                  <CardHeader className="pb-3">
                    <CardTitle className="text-base flex items-center justify-between">
                      <span className="flex items-center gap-2">
                        <Clock className="w-4 h-4 text-blue-600" /> {bookingServiceTitle}
                      </span>
                      <span className={`text-xs px-2.5 py-0.5 rounded-full font-bold uppercase ${
                        ['dibatalkan', 'reject'].includes(ticket.booking.status) ? 'bg-red-100 text-red-700' :
                        ticket.booking.status === 'selesai' ? 'bg-slate-100 text-slate-700' :
                        'bg-emerald-100 text-emerald-700'
                      }`}>
                        {ticket.booking.status}
                      </span>
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-3 text-sm">
                    <div>
                      <span className="text-xs text-slate-500">Aset:</span>
                      <p className="font-semibold text-slate-800">{ticket.booking.nama_aset} ({ticket.booking.tipe})</p>
                    </div>
                    <div>
                      <span className="text-xs text-slate-500">Jadwal Penggunaan:</span>
                      <p className="text-xs font-medium text-slate-700">
                        {formatDateId(ticket.booking.tanggal_mulai)} s/d {formatDateId(ticket.booking.tanggal_selesai)}
                      </p>
                    </div>

                    {canCancelBooking && (
                      <div className="pt-2 border-t border-blue-100">
                        <Button
                          type="button"
                          variant="destructive"
                          size="sm"
                          className="w-full text-xs flex items-center justify-center gap-1.5"
                          onClick={() => setShowCancelBookingConfirm(true)}
                        >
                          <XCircle className="w-3.5 h-3.5" /> Batalkan {bookingServiceTitle}
                        </Button>
                      </div>
                    )}
                  </CardContent>
                </Card>
              )}

              <ConfirmDialog
                open={showCancelBookingConfirm}
                onOpenChange={setShowCancelBookingConfirm}
                title={`Batalkan ${bookingServiceTitle}?`}
                message={`${bookingServiceTitle} ini akan dibatalkan seketika dan aset akan langsung berstatus 'Tersedia' di Live Monitor. Yakin ingin membatalkan?`}
                confirmText="Ya, Batalkan Booking"
                cancelText="Tutup"
                onConfirm={handleCancelBooking}
              />

              {ticket.sub_unit?.wajib_kembali && (
                <>
                  <Card className="border-emerald-200 bg-emerald-50/40">
                    <CardHeader className="pb-3">
                      <CardTitle className="text-sm font-semibold flex items-center justify-between">
                        <span className="flex items-center gap-2">
                          <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                          Pengembalian Alat
                        </span>
                        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                          isReturned ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'
                        }`}>
                          {isReturned ? 'Sudah Dikembalikan' : 'Belum Dikembalikan'}
                        </span>
                      </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm pt-0">
                      <p className="text-xs text-slate-600">
                        {isReturned
                          ? `Alat telah ditandai dikembalikan${ticket.dikembalikan_at ? ` pada ${formatDateId(ticket.dikembalikan_at)}` : ''}.`
                          : ticket.status === 'solve'
                            ? 'Tiket telah selesai. Konfirmasi jika peminjam telah mengembalikan alat.'
                            : 'Alat masih dalam masa peminjaman. Tombol pengembalian aktif saat tiket selesai.'}
                      </p>

                      {isReturned && (
                        <div className="pt-2 border-t border-emerald-100 space-y-1.5 text-xs">
                          <div className="flex items-center justify-between">
                            <span className="text-slate-500">Kondisi saat kembali:</span>
                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold ${
                              (ticket.kondisi_kembali || kondisiKembali) === 'rusak'
                                ? 'bg-rose-100 text-rose-800'
                                : (ticket.kondisi_kembali || kondisiKembali) === 'tidak_lengkap'
                                ? 'bg-amber-100 text-amber-800'
                                : 'bg-emerald-100 text-emerald-800'
                            }`}>
                              {(ticket.kondisi_kembali || kondisiKembali) === 'rusak' ? 'Rusak' : (ticket.kondisi_kembali || kondisiKembali) === 'tidak_lengkap' ? 'Kurang Lengkap' : 'Baik (Normal)'}
                            </span>
                          </div>
                          {(ticket.catatan_kembali || catatanKembali) && (
                            <p className="text-[11px] text-slate-600 bg-white/70 p-2 rounded border border-emerald-100">
                              "{ticket.catatan_kembali || catatanKembali}"
                            </p>
                          )}
                        </div>
                      )}

                      {ticket.status === 'solve' && !isReturned && (
                        <Button
                          type="button"
                          className="w-full bg-emerald-600 hover:bg-emerald-700 text-white text-xs"
                          onClick={() => setShowKembaliModal(true)}
                        >
                          Tandai Alat Sudah Dikembalikan
                        </Button>
                      )}
                    </CardContent>
                  </Card>

                  {/* Modal Konfirmasi & Catatan Kondisi Alat */}
                  <Dialog open={showKembaliModal} onOpenChange={setShowKembaliModal}>
                    <DialogContent className="max-w-md">
                      <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base font-semibold">
                          <CheckCircle2 className="w-5 h-5 text-emerald-600" />
                          Pemeriksaan Pengembalian Alat
                        </DialogTitle>
                      </DialogHeader>
                      <div className="space-y-4 pt-2">
                        <div>
                          <label className="text-xs font-semibold text-slate-700 block mb-1.5">
                            Kondisi Fisik & Kelengkapan <span className="text-red-500">*</span>
                          </label>
                          <div className="grid grid-cols-3 gap-2">
                            <button
                              type="button"
                              onClick={() => setKondisiKembali('baik')}
                              className={`p-2.5 rounded-lg border text-xs font-medium flex flex-col items-center gap-1 transition-all ${
                                kondisiKembali === 'baik'
                                  ? 'bg-emerald-50 border-emerald-500 text-emerald-800 ring-2 ring-emerald-500/20 shadow-xs'
                                  : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
                              }`}
                            >
                              <span className="text-base">🟢</span>
                              <span>Baik (Normal)</span>
                            </button>
                            <button
                              type="button"
                              onClick={() => setKondisiKembali('tidak_lengkap')}
                              className={`p-2.5 rounded-lg border text-xs font-medium flex flex-col items-center gap-1 transition-all ${
                                kondisiKembali === 'tidak_lengkap'
                                  ? 'bg-amber-50 border-amber-500 text-amber-800 ring-2 ring-amber-500/20 shadow-xs'
                                  : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
                              }`}
                            >
                              <span className="text-base">🟡</span>
                              <span>Kurang Lengkap</span>
                            </button>
                            <button
                              type="button"
                              onClick={() => setKondisiKembali('rusak')}
                              className={`p-2.5 rounded-lg border text-xs font-medium flex flex-col items-center gap-1 transition-all ${
                                kondisiKembali === 'rusak'
                                  ? 'bg-rose-50 border-rose-500 text-rose-800 ring-2 ring-rose-500/20 shadow-xs'
                                  : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
                              }`}
                            >
                              <span className="text-base">🔴</span>
                              <span>Rusak</span>
                            </button>
                          </div>
                        </div>

                        <div>
                          <label className="text-xs font-semibold text-slate-700 block mb-1">
                            Catatan Pemeriksaan <span className="text-slate-400 font-normal">(Opsional)</span>
                          </label>
                          <textarea
                            rows={3}
                            value={catatanKembali}
                            onChange={e => setCatatanKembali(e.target.value)}
                            placeholder="Contoh: Kabel lengkap dan tas bersih, atau ada goresan halus..."
                            className="w-full text-xs rounded-lg border border-slate-200 p-2.5 focus:outline-none focus:ring-1 focus:ring-primary"
                          />
                        </div>

                        <div className="flex items-center justify-end gap-2 pt-2 border-t">
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setShowKembaliModal(false)}
                          >
                            Batal
                          </Button>
                          <Button
                            type="button"
                            size="sm"
                            className="bg-emerald-600 hover:bg-emerald-700 text-white"
                            disabled={isSubmittingKembali}
                            onClick={() => {
                              setIsSubmittingKembali(true);
                              try {
                                const url = `/admin/tiket/${ticket.id}/kembalikan-alat`;
                                router.patch(
                                  url,
                                  {
                                    kondisi_kembali: kondisiKembali,
                                    catatan_kembali: catatanKembali,
                                  },
                                  {
                                    preserveScroll: true,
                                    onSuccess: (page: any) => {
                                      if (page?.props?.flash?.error) {
                                        setIsSubmittingKembali(false);
                                        Swal.fire({
                                          title: 'Gagal Menyimpan',
                                          text: page.props.flash.error,
                                          icon: 'error',
                                          confirmButtonColor: '#ef4444',
                                        });
                                      } else {
                                        setIsReturned(true);
                                        setShowKembaliModal(false);
                                        setIsSubmittingKembali(false);
                                        Swal.fire({
                                          title: 'Berhasil!',
                                          text: 'Pengembalian alat berhasil disimpan.',
                                          icon: 'success',
                                          confirmButtonColor: '#059669',
                                          timer: 2000,
                                          showConfirmButton: false,
                                        });
                                      }
                                    },
                                    onError: (errs) => {
                                      setIsSubmittingKembali(false);
                                      const msg = Object.values(errs)[0] || 'Gagal menyimpan status pengembalian.';
                                      Swal.fire({
                                        title: 'Gagal Menyimpan',
                                        text: String(msg),
                                        icon: 'error',
                                        confirmButtonColor: '#ef4444',
                                      });
                                    },
                                    onFinish: () => {
                                      setIsSubmittingKembali(false);
                                    },
                                  }
                                );
                              } catch (err: any) {
                                setIsSubmittingKembali(false);
                                Swal.fire({
                                  title: 'Terjadi Kesalahan',
                                  text: err?.message || 'Gagal mengirim permintaan.',
                                  icon: 'error',
                                  confirmButtonColor: '#ef4444',
                                });
                              }
                            }}
                          >
                            {isSubmittingKembali ? 'Menyimpan...' : 'Simpan Pengembalian'}
                          </Button>
                        </div>
                      </div>
                    </DialogContent>
                  </Dialog>
                </>
              )}

              <Card>
                <CardHeader>
                  <CardTitle>Aksi Tiket</CardTitle>
                </CardHeader>
                <CardContent className="space-y-5">
                  <form onSubmit={handleMainSubmit} className="space-y-4">
                    <div className="space-y-2">
                      <span className="text-sm text-slate-500">Status Saat Ini:</span>
                      <div><StatusBadge status={ticket.status} /></div>
                    </div>

                    {transitions.length > 0 ? (
                      <>
                        <div className="space-y-2">
                          <label className="text-sm font-medium">Ubah Status</label>
                          <select
                            className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                            value={statusData.status}
                            onChange={e => setStatusData('status', e.target.value)}
                          >
                            <option value="">Pilih status baru (opsional jika hanya tugaskan operator)</option>
                            {transitions.map((s: string) => (
                              <option key={s} value={s}>
                                {
                                  // SAFETY: Look up human status label by status code using keyof; fallback to raw status code.
                                  statusLabels[s as keyof typeof statusLabels] || s
                                }
                              </option>
                            ))}
                          </select>
                          {errorsStatus.status && <p className="text-red-500 text-sm">{errorsStatus.status}</p>}
                        </div>

                        {canAssignOperator && (
                          <div className="space-y-2">
                            <label className="text-sm font-medium">
                              Tugaskan ke Operator <span className="text-xs text-slate-400 font-normal">(Opsional)</span>
                            </label>
                            <select
                              className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                              value={assignData.assigned_admin_id}
                              onChange={e => setAssignData('assigned_admin_id', e.target.value)}
                            >
                              <option value="">-- Pilih Operator --</option>
                              {operators?.map((op: any) => (
                                <option key={op.id} value={op.id}>{op.name || op.username}</option>
                              ))}
                            </select>
                            {(errorsAssign.assigned_admin_id || pageErrors?.assigned_admin_id) && (
                              <p className="text-red-500 text-sm">{errorsAssign.assigned_admin_id || pageErrors?.assigned_admin_id}</p>
                            )}
                            {ticket.assigned_admin && (
                              <p className="text-xs text-slate-500">
                                Saat ini ditugaskan ke: <span className="font-semibold text-slate-700">{ticket.assigned_admin.name || ticket.assigned_admin.username}</span>
                              </p>
                            )}
                          </div>
                        )}

                        <div className="space-y-2">
                          <label className="text-sm font-medium">
                            Catatan Admin {statusData.status && <span className="text-red-500">*</span>}
                          </label>
                          <textarea
                            className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm min-h-[90px]"
                            value={statusData.catatan}
                            onChange={e => setStatusData('catatan', e.target.value)}
                            placeholder={statusData.status ? "Wajib diisi saat ubah status..." : "Catatan admin (opsional jika hanya penugasan operator)..."}
                          />
                          {errorsStatus.catatan && <p className="text-red-500 text-sm">{errorsStatus.catatan}</p>}
                        </div>

                        <div className="space-y-2">
                          <label className="text-sm font-medium">Lampiran Tambahan (Opsional)</label>
                          <p className="text-xs text-slate-500">Maks. 3 file, 3MB/file (JPG, PNG, PDF, DOC, DOCX).</p>
                          <div className="flex items-center gap-3">
                            <label className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100 text-sm font-medium cursor-pointer transition-colors border border-blue-200">
                              <Paperclip className="w-4 h-4" />
                              <span>Pilih Berkas</span>
                              <input
                                type="file"
                                multiple
                                accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"
                                className="hidden"
                                onChange={e => {
                                  const files = Array.from(e.target.files || []);

                                  if (statusData.general_attachments.length + files.length > 3) {
                                    alert('Maksimal hanya 3 lampiran.');

                                    return;
                                  }

                                  const validFiles = files.filter(f => {
                                    if (f.size > 3 * 1024 * 1024) { alert(`${f.name} melebihi 3MB.`);

 return false; }

                                    return true;
                                  });

                                  setStatusData('general_attachments', [...statusData.general_attachments, ...validFiles]);
                                  e.target.value = '';
                                }}
                              />
                            </label>
                            <span className="text-xs text-slate-500">
                              {statusData.general_attachments.length > 0
                                ? `${statusData.general_attachments.length} berkas dipilih`
                                : 'Belum ada berkas yang dipilih'}
                            </span>
                          </div>
                          {errorsStatus.general_attachments && <p className="text-red-500 text-sm">{errorsStatus.general_attachments}</p>}
                          {statusData.general_attachments.length > 0 && (
                            <div className="mt-2 space-y-2">
                              {statusData.general_attachments.map((file, idx) => (
                                <div key={idx} className="flex justify-between items-center text-sm p-2 bg-slate-50 border rounded">
                                  <span className="truncate max-w-[200px]">{file.name}</span>
                                  <div className="flex items-center gap-3">
                                    {file.type.startsWith('image/') && (
                                      <button type="button" onClick={() => { setFileToEdit({file, index: idx, form: 'admin'}); setEditorOpen(true); }} className="text-blue-600 hover:underline flex items-center gap-1"><Edit2 className="w-4 h-4"/> Edit</button>
                                    )}
                                    <button type="button" onClick={() => setStatusData('general_attachments', statusData.general_attachments.filter((_, i) => i !== idx))} className="text-red-500 hover:underline">Hapus</button>
                                  </div>
                                </div>
                              ))}
                            </div>
                          )}
                        </div>

                        <Button type="submit" className="w-full" disabled={isSubmitting || processingStatus}>
                          {isSubmitting || processingStatus ? 'Menyimpan...' : 'Simpan'}
                        </Button>
                      </>
                    ) : (
                      <>
                        <p className="text-sm text-slate-500">Tidak ada transisi status yang tersedia.</p>
                        {canAssignOperator && (
                          <div className="space-y-3">
                            <div className="space-y-2">
                              <label className="text-sm font-medium">Tugaskan ke Operator</label>
                              <select
                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                                value={assignData.assigned_admin_id}
                                onChange={e => setAssignData('assigned_admin_id', e.target.value)}
                              >
                                <option value="">-- Pilih Operator --</option>
                                {operators?.map((op: any) => (
                                  <option key={op.id} value={op.id}>{op.name || op.username}</option>
                                ))}
                              </select>
                              {(errorsAssign.assigned_admin_id || pageErrors?.assigned_admin_id) && (
                                <p className="text-red-500 text-sm">{errorsAssign.assigned_admin_id || pageErrors?.assigned_admin_id}</p>
                              )}
                              {ticket.assigned_admin && (
                                <p className="text-xs text-slate-500">
                                  Saat ini ditugaskan ke: <span className="font-semibold text-slate-700">{ticket.assigned_admin.name || ticket.assigned_admin.username}</span>
                                </p>
                              )}
                            </div>
                            <Button type="submit" className="w-full" disabled={isSubmitting}>
                              {isSubmitting ? 'Menyimpan...' : 'Simpan Penugasan'}
                            </Button>
                          </div>
                        )}
                      </>
                    )}
                  </form>

                  {/* Divider & SLA Priority Section */}
                  <div className="pt-4 border-t border-slate-200">
                    <form onSubmit={handlePrioritySubmit} className="space-y-3">
                      <div className="space-y-2">
                        <label className="text-sm font-medium">Prioritas SLA</label>
                        <select
                          className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                          value={priorityData.priority}
                          onChange={e => setPriorityData('priority', e.target.value)}
                        >
                          <option value="">Pilih Prioritas (Default: Sedang)</option>
                          <option value="Rendah">Rendah</option>
                          <option value="Sedang">Sedang</option>
                          <option value="Tinggi">Tinggi</option>
                          <option value="Urgen">Urgen</option>
                        </select>
                        {errorsPriority.priority && <p className="text-red-500 text-sm">{errorsPriority.priority}</p>}
                      </div>
                      <Button
                        type="submit"
                        variant="secondary"
                        className="w-full"
                        disabled={processingPriority || isSubmitting}
                      >
                        {processingPriority ? 'Menyimpan...' : 'Set Prioritas'}
                      </Button>
                    </form>
                  </div>
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

                   <div className="space-y-1">
                     <div className="flex items-center justify-between">
                       <span className="text-sm text-slate-500">SLA Respon</span>
                       {!ticket.sla_tracking.sla_response_deadline && !ticket.sla_tracking.responded_at ? (
                         <span className="inline-flex items-center gap-1 text-xs text-slate-500 font-medium">
                           <Clock className="w-3 h-3" /> Belum Dimulai
                         </span>
                       ) : ticket.sla_tracking.responded_at ? (
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
                     {ticket.sla_tracking.sla_response_deadline ? (
                       <p className="text-xs text-slate-400">
                         Deadline: {formatDateId(ticket.sla_tracking.sla_response_deadline)}
                       </p>
                     ) : (
                       <p className="text-xs text-slate-400">
                         SLA akan dimulai saat status diubah ke Proses
                       </p>
                     )}
                     {ticket.sla_tracking.responded_at && (
                       <div className="text-xs mt-1 text-slate-500">
                         Direspon: {formatDateId(ticket.sla_tracking.responded_at)}
                       </div>
                     )}
                   </div>

                   <div className="space-y-1">
                     <div className="flex items-center justify-between">
                       <span className="text-sm text-slate-500">SLA Penyelesaian</span>
                       {!ticket.sla_tracking.sla_resolution_deadline && !ticket.sla_tracking.resolved_at ? (
                         <span className="inline-flex items-center gap-1 text-xs text-slate-500 font-medium">
                           <Clock className="w-3 h-3" /> Belum Dimulai
                         </span>
                       ) : ticket.sla_tracking.resolved_at ? (
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
                     {ticket.sla_tracking.sla_resolution_deadline ? (
                       <p className="text-xs text-slate-400">
                         Deadline: {formatDateId(ticket.sla_tracking.sla_resolution_deadline)}
                       </p>
                     ) : (
                       <p className="text-xs text-slate-400">
                         SLA akan dimulai saat status diubah ke Proses
                       </p>
                     )}
                     {ticket.sla_tracking.resolved_at && (
                       <div className="text-xs mt-1 text-slate-500">
                         Diselesaikan: {formatDateId(ticket.sla_tracking.resolved_at)}
                       </div>
                     )}
                   </div>

                   {ticket.sla_tracking.paused_at && (
                     <div className="flex items-center gap-2 p-2 bg-amber-50 rounded-md border border-amber-200">
                       <PauseCircle className="w-4 h-4 text-amber-600" />
                       <span className="text-xs text-amber-700 font-medium">SLA sedang di-pause</span>
                     </div>
                   )}

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
     
     {fileToEdit && (
       <ImageEditorModal
         isOpen={editorOpen}
         onClose={() => setEditorOpen(false)}
         imageFile={fileToEdit.file}
         onSave={(editedFile) => {
           if (fileToEdit.form === 'admin') {
             const newFiles = [...statusData.general_attachments];
             newFiles[fileToEdit.index] = editedFile;
             setStatusData('general_attachments', newFiles);
           }

           setEditorOpen(false);
         }}
       />
     )}
   </AdminLayout>
 );
}
