import React from 'react';
import { 
  FileText, Download, Eye, Image as ImageIcon,
  FileArchive, ShieldCheck, MessageSquare, Layers, Paperclip 
} from 'lucide-react';
import { Button } from './ui/button';
import { AttachmentViewer } from './AttachmentViewer';

export interface Attachment {
  id: number;
  original_name: string;
  file_path: string;
  field_id?: number | null;
  ticket_log_id?: number | null;
  mime_type?: string | null;
  file_size?: number | null;
  created_at?: string | null;
  field?: {
    id: number;
    label: string;
  } | null;
  log?: {
    id: number;
    aksi: string;
    catatan?: string | null;
    timestamp?: string | null;
    admin?: {
      name?: string;
      username: string;
    } | null;
  } | null;
}

interface TicketAttachmentListProps {
  attachments: Attachment[];
  downloadRoute: string;
  grouped?: boolean;
}

const formatFileSize = (bytes?: number | null): string => {
  if (!bytes || bytes <= 0) return '';

  if (bytes < 1024) return `${bytes} B`;

  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

const getFileIcon = (filename: string, mime?: string | null) => {
  const ext = filename?.split('.').pop()?.toLowerCase() || '';

  if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext) || mime?.startsWith('image/')) {
    return <ImageIcon className="h-4 w-4 text-emerald-500 shrink-0" />;
  }

  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) {
    return <FileArchive className="h-4 w-4 text-amber-500 shrink-0" />;
  }

  if (ext === 'pdf') {
    return <FileText className="h-4 w-4 text-red-500 shrink-0" />;
  }

  if (['doc', 'docx'].includes(ext)) {
    return <FileText className="h-4 w-4 text-blue-500 shrink-0" />;
  }

  return <FileText className="h-4 w-4 text-slate-400 shrink-0" />;
};

const statusNameMap = {
  open: 'Baru',
  on_proses: 'Diproses',
  pending: 'Tertunda',
  solve: 'Selesai',
  reject: 'Ditolak',
  assign_operator: 'Penugasan Operator',
  revisi: 'Revisi',
  balasan: 'Balasan',
} satisfies Record<string, string>;

