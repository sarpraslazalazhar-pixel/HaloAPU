import axios from 'axios';
import {
    Download,
    ExternalLink,
    FileSpreadsheet,
    FileText,
    Loader2,
    Search,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import mammoth from 'mammoth';
import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Components/ui/dialog';

interface AttachmentItem {
    id: number;
    original_name: string;
    file_path?: string;
    mime_type?: string | null;
}

interface AttachmentViewerProps {
    attachment: AttachmentItem;
    viewRoute: string;
    downloadRoute: string;
    previewRoute?: string;
    children: React.ReactNode;
}

interface SpreadsheetSheet {
    name: string;
    columns?: string[];
    row_count: number;
    col_count: number;
    truncated_rows?: boolean;
    truncated_cols?: boolean;
    rows: Array<Array<string | number | boolean | null>>;
}

interface SpreadsheetPreviewPayload {
    sheets: SpreadsheetSheet[];
    truncated_sheets?: boolean;
    total_sheets?: number;
    notice?: string | null;
}

type ViewerKind = 'image' | 'pdf' | 'docx' | 'legacy_doc' | 'spreadsheet' | 'other';

function extractExtension(attachment: AttachmentItem): string {
    const rawName = (attachment.original_name || attachment.file_path || '').trim().toLowerCase();
    const cleanName = rawName.split('?')[0].split('#')[0];
    const parts = cleanName.split('.');

    if (parts.length < 2) {
        return '';
    }

    return parts[parts.length - 1] ?? '';
}

function detectViewerKind(attachment: AttachmentItem): ViewerKind {
    const ext = extractExtension(attachment);
    const mime = (attachment.mime_type || '').toLowerCase();

    if (
        ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)
        || mime.startsWith('image/')
    ) {
        return 'image';
    }

    if (ext === 'pdf' || mime === 'application/pdf') {
        return 'pdf';
    }

    if (
        ext === 'docx'
        || mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ) {
        return 'docx';
    }

    if (ext === 'doc' || mime === 'application/msword') {
        return 'legacy_doc';
    }

    if (
        ['xlsx', 'xls', 'csv'].includes(ext)
        || mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        || mime === 'application/vnd.ms-excel'
        || mime === 'text/csv'
        || mime === 'application/csv'
    ) {
        return 'spreadsheet';
    }

    return 'other';
}

function buildWordHtmlDocument(bodyHtml: string, zoomPercent: number): string {
    const scaleFactor = (zoomPercent / 100).toFixed(2);

    return `<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8" />
<style>
  * { box-sizing: border-box; }
  html, body {
    margin: 0;
    padding: 0;
    background: #eef2f7;
    color: #0f172a;
    font-family: 'Segoe UI', Calibri, Arial, sans-serif;
    font-size: 14px;
    line-height: 1.6;
  }
  .word-canvas {
    min-height: 100%;
    padding: 28px 20px 44px;
    display: flex;
    justify-content: center;
    align-items: flex-start;
  }
  .word-page {
    width: 100%;
    max-width: 820px;
    background: #ffffff;
    border: 1px solid #dbe2ea;
    border-radius: 4px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
    padding: 36px 44px;
    zoom: ${scaleFactor};
    overflow-wrap: anywhere;
  }
  .word-page h1, .word-page h2, .word-page h3, .word-page h4 {
    color: #0f172a;
    margin-top: 1.15em;
    margin-bottom: 0.45em;
    line-height: 1.3;
  }
  .word-page h1:first-child, .word-page h2:first-child, .word-page p:first-child {
    margin-top: 0;
  }
  .word-page p {
    margin: 0 0 0.75em;
  }
  .word-page ul, .word-page ol {
    margin: 0 0 0.85em;
    padding-left: 1.5em;
  }
  .word-page table {
    width: 100%;
    border-collapse: collapse;
    margin: 14px 0;
    font-size: 13px;
  }
  .word-page th, .word-page td {
    border: 1px solid #cbd5e1;
    padding: 7px 10px;
    text-align: left;
    vertical-align: top;
  }
  .word-page th {
    background: #f8fafc;
    font-weight: 600;
  }
  .word-page img {
    max-width: 100%;
    height: auto;
  }
  .word-page blockquote {
    margin: 0.8em 0;
    padding: 6px 12px;
    border-left: 3px solid #94a3b8;
    background: #f8fafc;
    color: #334155;
  }
  @media (max-width: 640px) {
    .word-canvas { padding: 12px; }
    .word-page { padding: 20px 18px; }
  }
</style>
</head>
<body>
  <div class="word-canvas">
    <article class="word-page">${bodyHtml}</article>
  </div>
</body>
</html>`;
}

