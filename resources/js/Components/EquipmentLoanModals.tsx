import React, { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Check, CheckCircle2, Info, Loader2, PackageCheck, Paperclip, Search, Upload, UserCheck, X } from 'lucide-react';
import Swal from 'sweetalert2';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { formatTicketId } from '@/lib/utils';

export type EquipmentCondition = 'baik' | 'tidak_lengkap' | 'rusak';

export interface EquipmentLoanModalTarget {
  id: number;
  formatted_id?: string;
  borrowerName?: string | null;
  equipmentText?: string | null;
  scheduleText?: string | null;
  hasHandedOver?: boolean;
}

interface DefaultRecipientOption {
  type: 'admin';
  id: number;
  name: string;
  username: string;
}

interface UserRecipientOption {
  type: 'user';
  id: number;
  name: string;
  username: string;
  divisi?: string | null;
  is_requester: boolean;
}

interface RecipientOptionsResponse {
  data?: {
    default_recipient?: DefaultRecipientOption;
    options?: UserRecipientOption[];
  };
}

interface HandoverEquipmentModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  ticket: EquipmentLoanModalTarget | null;
  onSuccess?: () => void;
}

interface ReturnEquipmentModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  ticket: EquipmentLoanModalTarget | null;
  onSuccess?: () => void;
}

interface FlashResponsePage {
  props?: {
    flash?: {
      error?: string;
      success?: string;
    };
  };
}

export interface ConditionMeta {
  label: string;
  className: string;
}

const MAX_LOAN_FILES = 3;

const MAX_LOAN_FILE_BYTES = 3 * 1024 * 1024;

const ALLOWED_LOAN_EXTENSIONS = new Set(['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx']);