export function TicketAttachmentList({ attachments, downloadRoute, grouped = false }: TicketAttachmentListProps) {
  if (!attachments?.length) return null;

  const viewRoute = downloadRoute.replace('.download', '.view');

  const renderAttachmentRow = (att: Attachment, showOriginBadge: boolean = true) => {
    const filename = att.original_name || att.file_path?.split('/').pop() || 'Berkas Lampiran';
    const sizeStr = formatFileSize(att.file_size);
    // SAFETY: Look up status name in statusNameMap using keyof; fallback to raw aksi string if unknown.
    const actionLabel = (att.log?.aksi ? statusNameMap[att.log.aksi as keyof typeof statusNameMap] : undefined) || att.log?.aksi;

    return (
      <div 
        key={att.id} 
        className="flex flex-col sm:flex-row sm:items-center justify-between p-3 border border-slate-200 rounded-lg bg-white hover:bg-slate-50/70 transition-colors gap-3"
      >
        <div className="flex items-start gap-3 min-w-0">
          <div className="p-2 bg-slate-100 rounded-lg text-slate-600 shrink-0 mt-0.5">
            {getFileIcon(filename, att.mime_type)}
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-sm font-medium text-slate-800 truncate" title={filename}>
              {filename}
            </p>
            <div className="flex flex-wrap items-center gap-2 mt-1">
              {sizeStr && (
                <span className="text-xs text-slate-400 font-mono">{sizeStr}</span>
              )}

              {showOriginBadge && att.field?.label && (
                <span className="text-[11px] bg-blue-50 text-blue-700 px-2 py-0.5 rounded border border-blue-100 font-medium">
                  {att.field.label}
                </span>
              )}

              {showOriginBadge && att.log && (
                <span className="text-[11px] bg-emerald-50 text-emerald-700 px-2 py-0.5 rounded border border-emerald-100 font-medium flex items-center gap-1">
                  <span>Status: {actionLabel}</span>
                  {att.log.admin && (
                    <span className="text-slate-500 font-normal">
                      • {att.log.admin.name || att.log.admin.username}
                    </span>
                  )}
                </span>
              )}
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2 shrink-0 self-end sm:self-center">
          <AttachmentViewer attachment={att} viewRoute={viewRoute} downloadRoute={downloadRoute}>
            <Button type="button" variant="outline" size="sm" className="h-8 text-blue-600 hover:text-blue-800 flex items-center gap-1.5 hover:bg-blue-50 border-blue-200">
              <Eye className="h-3.5 w-3.5" />
              <span>Lihat</span>
            </Button>
          </AttachmentViewer>

          <a 
            href={route(downloadRoute, att.id)} 
            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition-colors h-8"
            download
            title="Unduh Berkas"
          >
            <Download className="h-3.5 w-3.5 text-slate-500" />
            <span>Unduh</span>
          </a>
        </div>
      </div>
    );
  };

  // If simple flat list
  if (!grouped) {
    return (
      <div className="space-y-2">
        {attachments.map((att) => renderAttachmentRow(att, false))}
      </div>
    );
  }

  // Grouped mode for Tab Lampiran
  const initialAttachments: Attachment[] = [];
  const adminAttachments: Attachment[] = [];
  const revisionAttachments: Attachment[] = [];
  const otherAttachments: Attachment[] = [];

  attachments.forEach((att) => {
    if (att.log) {
      if (['on_proses', 'solve', 'pending', 'reject', 'assign_operator'].includes(att.log.aksi)) {
        adminAttachments.push(att);
      } else if (['revisi', 'balasan', 'need_revision'].includes(att.log.aksi)) {
        revisionAttachments.push(att);
      } else {
        otherAttachments.push(att);
      }
    } else {
      initialAttachments.push(att);
    }
  });

  const groups = [
    {
      id: 'initial',
      title: 'Lampiran Pengajuan Awal',
      subtitle: 'Berkas dari formulir permohonan tiket pemohon',
      icon: Layers,
      color: 'text-blue-600',
      bgColor: 'bg-blue-50/50',
      items: initialAttachments,
    },
    {
      id: 'admin',
      title: 'Lampiran Tanggapan Admin / Update Status',
      subtitle: 'Berkas pendukung yang diunggah admin saat perubahan status',
      icon: ShieldCheck,
      color: 'text-emerald-600',
      bgColor: 'bg-emerald-50/50',
      items: adminAttachments,
    },
    {
      id: 'revision',
      title: 'Lampiran Revisi & Balasan',
      subtitle: 'Berkas catatan revisi atau tanggapan diskusi tiket',
      icon: MessageSquare,
      color: 'text-amber-600',
      bgColor: 'bg-amber-50/50',
      items: revisionAttachments,
    },
    {
      id: 'other',
      title: 'Lampiran Tambahan Lainnya',
      subtitle: 'Berkas pendukung lainnya',
      icon: Paperclip,
      color: 'text-slate-600',
      bgColor: 'bg-slate-50/50',
      items: otherAttachments,
    },
  ].filter(g => g.items.length > 0);

  if (groups.length === 0) {
    return (
      <p className="text-xs text-slate-500 py-4 text-center">
        Tidak ada lampiran file pada tiket ini.
      </p>
    );
  }

  return (
    <div className="space-y-6">
      {groups.map((group) => {
        const GroupIcon = group.icon;

        return (
          <div key={group.id} className="border border-slate-200 rounded-xl overflow-hidden bg-white shadow-sm">
            <div className={`px-4 py-3 border-b border-slate-200 flex items-center justify-between ${group.bgColor}`}>
              <div className="flex items-center gap-2.5">
                <GroupIcon className={`w-5 h-5 ${group.color}`} />
                <div>
                  <h4 className="text-sm font-semibold text-slate-800">{group.title}</h4>
                  <p className="text-xs text-slate-500">{group.subtitle}</p>
                </div>
              </div>
              <span className="text-xs font-semibold px-2.5 py-0.5 rounded-full bg-white border border-slate-200 text-slate-700 shadow-sm">
                {group.items.length} berkas
              </span>
            </div>

            <div className="p-3 space-y-2">
              {group.items.map((att) => renderAttachmentRow(att, true))}
            </div>
          </div>
        );
      })}
    </div>
  );
}
