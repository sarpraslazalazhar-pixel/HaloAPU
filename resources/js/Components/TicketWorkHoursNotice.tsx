import { Button } from '@/Components/ui/button';
import { AlertTriangle, Clock3, MessageSquare, PlayCircle } from 'lucide-react';

export interface WorkHoursInfo {
  reason: 'paused' | 'queued';
  resume_status: string | null;
  is_open: boolean;
  next_open_label: string | null;
  operator_name: string | null;
  is_assignee: boolean;
  can_resume: boolean;
}

interface Props {
  workHours: WorkHoursInfo;
  isAdmin: boolean;
  onContact: () => void;
  onResume?: () => void;
  resumeProcessing?: boolean;
}

export function TicketWorkHoursNotice({ workHours, isAdmin, onContact, onResume, resumeProcessing }: Props) {
  const paused = workHours.reason === 'paused';

  const open = workHours.is_open;

  const heading = !open
    ? 'Jam kerja sudah berakhir'
    : paused
      ? workHours.is_assignee ? 'Tiket siap dilanjutkan' : 'Menunggu petugas melanjutkan tiket'
      : 'Tiket menunggu giliran petugas';

  const body = !open
    ? paused ? 'Pengerjaan tiket ditunda.' : 'Posisi tiket dalam antrean tidak berubah.'
    : paused
      ? 'Jam kerja sudah dimulai. Tiket ini sudah mendapat giliran dan menunggu dilanjutkan oleh petugas.'
      : 'Jam kerja sedang berlangsung. Tiket ini sedang dalam antrean dan akan otomatis diproses saat petugas menyelesaikan tiket sebelumnya.';

  const guidance = !open
    ? isAdmin
      ? 'Jika harus ditangani hari ini, koordinasikan lewat chat tiket dan ajukan lembur sesuai prosedur kantor.'
      : workHours.operator_name
        ? 'Perlu ditangani hari ini? Hubungi petugas lewat chat tiket untuk meminta lembur.'
        : 'Petugas belum ditugaskan. Sampaikan kebutuhan hari ini di chat tiket.'
    : paused && workHours.is_assignee
      ? 'Pilih Lanjutkan Tiket untuk melanjutkan pengerjaan.'
      : null;

  const showResume = workHours.can_resume && !!onResume && paused;

  return (
    <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 p-4 sm:p-5">
      <div className="flex flex-wrap items-start gap-3">
        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" aria-hidden="true" />
        <div className="min-w-0 flex-1 space-y-1.5">
          <p className="text-sm font-semibold text-amber-900 sm:text-base">{heading}</p>
          <p className="text-sm text-amber-900/90">{body}</p>
          {!workHours.is_open && workHours.next_open_label && (
            <p className="flex items-center gap-1.5 text-sm text-amber-900/80">
              <Clock3 className="h-4 w-4 shrink-0" aria-hidden="true" />
              <span>Buka kembali: {workHours.next_open_label}</span>
            </p>
          )}
          <p className="text-sm text-amber-900/80">
            {workHours.operator_name ? `Petugas: ${workHours.operator_name}` : 'Belum ada petugas yang ditugaskan'}
          </p>
          {guidance && <p className="text-sm text-amber-900/80">{guidance}</p>}
        </div>
      </div>

      <div className="mt-3 flex flex-wrap gap-2 pl-0 sm:pl-8">
        {showResume && onResume && (
          <Button size="sm" onClick={onResume} disabled={resumeProcessing} className="min-h-11">
            <PlayCircle className="mr-1.5 h-4 w-4" aria-hidden="true" />
            {resumeProcessing ? 'Memproses...' : 'Lanjutkan Tiket'}
          </Button>
        )}
        {!workHours.is_assignee && (
          <Button size="sm" variant="outline" onClick={onContact} className="min-h-11">
            <MessageSquare className="mr-1.5 h-4 w-4" aria-hidden="true" />
            {workHours.operator_name ? 'Hubungi Petugas' : 'Buka Chat Tiket'}
          </Button>
        )}
      </div>
    </div>
  );
}