function formatBytesLabel(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;

  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function resolveConditionMeta(kondisi: string): ConditionMeta {
  if (kondisi === 'rusak') {
    return { label: 'Rusak', className: 'bg-rose-100 text-rose-800 border border-rose-200' };
  }

  if (kondisi === 'tidak_lengkap') {
    return { label: 'Kurang Lengkap', className: 'bg-amber-100 text-amber-800 border border-amber-200' };
  }

  return { label: 'Bagus (Baik)', className: 'bg-emerald-100 text-emerald-800 border border-emerald-200' };
}

function LoanConditionSelector({
  value,
  onChange,
  label,
}: {
  value: EquipmentCondition;
  onChange: (next: EquipmentCondition) => void;
  label: string;
}) {
  return (
    <div>
      <span className="text-xs font-semibold text-slate-700 block mb-1.5">
        {label} <span className="text-red-500">*</span>
      </span>
      <div className="grid grid-cols-3 gap-2">
        <button
          type="button"
          onClick={() => onChange('baik')}
          className={`p-2.5 rounded-lg border text-xs font-medium flex flex-col items-center gap-1 transition-all ${
            value === 'baik'
              ? 'bg-emerald-50 border-emerald-500 text-emerald-800 ring-2 ring-emerald-500/20 shadow-xs'
              : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
          }`}
        >
          <span className="text-base">🟢</span>
          <span>Bagus (Baik)</span>
        </button>
        <button
          type="button"
          onClick={() => onChange('tidak_lengkap')}
          className={`p-2.5 rounded-lg border text-xs font-medium flex flex-col items-center gap-1 transition-all ${
            value === 'tidak_lengkap'
              ? 'bg-amber-50 border-amber-500 text-amber-800 ring-2 ring-amber-500/20 shadow-xs'
              : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
          }`}
        >
          <span className="text-base">🟡</span>
          <span>Kurang Lengkap</span>
        </button>
        <button
          type="button"
          onClick={() => onChange('rusak')}
          className={`p-2.5 rounded-lg border text-xs font-medium flex flex-col items-center gap-1 transition-all ${
            value === 'rusak'
              ? 'bg-rose-50 border-rose-500 text-rose-800 ring-2 ring-rose-500/20 shadow-xs'
              : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
          }`}
        >
          <span className="text-base">🔴</span>
          <span>Rusak</span>
        </button>
      </div>
    </div>
  );
}

function LoanAttachmentUploader({
  files,
  onChange,
  inputId,
}: {
  files: File[];
  onChange: (next: File[]) => void;
  inputId: string;
}) {
  const [warning, setWarning] = useState('');

  const handleFilePick = (e: React.ChangeEvent<HTMLInputElement>) => {
    const picked = Array.from(e.target.files || []);
    e.target.value = '';

    if (picked.length === 0) return;

    const accepted: File[] = [...files];
    const issues: string[] = [];

    for (const file of picked) {
      if (accepted.length >= MAX_LOAN_FILES) {
        issues.push(`Maksimal ${MAX_LOAN_FILES} lampiran per aksi.`);
        break;
      }

      const ext = file.name.split('.').pop()?.toLowerCase() || '';

      if (!ALLOWED_LOAN_EXTENSIONS.has(ext)) {
        issues.push(`${file.name}: format tidak didukung.`);
        continue;
      }

      if (file.size > MAX_LOAN_FILE_BYTES) {
        issues.push(`${file.name}: ukuran melebihi 3 MB.`);
        continue;
      }

      accepted.push(file);
    }

    onChange(accepted);
    setWarning(issues.length > 0 ? issues[0] : '');
  };

  const removeFileAt = (idx: number) => {
    onChange(files.filter((_, i) => i !== idx));
    setWarning('');
  };

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between gap-2">
        <label htmlFor={inputId} className="text-xs font-semibold text-slate-700">
          Lampiran Foto / Dokumen{' '}
          <span className="text-slate-400 font-normal">(Opsional, maks {MAX_LOAN_FILES} file @ 3 MB)</span>
        </label>
        <span className="text-[11px] text-slate-500">
          {files.length}/{MAX_LOAN_FILES}
        </span>
      </div>

      <label
        htmlFor={inputId}
        className={`flex items-center justify-center gap-2 rounded-lg border border-dashed px-3 py-2.5 text-xs transition-colors cursor-pointer ${
          files.length >= MAX_LOAN_FILES
            ? 'border-slate-200 bg-slate-50 text-slate-400 cursor-not-allowed'
            : 'border-slate-300 bg-slate-50/70 text-slate-600 hover:border-sky-400 hover:bg-sky-50/50'
        }`}
      >
        <Upload className="w-3.5 h-3.5 shrink-0" />
        <span>
          {files.length >= MAX_LOAN_FILES
            ? 'Batas maksimal 3 file tercapai'
            : 'Pilih foto kondisi atau dokumen (.jpg, .png, .webp, .pdf, .doc, .docx)'}
        </span>
        <input
          id={inputId}
          type="file"
          multiple
          accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx"
          disabled={files.length >= MAX_LOAN_FILES}
          onChange={handleFilePick}
          className="hidden"
        />
      </label>

      {warning && (
        <p className="text-[11px] font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-2.5 py-1.5">
          {warning}
        </p>
      )}

      {files.length > 0 && (
        <div className="space-y-1.5">
          {files.map((file, idx) => (
            <div
              key={`${file.name}-${file.size}-${idx}`}
              className="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs"
            >
              <div className="flex items-center gap-2 min-w-0">
                <Paperclip className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                <span className="truncate font-medium text-slate-700" title={file.name}>
                  {file.name}
                </span>
                <span className="text-[11px] text-slate-400 shrink-0">
                  {formatBytesLabel(file.size)}
                </span>
              </div>
              <button
                type="button"
                onClick={() => removeFileAt(idx)}
                className="p-1 rounded text-slate-400 hover:text-rose-600 hover:bg-rose-50 shrink-0"
                aria-label={`Hapus ${file.name}`}
              >
                <X className="w-3.5 h-3.5" />
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function TicketLoanSummaryBox({ ticket }: { ticket: EquipmentLoanModalTarget }) {
  const displayId = ticket.formatted_id || formatTicketId(ticket.id);

  return (
    <div className="rounded-xl border border-slate-200 bg-slate-50/80 p-3 space-y-1.5 text-xs">
      <div className="flex items-center justify-between gap-2">
        <span className="text-slate-500">Tiket</span>
        <span className="font-mono font-semibold text-slate-800">#TKT-{displayId}</span>
      </div>
      {ticket.borrowerName && (
        <div className="flex items-center justify-between gap-2">
          <span className="text-slate-500">Peminjam</span>
          <span className="font-medium text-slate-800 text-right">{ticket.borrowerName}</span>
        </div>
      )}
      {ticket.equipmentText && (
        <div className="flex items-start justify-between gap-2">
          <span className="text-slate-500 shrink-0">Alat</span>
          <span className="font-medium text-slate-800 text-right">{ticket.equipmentText}</span>
        </div>
      )}
      {ticket.scheduleText && (
        <div className="flex items-center justify-between gap-2">
          <span className="text-slate-500">Waktu Pakai</span>
          <span className="font-medium text-slate-700 text-right">{ticket.scheduleText}</span>
        </div>
      )}
    </div>
  );
}

export function HandoverEquipmentModal({
  open,
  onOpenChange,
  ticket,
  onSuccess,
}: HandoverEquipmentModalProps) {
  const [selectedUserId, setSelectedUserId] = useState<string>('');
  const [recipientSearch, setRecipientSearch] = useState('');
  const [defaultRecipient, setDefaultRecipient] = useState<DefaultRecipientOption | null>(null);
  const [recipientOptions, setRecipientOptions] = useState<UserRecipientOption[]>([]);
  const [loadingRecipients, setLoadingRecipients] = useState(false);
  const [kondisiSerah, setKondisiSerah] = useState<EquipmentCondition>('baik');
  const [catatanSerah, setCatatanSerah] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    setSelectedUserId('');
    setRecipientSearch('');
    setKondisiSerah('baik');
    setCatatanSerah('');
    setFiles([]);
    setIsSubmitting(false);
  }, [open, ticket?.id]);

  useEffect(() => {
    if (!open || !ticket) return;

    let active = true;
    const delayMs = recipientSearch.trim() === '' ? 0 : 250;

    const timer = window.setTimeout(async () => {
      setLoadingRecipients(true);

      try {
        const res = await axios.get<RecipientOptionsResponse>(
          route('admin.tiket.penerima-alat-options'),
          {
            params: {
              ticket_id: ticket.id,
              search: recipientSearch.trim() || undefined,
            },
          },
        );

        if (!active) return;

        setDefaultRecipient(res.data?.data?.default_recipient ?? null);
        setRecipientOptions(res.data?.data?.options ?? []);
      } catch {
        if (!active) return;

        setRecipientOptions([]);
      } finally {
        if (active) {
          setLoadingRecipients(false);
        }
      }
    }, delayMs);

    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [open, ticket?.id, recipientSearch]);

  const normalizedSearch = recipientSearch.trim().toLowerCase();

  const visibleOptions = normalizedSearch === ''
    ? recipientOptions
    : recipientOptions.filter((opt) => {
        const haystack = `${opt.name} ${opt.username} ${opt.divisi || ''}`.toLowerCase();

        return haystack.includes(normalizedSearch);
      });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    if (!ticket || isSubmitting) return;

    setIsSubmitting(true);

    router.post(
      route('admin.tiket.serahkan-alat', ticket.id),
      {
        _method: 'patch',
        penerima_alat_user_id: selectedUserId ? Number(selectedUserId) : null,
        kondisi_serah: kondisiSerah,
        catatan_serah: catatanSerah,
        lampiran: files,
      },
      {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: (page) => {
          setIsSubmitting(false);
          // SAFETY: Inertia response props carry Laravel flash messages.
          const flashError = (page as FlashResponsePage)?.props?.flash?.error;

          if (flashError) {
            Swal.fire({
              title: 'Gagal Menyimpan',
              text: flashError,
              icon: 'error',
              confirmButtonColor: '#ef4444',
            });

            return;
          }

          onOpenChange(false);
          onSuccess?.();
          Swal.fire({
            title: 'Berhasil!',
            text: 'Serah-terima alat berhasil dicatat.',
            icon: 'success',
            confirmButtonColor: '#0284c7',
            timer: 2000,
            showConfirmButton: false,
          });
        },
        onError: (errs) => {
          setIsSubmitting(false);
          const firstMsg = Object.values(errs)[0] || 'Gagal mencatat serah-terima alat.';

          Swal.fire({
            title: 'Gagal Menyimpan',
            text: String(firstMsg),
            icon: 'error',
            confirmButtonColor: '#ef4444',
          });
        },
        onFinish: () => {
          setIsSubmitting(false);
        },
      },
    );
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-base font-semibold">
            <PackageCheck className="w-5 h-5 text-sky-600" />
            Konfirmasi Serah-Terima Alat
          </DialogTitle>
        </DialogHeader>

        {ticket && (
          <form onSubmit={handleSubmit} className="space-y-4 pt-1">
            <TicketLoanSummaryBox ticket={ticket} />

            <div className="space-y-2">
              <span className="text-xs font-semibold text-slate-700 block">
                Pengambil / Penerima Alat
              </span>

              <button
                type="button"
                onClick={() => setSelectedUserId('')}
                className={`w-full flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-left text-xs transition-all ${
                  selectedUserId === ''
                    ? 'border-sky-500 bg-sky-50 text-sky-900 ring-2 ring-sky-500/20'
                    : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                }`}
              >
                <div className="flex items-center gap-2 min-w-0">
                  <UserCheck className="w-4 h-4 text-sky-600 shrink-0" />
                  <div className="min-w-0">
                    <div className="font-semibold truncate">
                      Otomatis: {defaultRecipient?.name || 'Admin yang sedang login'} (Anda)
                    </div>
                    <div className="text-[11px] text-slate-500">
                      Nama otomatis diisi dari akun Anda yang mengklik tombol ini
                    </div>
                  </div>
                </div>
                {selectedUserId === '' && <Check className="w-4 h-4 text-sky-600 shrink-0" />}
              </button>

              <div className="rounded-lg border border-slate-200 bg-white overflow-hidden">
                <div className="flex items-center gap-2 border-b border-slate-100 px-2.5 py-1.5 bg-slate-50/60">
                  <Search className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                  <input
                    type="text"
                    value={recipientSearch}
                    onChange={(e) => setRecipientSearch(e.target.value)}
                    placeholder="Atau cari & pilih user terdaftar..."
                    aria-label="Cari user penerima alat"
                    className="w-full bg-transparent text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none"
                  />
                  {loadingRecipients && <Loader2 className="w-3.5 h-3.5 animate-spin text-slate-400 shrink-0" />}
                </div>

                <div className="max-h-40 overflow-y-auto divide-y divide-slate-100">
                  {visibleOptions.length > 0 ? (
                    visibleOptions.map((opt) => {
                      const isSelected = selectedUserId === String(opt.id);

                      return (
                        <button
                          key={opt.id}
                          type="button"
                          onClick={() => setSelectedUserId(String(opt.id))}
                          className={`w-full flex items-center justify-between gap-2 px-3 py-2 text-left text-xs transition-colors ${
                            isSelected
                              ? 'bg-sky-50 text-sky-900 font-medium'
                              : 'hover:bg-slate-50 text-slate-700'
                          }`}
                        >
                          <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-1.5">
                              <span className="font-medium truncate">{opt.name}</span>
                              {opt.is_requester && (
                                <span className="px-1.5 py-0.5 rounded bg-teal-100 text-teal-800 text-[10px] font-semibold">
                                  Pemohon
                                </span>
                              )}
                            </div>
                            <div className="text-[11px] text-slate-500 truncate">
                              @{opt.username}
                              {opt.divisi ? ` • ${opt.divisi}` : ''}
                            </div>
                          </div>
                          {isSelected && <Check className="w-4 h-4 text-sky-600 shrink-0" />}
                        </button>
                      );
                    })
                  ) : (
                    <div className="px-3 py-3 text-center text-[11px] text-slate-500">
                      {loadingRecipients
                        ? 'Memuat daftar user...'
                        : 'Tidak ada user terdaftar yang cocok.'}
                    </div>
                  )}
                </div>
              </div>
            </div>

            <LoanConditionSelector
              value={kondisiSerah}
              onChange={setKondisiSerah}
              label="Kondisi Barang Saat Diserahkan"
            />

            <div>
              <label
                htmlFor="handover-catatan-serah"
                className="text-xs font-semibold text-slate-700 block mb-1"
              >
                Keterangan / Catatan Serah-Terima{' '}
                <span className="text-slate-400 font-normal">(Opsional)</span>
              </label>
              <textarea
                id="handover-catatan-serah"
                rows={3}
                maxLength={1000}
                value={catatanSerah}
                onChange={(e) => setCatatanSerah(e.target.value)}
                placeholder="Lengkap dengan tas, charger, dan kabel HDMI"
                className="w-full text-xs rounded-lg border border-slate-200 p-2.5 focus:outline-none focus:ring-1 focus:ring-sky-500"
              />
            </div>

            <LoanAttachmentUploader
              files={files}
              onChange={setFiles}
              inputId="handover-lampiran-input"
            />

            <div className="flex items-center justify-end gap-2 pt-2 border-t">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => onOpenChange(false)}
                disabled={isSubmitting}
              >
                Batal
              </Button>
              <Button
                type="submit"
                size="sm"
                className="bg-sky-600 hover:bg-sky-700 text-white"
                disabled={isSubmitting}
              >
                {isSubmitting ? 'Menyimpan...' : 'Tandai Sudah Dikasih'}
              </Button>
            </div>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}

export function ReturnEquipmentModal({
  open,
  onOpenChange,
  ticket,
  onSuccess,
}: ReturnEquipmentModalProps) {
  const [kondisiKembali, setKondisiKembali] = useState<EquipmentCondition>('baik');
  const [catatanKembali, setCatatanKembali] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    setKondisiKembali('baik');
    setCatatanKembali('');
    setFiles([]);
    setIsSubmitting(false);
  }, [open, ticket?.id]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    if (!ticket || isSubmitting) return;

    setIsSubmitting(true);

    router.post(
      route('admin.tiket.kembalikan-alat', ticket.id),
      {
        _method: 'patch',
        kondisi_kembali: kondisiKembali,
        catatan_kembali: catatanKembali,
        lampiran: files,
      },
      {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: (page) => {
          setIsSubmitting(false);
          // SAFETY: Inertia response props carry Laravel flash messages.
          const flashError = (page as FlashResponsePage)?.props?.flash?.error;

          if (flashError) {
            Swal.fire({
              title: 'Gagal Menyimpan',
              text: flashError,
              icon: 'error',
              confirmButtonColor: '#ef4444',
            });

            return;
          }

          onOpenChange(false);
          onSuccess?.();
          Swal.fire({
            title: 'Berhasil!',
            text: 'Pengembalian alat berhasil disimpan.',
            icon: 'success',
            confirmButtonColor: '#059669',
            timer: 2000,
            showConfirmButton: false,
          });
        },
        onError: (errs) => {
          setIsSubmitting(false);
          const firstMsg = Object.values(errs)[0] || 'Gagal menyimpan status pengembalian.';

          Swal.fire({
            title: 'Gagal Menyimpan',
            text: String(firstMsg),
            icon: 'error',
            confirmButtonColor: '#ef4444',
          });
        },
        onFinish: () => {
          setIsSubmitting(false);
        },
      },
    );
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-base font-semibold">
            <CheckCircle2 className="w-5 h-5 text-emerald-600" />
            Pemeriksaan Pengembalian Alat
          </DialogTitle>
        </DialogHeader>

        {ticket && (
          <form onSubmit={handleSubmit} className="space-y-4 pt-1">
            <TicketLoanSummaryBox ticket={ticket} />

            {!ticket.hasHandedOver && (
              <div className="flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50/70 p-2.5 text-xs text-sky-800">
                <Info className="w-4 h-4 shrink-0 mt-0.5 text-sky-600" />
                <span>
                  Serah-terima belum ditandai; Anda tetap bisa langsung menyimpan pengembalian alat.
                </span>
              </div>
            )}

            <LoanConditionSelector
              value={kondisiKembali}
              onChange={setKondisiKembali}
              label="Kondisi Fisik & Kelengkapan"
            />

            <div>
              <label
                htmlFor="return-catatan-kembali"
                className="text-xs font-semibold text-slate-700 block mb-1"
              >
                Catatan Pemeriksaan / Pengembalian{' '}
                <span className="text-slate-400 font-normal">(Opsional)</span>
              </label>
              <textarea
                id="return-catatan-kembali"
                rows={3}
                maxLength={1000}
                value={catatanKembali}
                onChange={(e) => setCatatanKembali(e.target.value)}
                placeholder="Contoh: Kabel lengkap dan tas bersih, atau ada goresan halus..."
                className="w-full text-xs rounded-lg border border-slate-200 p-2.5 focus:outline-none focus:ring-1 focus:ring-emerald-500"
              />
            </div>

            <LoanAttachmentUploader
              files={files}
              onChange={setFiles}
              inputId="return-lampiran-input"
            />

            <div className="flex items-center justify-end gap-2 pt-2 border-t">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => onOpenChange(false)}
                disabled={isSubmitting}
              >
                Batal
              </Button>
              <Button
                type="submit"
                size="sm"
                className="bg-emerald-600 hover:bg-emerald-700 text-white"
                disabled={isSubmitting}
              >
                {isSubmitting ? 'Menyimpan...' : 'Simpan Pengembalian'}
              </Button>
            </div>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}