function toColumnLetter(index: number): string {
    let n = index;
    let label = '';

    while (n >= 0) {
        label = String.fromCharCode((n % 26) + 65) + label;
        n = Math.floor(n / 26) - 1;
    }

    return label;
}

export function AttachmentViewer({
    attachment,
    viewRoute,
    downloadRoute,
    previewRoute,
    children,
}: AttachmentViewerProps) {
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [docxBodyHtml, setDocxBodyHtml] = useState<string | null>(null);
    const [wordZoom, setWordZoom] = useState(100);

    const [spreadsheet, setSpreadsheet] = useState<SpreadsheetPreviewPayload | null>(null);
    const [activeSheetIdx, setActiveSheetIdx] = useState(0);
    const [searchQuery, setSearchQuery] = useState('');

    const abortRef = useRef<AbortController | null>(null);

    const kind = useMemo(() => detectViewerKind(attachment), [attachment]);
    const viewUrl = useMemo(() => route(viewRoute, attachment.id), [viewRoute, attachment.id]);
    const downloadUrl = useMemo(() => route(downloadRoute, attachment.id), [downloadRoute, attachment.id]);

    const resolvedPreviewUrl = useMemo(() => {
        if (!previewRoute) {
            return null;
        }

        return route(previewRoute, attachment.id);
    }, [previewRoute, attachment.id]);

    useEffect(() => {
        if (abortRef.current) {
            abortRef.current.abort();
            abortRef.current = null;
        }

        setLoading(false);
        setError(null);
        setDocxBodyHtml(null);
        setWordZoom(100);
        setSpreadsheet(null);
        setActiveSheetIdx(0);
        setSearchQuery('');
    }, [attachment.id, viewUrl, resolvedPreviewUrl]);

    useEffect(() => {
        return () => {
            if (abortRef.current) {
                abortRef.current.abort();
            }
        };
    }, []);

    const loadDocx = async (signal: AbortSignal) => {
        setLoading(true);
        setError(null);

        try {
            const response = await fetch(viewUrl, {
                credentials: 'same-origin',
                signal,
            });

            if (!response.ok) {
                throw new Error('Gagal mengambil dokumen Word dari server.');
            }

            const buffer = await response.arrayBuffer();
            const result = await mammoth.convertToHtml({ arrayBuffer: buffer });

            if (!signal.aborted) {
                setDocxBodyHtml(result.value || '<p><em>Dokumen kosong.</em></p>');
            }
        } catch (err) {
            if (!signal.aborted) {
                const message = err instanceof Error ? err.message : 'Gagal merender preview dokumen Word.';

                setError(message);
            }
        } finally {
            if (!signal.aborted) {
                setLoading(false);
            }
        }
    };

    const loadSpreadsheet = async (signal: AbortSignal) => {
        if (!resolvedPreviewUrl) {
            setError('Endpoint preview spreadsheet belum tersedia. Silakan unduh file untuk membukanya.');

            return;
        }

        setLoading(true);
        setError(null);

        try {
            const response = await axios.get<SpreadsheetPreviewPayload>(resolvedPreviewUrl, {
                signal,
            });

            if (!signal.aborted) {
                setSpreadsheet(response.data);
                setActiveSheetIdx(0);
                setSearchQuery('');
            }
        } catch (err) {
            if (axios.isCancel(err) || signal.aborted) {
                return;
            }

            const serverMsg = axios.isAxiosError<{ message?: string }>(err)
                ? err.response?.data?.message
                : undefined;

            setError(serverMsg || 'Gagal memuat preview spreadsheet. Silakan unduh file untuk membukanya.');
        } finally {
            if (!signal.aborted) {
                setLoading(false);
            }
        }
    };

    const handleOpenChange = (nextOpen: boolean) => {
        setOpen(nextOpen);

        if (!nextOpen) {
            if (abortRef.current) {
                abortRef.current.abort();
                abortRef.current = null;
            }

            return;
        }

        if (kind === 'docx' && !docxBodyHtml) {
            const controller = new AbortController();

            abortRef.current = controller;
            void loadDocx(controller.signal);

            return;
        }

        if (kind === 'spreadsheet' && !spreadsheet) {
            const controller = new AbortController();

            abortRef.current = controller;
            void loadSpreadsheet(controller.signal);
        }
    };

    const wordSrcDoc = useMemo(() => {
        if (!docxBodyHtml) {
            return '';
        }

        return buildWordHtmlDocument(docxBodyHtml, wordZoom);
    }, [docxBodyHtml, wordZoom]);

    const currentSheet = useMemo(() => {
        if (!spreadsheet || !spreadsheet.sheets.length) {
            return null;
        }

        return spreadsheet.sheets[activeSheetIdx] ?? spreadsheet.sheets[0];
    }, [spreadsheet, activeSheetIdx]);

    const maxColumnCount = useMemo(() => {
        if (!currentSheet) {
            return 0;
        }

        const fromRows = currentSheet.rows.reduce((max, row) => Math.max(max, row.length), 0);

        return Math.max(fromRows, currentSheet.col_count || 0, currentSheet.columns?.length || 0, 1);
    }, [currentSheet]);

    const filteredRows = useMemo(() => {
        if (!currentSheet) {
            return [];
        }

        const needle = searchQuery.trim().toLowerCase();

        return currentSheet.rows
            .map((row, idx) => ({ row, rowNumber: idx + 1 }))
            .filter(({ row }) => {
                if (!needle) {
                    return true;
                }

                return row.some((cell) => String(cell ?? '').toLowerCase().includes(needle));
            });
    }, [currentSheet, searchQuery]);

    const dialogMaxWidthClass = kind === 'spreadsheet' || kind === 'docx' || kind === 'pdf'
        ? 'max-w-5xl'
        : 'max-w-4xl';

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>
                {children}
            </DialogTrigger>
            <DialogContent className={`${dialogMaxWidthClass} w-[95vw] h-[88vh] flex flex-col p-0 overflow-hidden bg-white border-slate-200`}>
                <DialogHeader className="px-4 py-3 border-b border-slate-200 bg-slate-50 flex-shrink-0">
                    <div className="flex flex-wrap items-center justify-between gap-3 pr-8">
                        <div className="flex items-center gap-2 min-w-0">
                            {kind === 'spreadsheet' ? (
                                <span className="inline-flex items-center gap-1 rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700 border border-emerald-200 shrink-0">
                                    <FileSpreadsheet className="w-3.5 h-3.5" />
                                    Excel / CSV
                                </span>
                            ) : kind === 'docx' || kind === 'legacy_doc' ? (
                                <span className="inline-flex items-center gap-1 rounded-md bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700 border border-blue-200 shrink-0">
                                    <FileText className="w-3.5 h-3.5" />
                                    Word
                                </span>
                            ) : kind === 'pdf' ? (
                                <span className="inline-flex items-center gap-1 rounded-md bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700 border border-rose-200 shrink-0">
                                    <FileText className="w-3.5 h-3.5" />
                                    PDF
                                </span>
                            ) : null}

                            <DialogTitle className="text-sm sm:text-base font-semibold text-slate-900 truncate">
                                {attachment.original_name}
                            </DialogTitle>
                        </div>

                        <div className="flex items-center gap-2 shrink-0">
                            {kind === 'docx' && (
                                <div className="inline-flex items-center rounded-lg border border-slate-200 bg-white p-0.5">
                                    <button
                                        type="button"
                                        onClick={() => setWordZoom((z) => Math.max(75, z - 10))}
                                        className="p-1.5 text-slate-600 hover:bg-slate-100 rounded transition"
                                        title="Perkecil"
                                    >
                                        <ZoomOut className="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setWordZoom(100)}
                                        className="px-2 text-xs font-medium text-slate-700 hover:bg-slate-100 rounded transition"
                                        title="Reset zoom 100%"
                                    >
                                        {wordZoom}%
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setWordZoom((z) => Math.min(150, z + 10))}
                                        className="p-1.5 text-slate-600 hover:bg-slate-100 rounded transition"
                                        title="Perbesar"
                                    >
                                        <ZoomIn className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            )}

                            {kind === 'pdf' && (
                                <a
                                    href={viewUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 transition"
                                >
                                    <ExternalLink className="w-3.5 h-3.5" />
                                    Tab Baru
                                </a>
                            )}

                            <a
                                href={downloadUrl}
                                download={attachment.original_name}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800 transition"
                            >
                                <Download className="w-3.5 h-3.5" />
                                Unduh
                            </a>
                        </div>
                    </div>
                </DialogHeader>

                <div className="flex-1 min-h-0 flex flex-col bg-slate-100 overflow-hidden">
                    {kind === 'image' && (
                        <div className="flex-1 flex items-center justify-center p-4 overflow-auto">
                            <img
                                src={viewUrl}
                                alt={attachment.original_name}
                                className="max-w-full max-h-full object-contain rounded shadow-sm bg-white"
                            />
                        </div>
                    )}

                    {kind === 'pdf' && (
                        <iframe
                            src={viewUrl}
                            title={attachment.original_name}
                            className="w-full h-full border-0 bg-white"
                        />
                    )}

                    {kind === 'docx' && (
                        loading ? (
                            <div className="flex-1 flex flex-col items-center justify-center gap-2 text-slate-500">
                                <Loader2 className="w-7 h-7 animate-spin text-blue-600" />
                                <p className="text-sm">Memuat preview dokumen Word...</p>
                            </div>
                        ) : error ? (
                            <div className="flex-1 flex flex-col items-center justify-center gap-3 p-6 text-center">
                                <p className="text-sm text-rose-600 font-medium">{error}</p>
                                <a
                                    href={downloadUrl}
                                    download={attachment.original_name}
                                    className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white hover:bg-blue-700 transition"
                                >
                                    <Download className="w-4 h-4" />
                                    Unduh Dokumen
                                </a>
                            </div>
                        ) : (
                            <iframe
                                srcDoc={wordSrcDoc}
                                sandbox=""
                                title={attachment.original_name}
                                className="w-full h-full border-0 bg-slate-100"
                            />
                        )
                    )}

                    {kind === 'legacy_doc' && (
                        <div className="flex-1 flex flex-col items-center justify-center gap-3 p-6 text-center bg-white">
                            <div className="w-12 h-12 rounded-2xl bg-blue-50 border border-blue-200 flex items-center justify-center text-blue-600">
                                <FileText className="w-6 h-6" />
                            </div>
                            <div className="max-w-md space-y-1">
                                <p className="text-sm font-semibold text-slate-800">
                                    Format Word lama (.doc) tidak mendukung preview langsung di browser
                                </p>
                                <p className="text-xs text-slate-500">
                                    Silakan unduh file untuk membukanya dengan Microsoft Word atau LibreOffice, atau simpan ulang sebagai .docx.
                                </p>
                            </div>
                            <a
                                href={downloadUrl}
                                download={attachment.original_name}
                                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white hover:bg-blue-700 transition"
                            >
                                <Download className="w-4 h-4" />
                                Unduh {attachment.original_name}
                            </a>
                        </div>
                    )}

                    {kind === 'spreadsheet' && (
                        loading ? (
                            <div className="flex-1 flex flex-col items-center justify-center gap-2 text-slate-500 bg-white">
                                <Loader2 className="w-7 h-7 animate-spin text-emerald-600" />
                                <p className="text-sm">Membaca sheet spreadsheet...</p>
                            </div>
                        ) : error ? (
                            <div className="flex-1 flex flex-col items-center justify-center gap-3 p-6 text-center bg-white">
                                <p className="text-sm text-rose-600 font-medium">{error}</p>
                                <a
                                    href={downloadUrl}
                                    download={attachment.original_name}
                                    className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700 transition"
                                >
                                    <Download className="w-4 h-4" />
                                    Unduh Spreadsheet
                                </a>
                            </div>
                        ) : currentSheet ? (
                            <div className="flex-1 min-h-0 flex flex-col bg-white">
                                <div className="px-4 py-2.5 border-b border-slate-200 bg-slate-50 flex flex-wrap items-center justify-between gap-3">
                                    <div className="relative flex-1 min-w-[200px] max-w-sm">
                                        <Search className="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
                                        <input
                                            type="text"
                                            value={searchQuery}
                                            onChange={(e) => setSearchQuery(e.target.value)}
                                            placeholder="Cari isi sel pada sheet ini..."
                                            className="w-full rounded-lg border border-slate-300 bg-white pl-8 pr-3 py-1.5 text-xs text-slate-800 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                        />
                                    </div>

                                    <div className="text-xs text-slate-500 flex items-center gap-3">
                                        <span>
                                            Baris: <strong className="text-slate-700">{filteredRows.length}</strong> / {currentSheet.row_count}
                                        </span>
                                        <span>
                                            Kolom: <strong className="text-slate-700">{currentSheet.col_count}</strong>
                                        </span>
                                    </div>
                                </div>

                                {(currentSheet.truncated_rows || currentSheet.truncated_cols || spreadsheet?.truncated_sheets || spreadsheet?.notice) && (
                                    <div className="px-4 py-1.5 bg-amber-50 border-b border-amber-200 text-[11px] text-amber-800">
                                        {spreadsheet?.notice || 'Preview menampilkan sebagian baris/kolom awal. Unduh file untuk melihat seluruh data.'}
                                    </div>
                                )}

                                {spreadsheet && spreadsheet.sheets.length > 1 && (
                                    <div className="px-3 pt-2 border-b border-slate-200 bg-slate-100 flex items-center gap-1 overflow-x-auto shrink-0">
                                        {spreadsheet.sheets.map((sheet, idx) => {
                                            const active = idx === activeSheetIdx;

                                            return (
                                                <button
                                                    key={`${sheet.name}-${idx}`}
                                                    type="button"
                                                    onClick={() => setActiveSheetIdx(idx)}
                                                    className={`px-3 py-1.5 text-xs font-medium rounded-t-md border border-b-0 whitespace-nowrap transition ${
                                                        active
                                                            ? 'bg-white text-emerald-700 border-slate-300 shadow-sm'
                                                            : 'bg-slate-200/70 text-slate-600 border-transparent hover:bg-slate-200'
                                                    }`}
                                                >
                                                    {sheet.name}
                                                </button>
                                            );
                                        })}
                                    </div>
                                )}

                                <div className="flex-1 min-h-0 overflow-auto">
                                    {filteredRows.length === 0 ? (
                                        <div className="p-8 text-center text-xs text-slate-500">
                                            Tidak ada data yang cocok pada sheet ini.
                                        </div>
                                    ) : (
                                        <table className="border-collapse text-xs text-slate-800 min-w-full">
                                            <thead className="sticky top-0 z-10 bg-slate-100">
                                                <tr>
                                                    <th className="sticky left-0 z-20 w-12 min-w-[44px] border border-slate-300 bg-slate-200 px-2 py-1.5 text-center font-semibold text-slate-600">
                                                        #
                                                    </th>
                                                    {Array.from({ length: maxColumnCount }, (_, colIdx) => (
                                                        <th
                                                            key={colIdx}
                                                            className="border border-slate-300 bg-slate-100 px-3 py-1.5 text-center font-semibold text-slate-600 min-w-[110px]"
                                                        >
                                                            {currentSheet.columns?.[colIdx] || toColumnLetter(colIdx)}
                                                        </th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {filteredRows.map(({ row, rowNumber }) => (
                                                    <tr
                                                        key={rowNumber}
                                                        className={rowNumber % 2 === 0 ? 'bg-slate-50/70 hover:bg-emerald-50/50' : 'bg-white hover:bg-emerald-50/50'}
                                                    >
                                                        <td className="sticky left-0 z-[5] border border-slate-300 bg-slate-100 px-2 py-1.5 text-center font-mono text-[11px] text-slate-500 select-none">
                                                            {rowNumber}
                                                        </td>
                                                        {Array.from({ length: maxColumnCount }, (_, colIdx) => {
                                                            const value = row[colIdx];

                                                            return (
                                                                <td
                                                                    key={colIdx}
                                                                    className={`border border-slate-200 px-2.5 py-1.5 align-top whitespace-pre-wrap break-words max-w-[280px] ${
                                                                        rowNumber === 1 ? 'font-semibold text-slate-900 bg-slate-100/60' : ''
                                                                    }`}
                                                                >
                                                                    {value === null || value === undefined ? '' : String(value)}
                                                                </td>
                                                            );
                                                        })}
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                </div>
                            </div>
                        ) : (
                            <div className="flex-1 flex items-center justify-center text-sm text-slate-500 bg-white">
                                Sheet spreadsheet kosong.
                            </div>
                        )
                    )}

                    {kind === 'other' && (
                        <div className="flex-1 flex flex-col items-center justify-center gap-3 p-6 text-center bg-white">
                            <p className="text-sm text-slate-600">Preview tidak tersedia untuk format file ini.</p>
                            <a
                                href={downloadUrl}
                                download={attachment.original_name}
                                className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800 transition"
                            >
                                <Download className="w-4 h-4" />
                                Unduh File
                            </a>
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default AttachmentViewer;
