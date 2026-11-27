import React, { useState, useEffect, useRef, useMemo } from 'react';
import axios from 'axios';
import { useForm, usePage } from '@inertiajs/react';
import {
  Send, Paperclip, MessageSquare, Clock, Pencil,
  ExternalLink, X, Loader2, FileSpreadsheet, FileText
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { formatDateId } from '@/lib/utils';
import { LocalAttachmentImage, MAX_ATTACHMENT_BYTES, fitEditedAttachment } from '@/lib/localAttachment';
import { AttachmentViewer } from '@/Components/AttachmentViewer';
import ImageEditorModal from '@/Components/FormBuilder/ImageEditorModal';
import { subscribe, type TicketEvents, type TicketLogEntry } from '@/lib/realtime';

type TicketLogItem = TicketLogEntry;

interface TicketChatSummary {
  id: number;
  formatted_id?: string;
  status?: string;
  sub_unit?: {
    nama_layanan?: string | null;
  } | null;
  created_by_admin_id?: number | null;
  created_by_admin?: {
    id?: number;
    name?: string;
    username?: string;
    email?: string;
  } | null;
  user?: {
    id?: number;
    name?: string;
    username?: string;
    email?: string;
  } | null;
  logs?: TicketLogItem[];
}

interface TicketChatPageProps {
  auth?: {
    admin?: { id?: number } | null;
  };
}

interface TicketChatBoxProps {
  ticket: TicketChatSummary;
  isAdmin?: boolean;
  currentAdminId?: number | null;
  currentAdminName?: string;
  replyRoute: string;
  editReplyRoute?: string;
  downloadRoute?: string;
  viewRoute?: string;
  previewRoute?: string;
  canReply?: boolean;
  chatUrl?: string;
  includeSystemLogs?: boolean;
  managedRealtime?: boolean;
  onLogEdited?: (log: TicketLogItem) => void;
}

const isChatReplyLog = (log: TicketLogItem): boolean => (log.aksi || '').toLowerCase() === 'balasan';

export function TicketChatBox({
  ticket,
  isAdmin = false,
  currentAdminId: currentAdminIdProp,
  replyRoute,
  editReplyRoute,
  downloadRoute = 'admin.tiket.download',
  viewRoute = 'admin.tiket.view',
  previewRoute,
  canReply = true,
  chatUrl,
  includeSystemLogs = false,
  managedRealtime = false,
  onLogEdited,
}: TicketChatBoxProps) {
  const resolvedPreviewRoute = previewRoute ?? downloadRoute.replace('.download', '.preview');
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const chatContainerRef = useRef<HTMLDivElement>(null);
  const [shouldAutoScroll, setShouldAutoScroll] = useState(true);
  const attachmentDialogRef = useRef<HTMLDialogElement>(null);
  const [previewFile, setPreviewFile] = useState<File | null>(null);
  const [attachmentWarning, setAttachmentWarning] = useState('');
  const [editSession, setEditSession] = useState<AbortController | null>(null);

  useEffect(() => {
    if (previewFile) attachmentDialogRef.current?.showModal();
  }, [previewFile]);

  const { data, setData, post, processing, reset, errors } = useForm({
    catatan: '',
    // SAFETY: New reply form always starts with an empty File array.
    general_attachments: [] as File[],
    _method: 'post',
  });

  // Local feed state synced with server props and updated via inline edits / Echo.
  const [localLogs, setLocalLogs] = useState<TicketLogItem[]>(() => {
    const base = ticket.logs || [];

    return includeSystemLogs ? base : base.filter(isChatReplyLog);
  });

  const [editingLogId, setEditingLogId] = useState<number | null>(null);
  const [editText, setEditText] = useState('');
  const [savingEdit, setSavingEdit] = useState(false);
  const [editError, setEditError] = useState('');

  useEffect(() => {
    const base = ticket.logs || [];

    setLocalLogs(includeSystemLogs ? base : base.filter(isChatReplyLog));
  }, [ticket.logs, includeSystemLogs]);

  useEffect(() => {
    setEditingLogId(null);
    setEditText('');
    setEditError('');
  }, [ticket.id]);

  // Urutkan kronologis dari paling awal ke paling baru (chat style: paling baru di bawah)
  const sortedLogs = useMemo(() => {
    const filtered = includeSystemLogs ? localLogs : localLogs.filter(isChatReplyLog);

    return [...filtered].sort(
      (a: TicketLogItem, b: TicketLogItem) => new Date(a.timestamp).getTime() - new Date(b.timestamp).getTime()
    );
  }, [localLogs, includeSystemLogs]);

  const scrollToBottom = (smooth = true) => {
    if (messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto' });
    }
  };

  useEffect(() => {
    if (shouldAutoScroll) {
      scrollToBottom(false);
    }
  }, [sortedLogs.length]);

  // Realtime: when not managed by parent `useTicketRealtime`, listen directly on ticket channel.
  useEffect(() => {
    if (managedRealtime || !ticket?.id) return;

    return subscribe<TicketEvents>(`ticket.${ticket.id}`, {
      '.TicketMessagePosted': ({ log }) => {
        if (!includeSystemLogs && !isChatReplyLog(log)) return;

        setLocalLogs((prev) => (prev.some((l) => l.id === log.id) ? prev : [...prev, log]));
      },
      '.TicketMessageEdited': ({ log }) => {
        setLocalLogs((prev) => prev.map((l) => (l.id === log.id ? { ...l, ...log } : l)));
      },
    });
  }, [managedRealtime, ticket?.id, includeSystemLogs]);

  const handleSaveEdit = async (log: TicketLogItem) => {
    if (!editReplyRoute || savingEdit) return;

    const trimmed = editText.trim();

    if (!trimmed) {
      setEditError('Komentar tidak boleh kosong.');

      return;
    }

    setSavingEdit(true);
    setEditError('');

    try {
      const response = await axios.patch<{ log?: Partial<TicketLogItem> }>(
        route(editReplyRoute, [ticket.id, log.id]),
        { catatan: trimmed }
      );

      const updatedLog = response.data?.log;

      const mergedLog: TicketLogItem = {
        ...log,
        ...updatedLog,
        catatan: updatedLog?.catatan ?? trimmed,
        is_edited: true,
        edited_at: updatedLog?.edited_at ?? new Date().toISOString(),
      };

      setLocalLogs((prev) => prev.map((item) => (item.id === log.id ? mergedLog : item)));
      onLogEdited?.(mergedLog);
      setEditingLogId(null);
      setEditText('');
    } catch (err: unknown) {
      const message = axios.isAxiosError(err)
        ? err.response?.data?.message || err.response?.data?.errors?.catatan?.[0] || 'Gagal memperbarui komentar.'
        : 'Gagal memperbarui komentar.';

      setEditError(message);
    } finally {
      setSavingEdit(false);
    }
  };

  const handleScroll = () => {
    if (!chatContainerRef.current) return;
    const { scrollTop, scrollHeight, clientHeight } = chatContainerRef.current;
    const isAtBottom = scrollHeight - scrollTop - clientHeight < 80;
    setShouldAutoScroll(isAtBottom);
  };

  const handleSend = (e: React.SyntheticEvent) => {
    e.preventDefault();

    if (!data.catatan.trim() && data.general_attachments.length === 0) return;

    post(route(replyRoute, ticket.id), {
      preserveScroll: true,
      onSuccess: () => {
        reset();
        setShouldAutoScroll(true);
        setTimeout(() => scrollToBottom(true), 150);
      },
    });
  };

  const openAttachment = (file: File, isImage: boolean) => {
    if (isImage) {
      setPreviewFile(file);

      return;
    }

    const mime = /\.pdf$/i.test(file.name) ? 'application/pdf' : 'application/octet-stream';
    const url = URL.createObjectURL(file.slice(0, file.size, mime));
    setTimeout(() => URL.revokeObjectURL(url), 10_000);
    window.open(url, '_blank', 'noopener,noreferrer');
  };

  const clearAttachmentPreview = () => {
    editSession?.abort();
    setEditSession(null);
    setPreviewFile(null);
  };

  const saveEditedAttachment = async (editedFile: File) => {
    if (!previewFile || !editSession) return;

    let file: File | null = null;

    try {
      file = await fitEditedAttachment(editedFile);
    } catch {
      // Keep the original if decoding or encoding the edit fails.
    }

    if (editSession.signal.aborted || !attachmentDialogRef.current?.open) return;

    if (file) {
      const index = data.general_attachments.indexOf(previewFile);
      setAttachmentWarning('');
      setData('general_attachments', data.general_attachments.map((attachment, i) => i === index ? file : attachment));
    } else {
      setAttachmentWarning(`Edit ${editedFile.name} melebihi 3MB atau tidak dapat diperkecil. File asli tetap digunakan.`);
    }

    attachmentDialogRef.current.close();
  };

  const quickReplies = isAdmin ? [
    'Baik, sedang ditindaklanjuti oleh tim terkait.',
    'Mohon lampirkan foto/dokumen pendukung ya.',
    'Pengerjaan telah selesai, silakan dicek kembali.',
  ] : [
    'Terima kasih atas bantuannya.',
    'Kira-kira estimasi selesai kapan ya pak?',
    'Sudah saya lampirkan dokumen tambahannya.',
  ];

  const formatSystemAction = (log: TicketLogItem) => {
    const act = (log.aksi || '').toLowerCase();
    const adminName = log.admin?.name || log.admin?.username || 'Admin';

    if (act === 'dibuat' || act === 'open') return `Tiket dibuat oleh ${ticket.user?.name || ticket.user?.username || 'Pengguna'}`;

    if (act === 'on_proses') return `Status diubah menjadi Diproses oleh ${adminName}`;

    if (act === 'pending') return `Status diubah menjadi Ditunda oleh ${adminName}`;

    if (act === 'solve' || act === 'selesai') return `Tiket diselesaikan oleh ${adminName}`;

    if (act === 'reject') return `Tiket ditolak oleh ${adminName}`;

    if (act === 'dibatalkan') return `Tiket dibatalkan`;

    if (act === 'assign_operator') return `Penugasan operator oleh ${adminName}`;

    if (act === 'revisi' || act === 'need_revision') return `Permintaan revisi diajukan`;

    if (act === 'accepted') return `Hasil tiket diterima oleh pengguna`;

    if (act === 'alat_diserahkan') return `Alat telah diserahkan`;

    if (act === 'pengajuan_kembali_alat') return `Pemohon melaporkan alat sudah dikembalikan`;

    if (act === 'alat_dikembalikan') return `Alat telah ditandai dikembalikan`;

    if (act === 'work_hours_paused') return 'Tiket ditunda karena jam kerja berakhir';

    if (act === 'work_hours_queued') return 'Tiket tetap antre di luar jam kerja';

    if (act === 'work_hours_resumed') return 'Tiket dilanjutkan setelah jam kerja buka';

    if (act === 'work_hours_overtime_resumed') return 'Tiket dilanjutkan di luar jam kerja';

    return log.catatan || act;
  };

  // SAFETY: Inertia page props include the shared auth object.
  const { auth } = usePage().props as TicketChatPageProps;
  const currentAdminId = currentAdminIdProp ?? auth?.admin?.id ?? null;

  const requesterLabel =
    ticket.user?.name ||
    ticket.user?.username ||
    ticket.created_by_admin?.name ||
    ticket.created_by_admin?.username ||
    'Pemohon Tiket';

  const isClosed = ['solve', 'selesai', 'reject', 'dibatalkan'].includes(String(ticket.status || '').toLowerCase());

  return (
    <div className="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs flex flex-col h-[650px] max-h-[85vh]">
      {/* Header Chat */}
      <div className="px-4 py-3 bg-slate-50/90 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3 shrink-0">
        <div className="flex items-center gap-2.5">
          <div className="w-9 h-9 rounded-full bg-sky-100 text-sky-700 flex items-center justify-center font-bold text-sm shrink-0 border border-sky-200">
            {isAdmin
              ? requesterLabel.charAt(0).toUpperCase()
              : 'CS'
            }
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h3 className="text-xs sm:text-sm font-semibold text-slate-800">
                {isAdmin
                  ? requesterLabel
                  : 'Tim Support / Admin APU'
                }
              </h3>
            </div>
            <p className="text-[11px] text-slate-500">
              {ticket.sub_unit?.nama_layanan?.trim() || 'Diskusi Tiket'}
            </p>
          </div>
        </div>

        {chatUrl && (
          <a
            href={chatUrl}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sky-50 text-sky-700 hover:bg-sky-100 border border-sky-200 text-xs font-semibold transition-colors"
          >
            <MessageSquare className="w-3.5 h-3.5" />
            <span>Buka Pesan Web</span>
            <ExternalLink className="w-3 h-3 text-sky-500" />
          </a>
        )}
      </div>

      {/* Message Feed (Scrollable, chronological) */}
      <div
        ref={chatContainerRef}
        onScroll={handleScroll}
        className="flex-1 overflow-y-auto p-4 space-y-4 bg-slate-50/40"
      >
        {sortedLogs.length === 0 ? (
          <div className="h-full flex flex-col items-center justify-center text-slate-400 text-xs py-12 text-center">
            <MessageSquare className="w-8 h-8 text-slate-300 mb-2 stroke-1" />
            <p className="font-medium text-slate-500">Belum ada percakapan.</p>
            <p className="text-[11px] text-slate-400 mt-0.5">Kirim pesan untuk memulai diskusi.</p>
          </div>
        ) : (
          sortedLogs.map((log: TicketLogItem) => {
            const isBalasan = isChatReplyLog(log);

            // Jika includeSystemLogs aktif dan bukan balasan, tampilkan sebagai System Event Pill di tengah
            if (!isBalasan) {
              return (
                <div key={log.id} className="flex flex-col items-center justify-center my-3">
                  <div className="inline-flex items-center gap-1.5 text-[11px] bg-slate-100 text-slate-600 px-3 py-1 rounded-full border border-slate-200/80 shadow-2xs font-medium max-w-[90%] text-center">
                    <Clock className="w-3 h-3 text-slate-400 shrink-0" />
                    <span>{formatSystemAction(log)}</span>
                    <span className="text-slate-400 font-normal shrink-0">• {formatDateId(log.timestamp)}</span>
                  </div>
                </div>
              );
            }

            // Balasan pesan: pisahkan Anda (kanan, biru), Admin/Petugas lain (kiri, indigo), & Pengaju (kiri, putih)
            const fromAdmin = log.admin_id !== null && log.admin_id !== undefined;

            const creatorAdminId = ticket.created_by_admin_id ?? ticket.created_by_admin?.id ?? null;

            const isRequesterAdmin = Boolean(
              fromAdmin &&
              creatorAdminId !== null &&
              creatorAdminId !== undefined &&
              Number(log.admin_id) === Number(creatorAdminId)
            );

            const isMe = isAdmin
              ? Boolean(
                  fromAdmin &&
                  currentAdminId !== null &&
                  currentAdminId !== undefined &&
                  Number(log.admin_id) === Number(currentAdminId)
                )
              : !fromAdmin;

            const isOtherAdmin = isAdmin && fromAdmin && !isMe && !isRequesterAdmin;

            const senderDisplayName = fromAdmin
              ? (log.admin?.name || log.admin?.username || 'Admin')
              : requesterLabel;

            const roleBadgeLabel = isOtherAdmin
              ? 'Admin'
              : !isMe
                ? (isAdmin
                    ? (isRequesterAdmin ? 'Pengaju (Admin)' : 'Pengaju')
                    : (isRequesterAdmin ? 'Pengaju' : 'Petugas'))
                : null;

            const canEditLog =
              !isClosed &&
              Boolean(editReplyRoute) &&
              (isAdmin
                ? Boolean(fromAdmin && currentAdminId !== null && currentAdminId !== undefined && Number(log.admin_id) === Number(currentAdminId))
                : !fromAdmin);

            const isEditingThis = editingLogId === log.id;

            const avatarToneClass = isOtherAdmin
              ? 'bg-indigo-100 text-indigo-700 border-indigo-200'
              : 'bg-slate-200 text-slate-700 border-slate-300';

            const roleBadgeToneClass = isOtherAdmin
              ? 'bg-indigo-100 text-indigo-700 border-indigo-200'
              : (isAdmin || isRequesterAdmin)
                ? 'bg-slate-100 text-slate-600 border-slate-200'
                : 'bg-sky-100 text-sky-700 border-sky-200';

            const bubbleToneClass = isMe
              ? 'bg-blue-600 text-white rounded-br-xs'
              : isOtherAdmin
                ? 'bg-indigo-50 text-slate-800 border border-indigo-200 rounded-bl-xs'
                : 'bg-white text-slate-800 border border-slate-200 rounded-bl-xs';

            return (
              <div
                key={log.id}
                className={`flex gap-2.5 items-end ${isMe ? 'justify-end' : 'justify-start'}`}
              >
                {!isMe && (
                  <div className={`w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs shrink-0 border ${avatarToneClass}`}>
                    {senderDisplayName.charAt(0).toUpperCase()}
                  </div>
                )}

                <div className="max-w-[82%] sm:max-w-[70%] space-y-1">
                  <div className={`flex flex-wrap items-center gap-1.5 text-[11px] text-slate-400 px-1 ${isMe ? 'justify-end' : 'justify-start'}`}>
                    <span className="font-semibold text-slate-600">{isMe ? 'Anda' : senderDisplayName}</span>
                    {roleBadgeLabel && (
                      <span className={`inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-semibold leading-none ${roleBadgeToneClass}`}>
                        {roleBadgeLabel}
                      </span>
                    )}
                    <span>•</span>
                    <span>{formatDateId(log.timestamp)}</span>
                    {log.is_edited && <span className="italic text-slate-400">(diedit)</span>}
                    {canEditLog && !isEditingThis && (
                      <button
                        type="button"
                        title="Edit komentar"
                        aria-label="Edit komentar"
                        onClick={() => {
                          setEditingLogId(log.id);
                          setEditText(log.catatan || '');
                          setEditError('');
                        }}
                        className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium text-slate-500 hover:bg-slate-200/70 hover:text-sky-700 transition-colors"
                      >
                        <Pencil className="w-3 h-3" />
                        <span>Edit</span>
                      </button>
                    )}
                  </div>

                  <div
                    className={`rounded-2xl px-4 py-2.5 text-xs sm:text-sm leading-relaxed shadow-xs whitespace-pre-wrap ${bubbleToneClass}`}
                  >
                    {isEditingThis ? (
                      <div className="space-y-2 min-w-[220px] sm:min-w-[280px]">
                        <textarea
                          rows={3}
                          value={editText}
                          disabled={savingEdit}
                          onChange={(e) => setEditText(e.target.value)}
                          onKeyDown={(e) => {
                            if (e.key === 'Escape') {
                              e.preventDefault();
                              setEditingLogId(null);
                              setEditText('');
                              setEditError('');
                            }
                          }}
                          className="w-full rounded-lg border border-slate-300 bg-white p-2 text-xs sm:text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-sky-500 resize-y"
                        />
                        {editError && (
                          <p className="rounded bg-rose-50 px-2 py-1 text-[11px] text-rose-700 border border-rose-200">
                            {editError}
                          </p>
                        )}
                        <div className="flex items-center justify-end gap-1.5">
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={savingEdit}
                            onClick={() => {
                              setEditingLogId(null);
                              setEditText('');
                              setEditError('');
                            }}
                            className="h-7 px-2.5 text-xs bg-white text-slate-700 border-slate-300 hover:bg-slate-100"
                          >
                            Batal
                          </Button>
                          <Button
                            type="button"
                            size="sm"
                            disabled={savingEdit || !editText.trim()}
                            onClick={() => handleSaveEdit(log)}
                            className="h-7 px-2.5 text-xs bg-sky-700 hover:bg-sky-800 text-white"
                          >
                            {savingEdit ? 'Menyimpan...' : 'Simpan'}
                          </Button>
                        </div>
                      </div>
                    ) : (
                      <p>{log.catatan}</p>
                    )}

                    {/* Lampiran Pesan */}
                    {log.attachments && log.attachments.length > 0 && (
                      <div className={`mt-2.5 pt-2 border-t space-y-1.5 ${isMe ? 'border-white/20' : isOtherAdmin ? 'border-indigo-200/80' : 'border-slate-200'}`}>
                        <p className={`text-[10px] font-semibold flex items-center gap-1 ${isMe ? 'text-blue-100' : isOtherAdmin ? 'text-indigo-700' : 'text-slate-500'}`}>
                          <Paperclip className="w-3 h-3" />
                          <span>{log.attachments.length} Berkas Lampiran</span>
                        </p>
                        <div className="space-y-1">
                          {/* Images get a real inline thumbnail, other files a row. */}
                          <div className="flex flex-wrap gap-1.5">
                          {log.attachments
                            .filter((att) => {
                              const name = att.original_name || att.file_name || '';

                              return att.mime_type?.startsWith('image/') || /\.(jpg|jpeg|png|webp|gif)$/i.test(name);
                            })
                            .map((att) => (
                              <AttachmentViewer
                                key={`thumb-${att.id}`}
                                attachment={{
                                  id: att.id,
                                  original_name: att.original_name || att.file_name || 'Lampiran',
                                  file_path: att.file_path,
                                }}
                                viewRoute={viewRoute}
                                downloadRoute={downloadRoute}
                              >
                                <button type="button" title="Lihat gambar" className="block p-0.5 rounded-lg overflow-hidden bg-white/20 hover:ring-2 hover:ring-sky-400 transition">
                                  <img
                                    src={route(viewRoute, att.id)}
                                    alt={att.original_name || 'Lampiran'}
                                    loading="lazy"
                                    className="h-20 w-20 object-cover rounded-md"
                                  />
                                </button>
                              </AttachmentViewer>
                            ))}
                          </div>
                          {log.attachments.map((att) => {
                            const name = att.original_name || att.file_name || 'Lampiran';
                            const isImg = att.mime_type?.startsWith('image/') || /\.(jpg|jpeg|png|webp)$/i.test(name);

                            const isSpreadsheet = att.mime_type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                              || att.mime_type === 'application/vnd.ms-excel'
                              || att.mime_type === 'text/csv'
                              || att.mime_type === 'application/csv'
                              || /\.(xls|xlsx|csv)$/i.test(name);

                            // Non-image files keep a compact row carrying the name.
                            if (isImg) return null;

                            // Review in place: the viewer previews images/PDF/DOCX/spreadsheets
                            // instead of forcing a download.
                            return (
                              <AttachmentViewer
                                key={att.id}
                                attachment={{
                                  id: att.id,
                                  original_name: name,
                                  file_path: att.file_path,
                                  mime_type: att.mime_type,
                                }}
                                viewRoute={viewRoute}
                                downloadRoute={downloadRoute}
                                previewRoute={resolvedPreviewRoute}
                              >
                                <button
                                  type="button"
                                  title="Lihat lampiran"
                                  className={`w-full flex items-center gap-1.5 p-1.5 rounded-lg text-xs text-left transition-colors ${
                                    isMe
                                      ? 'bg-blue-700/60 text-white hover:bg-blue-700/80'
                                      : isOtherAdmin
                                        ? 'bg-white text-slate-700 border border-indigo-200 hover:bg-indigo-100/70'
                                        : 'bg-slate-50 text-slate-700 border border-slate-200 hover:bg-slate-100'
                                  }`}
                                >
                                  {isSpreadsheet ? (
                                    <FileSpreadsheet className={`w-3.5 h-3.5 shrink-0 ${isMe ? 'text-emerald-200' : 'text-emerald-600'}`} />
                                  ) : (
                                    <FileText className="w-3.5 h-3.5 shrink-0" />
                                  )}
                                  <span className="truncate max-w-[170px] sm:max-w-[240px] text-[11px]">{name}</span>
                                </button>
                              </AttachmentViewer>
                            );
                          })}
                        </div>
                      </div>
                    )}
                  </div>
                </div>

                {isMe && (
                  <div className="w-7 h-7 rounded-full bg-sky-600 text-white flex items-center justify-center font-bold text-xs shrink-0 shadow-xs">
                    {senderDisplayName.charAt(0).toUpperCase()}
                  </div>
                )}
              </div>
            );
          })
        )}
        <div ref={messagesEndRef} />
      </div>

      {/* Quick Replies Bar */}
      {canReply && !isClosed && (
        <div className="px-3 py-1.5 bg-slate-50 border-t border-slate-200 flex items-center gap-1.5 overflow-x-auto shrink-0 no-scrollbar">
          <span className="text-[10px] text-slate-400 font-medium shrink-0">Template:</span>
          {quickReplies.map((qr, idx) => (
            <button
              key={idx}
              type="button"
              onClick={() => setData('catatan', qr)}
              className="px-2.5 py-1 rounded-full bg-white text-slate-600 hover:text-sky-700 hover:bg-sky-50 border border-slate-200 text-[11px] whitespace-nowrap transition-colors shrink-0 shadow-2xs"
            >
              {qr.length > 35 ? `${qr.slice(0, 35)}...` : qr}
            </button>
          ))}
        </div>
      )}

      {/* Docked Chat Input Area (Right underneath the newest message) */}
      <div className="p-3 bg-white border-t border-slate-200 shrink-0">
        {isClosed ? (
          <div className="text-center py-2 text-xs text-slate-500 bg-slate-50 rounded-xl border border-slate-200">
            Tiket ini sudah ditutup ({ticket.status}). Tidak dapat membalas pesan.
          </div>
        ) : !canReply ? (
          <div className="text-center py-2 text-xs text-slate-500 bg-slate-50 rounded-xl border border-slate-200">
            Anda tidak memiliki izin membalas tiket ini.
          </div>
        ) : (
          <form onSubmit={handleSend} className="space-y-2">
            {/* Attachment Preview Chips */}
            {data.general_attachments.length > 0 && (
              <div className="flex flex-wrap gap-2 pb-1">
                {data.general_attachments.map((file, idx) => {
                  const isImage = file.type.startsWith('image/') || /\.(jpg|jpeg|png|webp|gif)$/i.test(file.name);
                  const previewLabel = isImage ? `Pratinjau ${file.name}` : `Buka ${file.name} di tab baru`;

                  return (
                    <span
                      key={idx}
                      className="inline-flex max-w-full items-center gap-1.5 px-2.5 py-1 rounded-lg bg-sky-50 text-sky-800 text-[11px] border border-sky-200 font-medium"
                    >
                      <button
                        type="button"
                        title={previewLabel}
                        aria-label={previewLabel}
                        aria-haspopup={isImage ? 'dialog' : undefined}
                        disabled={processing}
                        onClick={() => openAttachment(file, isImage)}
                        className="inline-flex min-w-0 items-center gap-1.5 rounded-sm text-left hover:underline focus-visible:outline-2 focus-visible:outline-sky-700"
                      >
                        {isImage ? (
                          <LocalAttachmentImage file={file} alt="" className="size-7 shrink-0 rounded object-cover" />
                        ) : (
                          <Paperclip className="size-3 shrink-0 text-sky-700" />
                        )}
                        <span className="truncate max-w-[150px]">{file.name}</span>
                      </button>
                      <button
                        type="button"
                        aria-label={`Hapus ${file.name}`}
                        onClick={(event) => {
                          event.stopPropagation();
                          setData('general_attachments', data.general_attachments.filter((_, i) => i !== idx));
                          setAttachmentWarning('');
                        }}
                        className="grid size-6 shrink-0 place-items-center rounded-sm text-sky-700 hover:text-red-600 focus-visible:outline-2 focus-visible:outline-sky-700 transition-colors"
                      >
                        <X className="size-3" />
                      </button>
                    </span>
                  );
                })}
              </div>
            )}
            {data.general_attachments.length > 0 && attachmentWarning && (
              <p role="status" className="text-red-500 text-[11px]">{attachmentWarning}</p>
            )}

            <div className="flex items-end gap-2">
              <label
                className="p-2.5 rounded-xl border border-slate-200 bg-slate-50 text-slate-600 hover:text-sky-600 hover:bg-sky-50 cursor-pointer transition-colors shrink-0 shadow-2xs"
                title="Unggah Lampiran (Maks 3 file, 3MB/file)"
              >
                <Paperclip className="w-4 h-4" />
                <input
                  type="file"
                  multiple
                  accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx,.csv"
                  className="hidden"
                  onChange={(e) => {
                    const files = Array.from(e.target.files || []);

                    if (data.general_attachments.length + files.length > 3) {
                      alert('Maksimal hanya 3 lampiran.');

                      return;
                    }

                    const validFiles = files.filter((f) => {
                      if (f.size > MAX_ATTACHMENT_BYTES) {
                        alert(`${f.name} melebihi 3MB.`);

                        return false;
                      }

                      return true;
                    });

                    setData('general_attachments', [...data.general_attachments, ...validFiles]);
                    setAttachmentWarning('');
                    e.target.value = '';
                  }}
                />
              </label>

              <div className="flex-1 relative">
                <textarea
                  rows={2}
                  className="w-full rounded-xl border border-slate-200 bg-slate-50/70 p-2.5 text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:bg-white resize-none transition-colors"
                  placeholder="Ketik balasan untuk pengguna mobile... (tekan Shift+Enter untuk baris baru)"
                  value={data.catatan}
                  onChange={(e) => setData('catatan', e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                      e.preventDefault();
                      handleSend(e);
                    }
                  }}
                />
                {errors.catatan && (
                  <p className="text-red-500 text-[11px] mt-1">{errors.catatan}</p>
                )}
              </div>

              <Button
                type="submit"
                disabled={processing || (!data.catatan.trim() && data.general_attachments.length === 0)}
                className="h-10 px-4 bg-sky-600 hover:bg-sky-700 text-white rounded-xl gap-1.5 font-semibold text-xs shrink-0 shadow-xs"
              >
                {processing ? (
                  <Loader2 className="w-4 h-4 animate-spin" />
                ) : (
                  <Send className="w-4 h-4" />
                )}
                <span>Kirim</span>
              </Button>
            </div>
          </form>
        )}
      </div>
      <dialog
        ref={attachmentDialogRef}
        aria-label={previewFile ? `${editSession ? 'Edit' : 'Pratinjau'} ${previewFile.name}` : 'Pratinjau lampiran'}
        onClose={clearAttachmentPreview}
        onCancel={clearAttachmentPreview}
        className={`m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-4xl rounded-xl text-slate-800 backdrop:bg-slate-900/60 ${editSession ? 'bg-transparent' : 'bg-white'}`}
      >
        {previewFile && (editSession ? (
          <ImageEditorModal
            isOpen
            imageFile={previewFile}
            onClose={() => attachmentDialogRef.current?.close()}
            onSave={saveEditedAttachment}
          />
        ) : (
          <div className="space-y-4 p-4">
            <h3 className="wrap-anywhere text-sm font-semibold">{previewFile.name}</h3>
            <LocalAttachmentImage file={previewFile} alt={previewFile.name} className="mx-auto max-h-[70dvh] max-w-full object-contain" />
            <div className="flex flex-wrap justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => attachmentDialogRef.current?.close()}>
                Tutup
              </Button>
              <Button type="button" className="bg-sky-700 text-white hover:bg-sky-800" onClick={() => {
                setAttachmentWarning('');
                setEditSession(new AbortController());
              }}>
                Edit / Coret
              </Button>
            </div>
          </div>
        ))}
      </dialog>
    </div>
  );
}
