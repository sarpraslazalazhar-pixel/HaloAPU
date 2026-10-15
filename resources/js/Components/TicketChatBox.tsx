import React, { useState, useEffect, useRef, useMemo } from 'react';
import { useForm, router } from '@inertiajs/react';
import { 
  Send, Paperclip, MessageSquare, Clock, 
  ExternalLink, X, Loader2, Image as ImageIcon, FileText, Download 
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { formatDateId } from '@/lib/utils';

interface LogAttachment {
  id: number;
  original_name?: string;
  file_name?: string;
  file_path: string;
  mime_type?: string;
  file_size?: number;
}

interface TicketLogItem {
  id: number;
  ticket_id: number;
  admin_id?: number | null;
  aksi: string;
  catatan?: string | null;
  timestamp: string;
  admin?: {
    id: number;
    name?: string;
    username: string;
  } | null;
  attachments?: LogAttachment[];
}

interface TicketChatData {
  id: number;
  formatted_id?: string;
  status?: string;
  user?: {
    id?: number;
    name?: string;
    username?: string;
    email?: string;
  } | null;
  logs?: TicketLogItem[];
}

interface TicketChatBoxProps {
  ticket: TicketChatData;
  isAdmin?: boolean;
  currentAdminName?: string;
  replyRoute: string;
  downloadRoute?: string;
  canReply?: boolean;
  chatUrl?: string;
}
export function TicketChatBox({
  ticket,
  isAdmin = false,
  replyRoute,
  downloadRoute = 'admin.tiket.download',
  canReply = true,
  chatUrl,
}: TicketChatBoxProps) {
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const chatContainerRef = useRef<HTMLDivElement>(null);
  const [shouldAutoScroll, setShouldAutoScroll] = useState(true);

  const { data, setData, post, processing, reset, errors } = useForm({
    catatan: '',
    general_attachments: [] as File[],
    _method: 'post',
  });

  // Urutkan kronologis dari paling awal ke paling baru (chat style: paling baru di bawah)
  const sortedLogs = useMemo(() => {
    return [...(ticket.logs || [])].sort(
      (a: TicketLogItem, b: TicketLogItem) => new Date(a.timestamp).getTime() - new Date(b.timestamp).getTime()
    );
  }, [ticket.logs]);

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

  // Polling update berkala (4 detik) agar pesan dari mobile/web langsung muncul tanpa reload layar
  useEffect(() => {
    const timer = setInterval(() => {
      router.reload({ only: ['ticket'] });
    }, 4000);
    return () => clearInterval(timer);
  }, []);

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
    if (act === 'alat_dikembalikan') return `Alat telah ditandai dikembalikan`;

    return log.catatan || act;
  };

  const isClosed = ['solve', 'reject', 'dibatalkan'].includes(String(ticket.status || '').toLowerCase());

  return (
    <div className="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs flex flex-col h-[650px] max-h-[85vh]">
      {/* Header Chat */}
      <div className="px-4 py-3 bg-slate-50/90 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3 shrink-0">
        <div className="flex items-center gap-2.5">
          <div className="w-9 h-9 rounded-full bg-sky-100 text-sky-700 flex items-center justify-center font-bold text-sm shrink-0 border border-sky-200">
            {isAdmin 
              ? (ticket.user?.name || ticket.user?.username || 'U').charAt(0).toUpperCase()
              : 'CS'
            }
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h3 className="text-xs sm:text-sm font-semibold text-slate-800">
                {isAdmin
                  ? (ticket.user?.name || ticket.user?.username || 'Pengguna Tiket')
                  : 'Tim Support / Admin APU'
                }
              </h3>
              <span className="text-[10px] px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-semibold border border-emerald-200">
                Online Sinkron
              </span>
            </div>
            <p className="text-[11px] text-slate-500">
              Diskusi Langsung Tiket #{ticket.formatted_id || ticket.id}
            </p>
          </div>
        </div>

        {chatUrl && (
          <a
            href={chatUrl}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sky-50 text-sky-700 hover:bg-sky-100 border border-sky-200 text-xs font-semibold transition-colors"
          >
            <MessageSquare className="w-3.5 h-3.5" />
            <span>Buka Room Chat Web</span>
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
          <div className="h-full flex flex-col items-center justify-center text-slate-400 text-xs py-12">
            <MessageSquare className="w-8 h-8 text-slate-300 mb-2 stroke-1" />
            <p>Belum ada percakapan pada tiket ini.</p>
            <p className="text-[11px] text-slate-400 mt-0.5">Kirim pesan pertama Anda di bawah.</p>
          </div>
        ) : (
          sortedLogs.map((log: TicketLogItem) => {
            const isBalasan = (log.aksi || '').toLowerCase() === 'balasan';

            // Jika bukan balasan (misal status diubah, tiket dibuat), tampilkan sebagai System Event Pill di tengah
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

            // Balasan pesan
            const fromAdmin = log.admin_id !== null;
            const isMe = isAdmin ? fromAdmin : !fromAdmin;
            const senderDisplayName = fromAdmin
              ? (log.admin?.name || log.admin?.username || 'Admin')
              : (ticket.user?.name || ticket.user?.username || 'Pengguna');

            return (
              <div
                key={log.id}
                className={`flex gap-2.5 items-end ${isMe ? 'justify-end' : 'justify-start'}`}
              >
                {!isMe && (
                  <div className="w-7 h-7 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center font-bold text-xs shrink-0 border border-slate-300">
                    {senderDisplayName.charAt(0).toUpperCase()}
                  </div>
                )}

                <div className="max-w-[82%] sm:max-w-[70%] space-y-1">
                  <div className={`flex items-center gap-1.5 text-[11px] text-slate-400 px-1 ${isMe ? 'justify-end' : 'justify-start'}`}>
                    <span className="font-semibold text-slate-600">{isMe ? 'Anda' : senderDisplayName}</span>
                    <span>•</span>
                    <span>{formatDateId(log.timestamp)}</span>
                  </div>

                  <div
                    className={`rounded-2xl px-4 py-2.5 text-xs sm:text-sm leading-relaxed shadow-xs whitespace-pre-wrap ${
                      isMe
                        ? 'bg-sky-600 text-white rounded-br-xs'
                        : 'bg-white text-slate-800 border border-slate-200 rounded-bl-xs'
                    }`}
                  >
                    <p>{log.catatan}</p>

                    {/* Lampiran Pesan */}
                    {log.attachments && log.attachments.length > 0 && (
                      <div className="mt-2.5 pt-2 border-t border-white/20 space-y-1.5">
                        <p className={`text-[10px] font-semibold flex items-center gap-1 ${isMe ? 'text-sky-100' : 'text-slate-500'}`}>
                          <Paperclip className="w-3 h-3" />
                          <span>{log.attachments.length} Berkas Lampiran</span>
                        </p>
                        <div className="space-y-1">
                          {log.attachments.map((att) => {
                            const name = att.original_name || att.file_name || 'Lampiran';
                            const isImg = att.mime_type?.startsWith('image/') || /\.(jpg|jpeg|png|webp)$/i.test(name);
                            const url = route(downloadRoute, att.id);

                            return (
                              <div
                                key={att.id}
                                className={`flex items-center justify-between gap-2 p-1.5 rounded-lg text-xs ${
                                  isMe ? 'bg-sky-700/60 text-white' : 'bg-slate-50 text-slate-700 border border-slate-200'
                                }`}
                              >
                                <div className="flex items-center gap-1.5 min-w-0">
                                  {isImg ? <ImageIcon className="w-3.5 h-3.5 shrink-0" /> : <FileText className="w-3.5 h-3.5 shrink-0" />}
                                  <span className="truncate max-w-[170px] sm:max-w-[240px] text-[11px]">{name}</span>
                                </div>
                                <a
                                  href={url}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  className={`p-1 rounded hover:bg-black/10 transition-colors shrink-0 ${isMe ? 'text-white' : 'text-sky-600'}`}
                                  title="Unduh / Buka berkas"
                                >
                                  <Download className="w-3.5 h-3.5" />
                                </a>
                              </div>
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
                {data.general_attachments.map((file, idx) => (
                  <span
                    key={idx}
                    className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-sky-50 text-sky-800 text-[11px] border border-sky-200 font-medium"
                  >
                    <Paperclip className="w-3 h-3 text-sky-600" />
                    <span className="truncate max-w-[150px]">{file.name}</span>
                    <button
                      type="button"
                      onClick={() => setData('general_attachments', data.general_attachments.filter((_, i) => i !== idx))}
                      className="text-sky-600 hover:text-red-500 transition-colors"
                    >
                      <X className="w-3 h-3" />
                    </button>
                  </span>
                ))}
              </div>
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
                  accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"
                  className="hidden"
                  onChange={(e) => {
                    const files = Array.from(e.target.files || []);
                    if (data.general_attachments.length + files.length > 3) {
                      alert('Maksimal hanya 3 lampiran.');
                      return;
                    }
                    const validFiles = files.filter((f) => {
                      if (f.size > 3 * 1024 * 1024) {
                        alert(`${f.name} melebihi 3MB.`);
                        return false;
                      }
                      return true;
                    });
                    setData('general_attachments', [...data.general_attachments, ...validFiles]);
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
    </div>
  );
}
