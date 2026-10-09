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
import { XCircle, Eye, CheckCircle2, Edit2, Clock, Paperclip, Info } from 'lucide-react';
import { CsatDialog } from '@/Components/CsatDialog';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import ImageEditorModal from '@/Components/FormBuilder/ImageEditorModal';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/Components/ui/tabs';
import Swal from 'sweetalert2';


interface DetailProps {
 ticket: any;
 formFields: any[];
 maxRevisions: number;
}

export default function Detail({ ticket, formFields, maxRevisions }: DetailProps) {

 // SAFETY: general_attachments holds uploaded File instances for replies.
 const { data: replyData, setData: setReplyData, post: postReply, processing: processingReply, errors: errorsReply, reset: resetReply } = useForm({ catatan: '', general_attachments: [] as File[], _method: 'post' });
 // SAFETY: general_attachments holds uploaded File instances for revision submissions.
 const { data: revData, setData: setRevData, post: postRev, processing: processingRev, errors: errorsRev, reset: resetRev } = useForm({ catatan: '', general_attachments: [] as File[], _method: 'post' });
 const [showRevForm, setShowRevForm] = useState(false);
 const [showConfirm, setShowConfirm] = useState(false);
 const [editorOpen, setEditorOpen] = useState(false);
 const [fileToEdit, setFileToEdit] = useState<{file: File, index: number, form: 'reply' | 'rev'} | null>(null);
 const [isReturned, setIsReturned] = useState(Boolean(ticket.dikembalikan_at));
 const [showKembaliModal, setShowKembaliModal] = useState(false);
 const [kondisiKembali, setKondisiKembali] = useState<'baik' | 'rusak' | 'tidak_lengkap'>('baik');
 const [catatanKembali, setCatatanKembali] = useState('');
 const [isSubmittingKembali, setIsSubmittingKembali] = useState(false);
 const showCsat = ['solve', 'selesai'].includes(String(ticket.status || '').toLowerCase());
 const isBooking = Boolean(ticket.booking);
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
   isBooking &&
   ticket.booking?.tanggal_selesai &&
   new Date(ticket.booking.tanggal_selesai) <= new Date()
 );
 const bookingNotStarted = isBooking && ticket.booking?.tanggal_mulai && new Date(ticket.booking.tanggal_mulai) > new Date();
 const bookingActive = isBooking && !['dibatalkan', 'reject', 'selesai'].includes(ticket.booking?.status) && !isBookingPast;
 const canCancel = !isBookingPast && (ticket.status === 'open' || (bookingNotStarted && bookingActive)) && ticket.status !== 'dibatalkan' && ticket.status !== 'reject';

 const handleCancel = () => {
   router.patch(route('tiket.batal', ticket.id));
 };

 const handleKembalikanAlat = () => {
   setIsSubmittingKembali(true);
   router.post(`/tiket/${ticket.id}/kembalikan-alat`, {
     kondisi_kembali: kondisiKembali,
     catatan_kembali: catatanKembali,
   }, {
     preserveScroll: true,
     onSuccess: (page: any) => {
       setIsSubmittingKembali(false);
       if (page?.props?.flash?.error) {
         setIsReturned(false);
         Swal.fire({
           title: 'Gagal Menyimpan',
           text: page.props.flash.error,
           icon: 'error',
           confirmButtonColor: '#ef4444',
         });
       } else {
         setIsReturned(true);
         setShowKembaliModal(false);
         Swal.fire({
           title: 'Berhasil!',
           text: 'Alat telah ditandai sudah dikembalikan.',
           icon: 'success',
           confirmButtonColor: '#059669',
           timer: 2000,
           showConfirmButton: false,
         });
       }
     },
     onError: (errs) => {
       setIsSubmittingKembali(false);
       const msg = Object.values(errs)[0] || 'Terjadi kesalahan saat memproses pengembalian.';
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
   });
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
         title={isBooking ? `Batalkan ${bookingServiceTitle}?` : "Batalkan Tiket?"}
         message={isBooking ? `${bookingServiceTitle} ini akan dibatalkan dan akan langsung dibebaskan di Live Monitor. Yakin ingin membatalkan?` : "Tiket yang dibatalkan tidak bisa dikembalikan lagi. Yakin ingin membatalkan?"}
         confirmText="Ya, Batalkan"
         cancelText="Tidak"
         onConfirm={handleCancel}
       />

       {ticket.status === 'solve' && ticket.sub_unit?.is_revision_enabled && !ticket.is_result_accepted && (
         <div className="mb-6 p-4 bg-blue-50 border border-blue-200 text-blue-700 rounded-lg flex flex-col gap-4">
           <div>
             <p className="font-semibold text-lg flex items-center gap-2"><Eye className="w-5 h-5"/> Review Hasil</p>
             <p className="text-sm">Tiket ini sudah diselesaikan. Silakan periksa hasil pekerjaan. Kamu dapat menerima hasil akhir atau meminta revisi. Sisa revisi kamu: {maxRevisions - (ticket.revision_count || 0)} kali.</p>
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

                           if (revData.general_attachments.length + files.length > 3) { alert('Maksimal hanya 3 lampiran.');

 return; }

                           const validFiles = files.filter(f => { if (f.size > 3 * 1024 * 1024) { alert(`${f.name} melebihi 3MB.`);

 return false; }

 return true; });

                           setRevData('general_attachments', [...revData.general_attachments, ...validFiles]);
                           e.target.value = '';
                         }}
                       />
                     </label>
                     <span className="text-xs text-slate-500">
                       {revData.general_attachments.length > 0
                         ? `${revData.general_attachments.length} berkas dipilih`
                         : 'Belum ada berkas yang dipilih'}
                     </span>
                   </div>
                   {errorsRev.general_attachments && <p className="text-red-500 text-sm">{errorsRev.general_attachments}</p>}
                   {revData.general_attachments.length > 0 && (
                     <div className="mt-2 space-y-2">
                       {revData.general_attachments.map((file, idx) => (
                         <div key={idx} className="flex justify-between items-center text-sm p-2 bg-slate-50 border rounded">
                           <span className="truncate max-w-[200px]">{file.name}</span>
                           <div className="flex items-center gap-3">
                             {file.type.startsWith('image/') && (
                               <button type="button" onClick={() => { setFileToEdit({file, index: idx, form: 'rev'}); setEditorOpen(true); }} className="text-blue-600 hover:underline flex items-center gap-1"><Edit2 className="w-4 h-4"/> Edit</button>
                             )}
                             <button type="button" onClick={() => setRevData('general_attachments', revData.general_attachments.filter((_, i) => i !== idx))} className="text-red-500 hover:underline">Hapus</button>
                           </div>
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
           <p className="font-medium">Kamu telah menerima hasil akhir tiket ini.</p>
         </div>
       )}

       {ticket.sub_unit?.wajib_kembali && (
         <div className="mb-6 p-4 rounded-lg border flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white shadow-sm">
           <div className="flex items-center gap-3">
             <div className={`p-2 rounded-full ${isReturned ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}`}>
               <CheckCircle2 className="w-5 h-5" />
             </div>
             <div>
               <h4 className="font-semibold text-sm text-slate-800">Status Pengembalian Alat</h4>
               <p className="text-xs text-slate-500">
                 {isReturned
                   ? `Alat sudah dikembalikan${ticket.dikembalikan_at ? ` pada ${formatDateId(ticket.dikembalikan_at)}` : ''}`
                   : ticket.status === 'solve'
                     ? 'Tiket telah selesai. Harap konfirmasi jika alat sudah dikembalikan.'
                     : 'Alat masih dalam masa peminjaman.'}
               </p>
               {isReturned && ticket.kondisi_kembali && (
                 <div className="mt-1.5 flex items-center gap-2 text-xs">
                   <span className="text-slate-500">Kondisi:</span>
                   <span className={`font-semibold px-2 py-0.5 rounded text-[11px] ${
                     ticket.kondisi_kembali === 'rusak'
                       ? 'bg-rose-100 text-rose-800'
                       : ticket.kondisi_kembali === 'tidak_lengkap'
                       ? 'bg-amber-100 text-amber-800'
                       : 'bg-emerald-100 text-emerald-800'
                   }`}>
                     {ticket.kondisi_kembali === 'rusak' ? 'Rusak' : ticket.kondisi_kembali === 'tidak_lengkap' ? 'Kurang Lengkap' : 'Baik (Normal)'}
                   </span>
                   {ticket.catatan_kembali && (
                     <span className="text-slate-600 italic">"{ticket.catatan_kembali}"</span>
                   )}
                 </div>
               )}
             </div>
           </div>
           {ticket.status === 'solve' && !isReturned && (
             <Button
               type="button"
               variant="default"
               className="bg-emerald-600 hover:bg-emerald-700 text-white font-medium shadow-xs"
               onClick={() => setShowKembaliModal(true)}
             >
               Alat Sudah Dikembalikan
             </Button>
           )}
           {isReturned && (
             <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800">
               Sudah Dikembalikan
             </span>
           )}
         </div>
       )}

       {/* Modal Pemeriksaan & Pemilihan Kondisi Alat oleh User */}
       <Dialog open={showKembaliModal} onOpenChange={setShowKembaliModal}>
         <DialogContent className="max-w-md">
           <DialogHeader>
             <DialogTitle className="flex items-center gap-2 text-base font-semibold">
               <CheckCircle2 className="w-5 h-5 text-emerald-600" />
               Konfirmasi Pengembalian Alat
             </DialogTitle>
           </DialogHeader>
           <div className="space-y-4 pt-2">
             <div>
               <label className="text-xs font-semibold text-slate-700 block mb-1.5">
                 Kondisi Fisik & Kelengkapan Alat <span className="text-red-500">*</span>
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
                 Catatan Kondisi / Kelengkapan <span className="text-slate-400 font-normal">(Opsional)</span>
               </label>
               <textarea
                 rows={3}
                 value={catatanKembali}
                 onChange={e => setCatatanKembali(e.target.value)}
                 placeholder="Contoh: Alat lengkap dan berfungsi baik, atau ada aksesoris yang tertinggal..."
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
                 onClick={handleKembalikanAlat}
               >
                 {isSubmittingKembali ? 'Menyimpan...' : 'Simpan Pengembalian'}
               </Button>
             </div>
           </div>
         </DialogContent>
       </Dialog>

       {/* Tabs Navigation for Ticket Details */}
       <Tabs defaultValue="informasi" className="w-full space-y-6">
         <TabsList className="grid grid-cols-4 w-full max-w-lg bg-zinc-100 p-1 rounded-xl">
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
         <TabsContent value="informasi" className="space-y-6">
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
                       <textarea className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm min-h-[100px]" value={replyData.catatan} onChange={e => setReplyData('catatan', e.target.value)} placeholder="Tulis balasan kamu di sini..." required />
                       {errorsReply.catatan && <p className="text-red-500 text-sm">{errorsReply.catatan}</p>}
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

                               if (replyData.general_attachments.length + files.length > 3) {
                                 alert('Maksimal hanya 3 lampiran.');

                                 return;
                               }

                               const validFiles = files.filter(f => {
                                 if (f.size > 3 * 1024 * 1024) { alert(`${f.name} melebihi 3MB.`);

 return false; }

                                 return true;
                               });

                               setReplyData('general_attachments', [...replyData.general_attachments, ...validFiles]);
                               e.target.value = '';
                             }}
                           />
                         </label>
                         <span className="text-xs text-slate-500">
                           {replyData.general_attachments.length > 0
                             ? `${replyData.general_attachments.length} berkas dipilih`
                             : 'Belum ada berkas yang dipilih'}
                         </span>
                       </div>
                       {errorsReply.general_attachments && <p className="text-red-500 text-sm">{errorsReply.general_attachments}</p>}
                       {replyData.general_attachments.length > 0 && (
                         <div className="mt-2 space-y-2">
                           {replyData.general_attachments.map((file, idx) => (
                             <div key={idx} className="flex justify-between items-center text-sm p-2 bg-slate-50 border rounded">
                               <span className="truncate max-w-[200px]">{file.name}</span>
                               <div className="flex items-center gap-3">
                                 {file.type.startsWith('image/') && (
                                   <button type="button" onClick={() => { setFileToEdit({file, index: idx, form: 'reply'}); setEditorOpen(true); }} className="text-blue-600 hover:underline flex items-center gap-1"><Edit2 className="w-4 h-4"/> Edit</button>
                                 )}
                                 <button type="button" onClick={() => setReplyData('general_attachments', replyData.general_attachments.filter((_, i) => i !== idx))} className="text-red-500 hover:underline">Hapus</button>
                               </div>
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
         </TabsContent>

         {/* TAB 2: TIMELINE */}
         <TabsContent value="timeline">
           <Card>
             <CardHeader>
               <CardTitle className="flex items-center gap-2">
                 <Clock className="h-5 w-5" />
                 Timeline Respon Tiket
               </CardTitle>
             </CardHeader>
             <CardContent>
               {ticket.logs?.length > 0 ? (
                 <TicketTimeline logs={ticket.logs} />
               ) : (
                 <p className="text-xs text-slate-500">Belum ada aktivitas timeline.</p>
               )}
             </CardContent>
           </Card>
         </TabsContent>

         {/* TAB 3: LAMPIRAN */}
         <TabsContent value="lampiran">
           <Card>
             <CardHeader>
               <CardTitle className="flex items-center gap-2">
                 <Paperclip className="h-5 w-5" />
                 Daftar Lampiran Tiket
               </CardTitle>
             </CardHeader>
             <CardContent>
               {ticket.attachments?.length > 0 ? (
                 <TicketAttachmentList attachments={ticket.attachments} downloadRoute="tiket.download" grouped={true} />
               ) : (
                 <p className="text-xs text-slate-500">Tidak ada lampiran file pada tiket ini.</p>
               )}
             </CardContent>
           </Card>
         </TabsContent>


       </Tabs>
     </div>
     
     {fileToEdit && (
       <ImageEditorModal
         isOpen={editorOpen}
         onClose={() => setEditorOpen(false)}
         imageFile={fileToEdit.file}
         onSave={(editedFile) => {
           if (fileToEdit.form === 'reply') {
             const newFiles = [...replyData.general_attachments];
             newFiles[fileToEdit.index] = editedFile;
             setReplyData('general_attachments', newFiles);
           } else if (fileToEdit.form === 'rev') {
             const newFiles = [...revData.general_attachments];
             newFiles[fileToEdit.index] = editedFile;
             setRevData('general_attachments', newFiles);
           }

           setEditorOpen(false);
         }}
       />
     )}
   </UserLayout>
 );
}
