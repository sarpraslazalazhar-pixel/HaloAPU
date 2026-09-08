import React, { useState, useEffect, useRef } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent } from '@/Components/ui/card';
import axios from 'axios';
import {
    Bot,
    Sparkles,
    Send,
    Copy,
    Check,
    Trash2,
    Shield,
    Clock,
    Star,
    BarChart2,
    Inbox,
    RefreshCw,
    Info,
    User,
    ChevronRight,
    CornerDownLeft,
    CheckCheck
} from 'lucide-react';

interface QuickMetrics {
    total_tickets: number;
    open_tickets: number;
    in_progress: number;
    avg_csat: number;
}

interface PageProps {
    hasApiKey: boolean;
    quickMetrics: QuickMetrics;
    geminiModel: string;
}

interface ChatMessage {
    id: string;
    role: 'user' | 'model';
    text: string;
    timestamp: string;
    isFallback?: boolean;
}

const STORAGE_KEY = 'haloapu_ai_bot_chat_history_v1';

const SUGGESTION_PROMPTS = [
    {
        title: 'Ringkasan Tiket',
        desc: 'Status dan tren bulan ini',
        icon: BarChart2,
        prompt: 'Berikan ringkasan eksekutif statistik tiket HaloAPU terkini, termasuk status tiket dan unit dengan beban tertinggi.',
    },
    {
        title: 'Analisis SLA',
        desc: 'Kepatuhan & keterlambatan',
        icon: Clock,
        prompt: 'Bagaimana kepatuhan SLA HaloAPU saat ini? Apakah ada tiket yang mengalami pelanggaran respon atau resolusi?',
    },
    {
        title: 'Evaluasi CSAT',
        desc: 'Rating bintang & ulasan',
        icon: Star,
        prompt: 'Rangkum laporan kepuasan pengguna (CSAT), rata-rata bintang, dan feedback terbaru dari pemohon.',
    },
    {
        title: 'Rekomendasi Layanan',
        desc: 'Saran tindakan operasional',
        icon: Sparkles,
        prompt: 'Berdasarkan data tiket dan SLA saat ini, berikan 3 rekomendasi konkret untuk meningkatkan performa layanan operasional.',
    },
];

// Helper parser Markdown yang diselaraskan dengan light theme HaloAPU
function FormattedContent({ text }: { text: string }) {
    const lines = text.split('\n');
    const elements: React.ReactNode[] = [];
    let inCodeBlock = false;
    let codeBlockContent: string[] = [];
    let tableRows: string[][] = [];
    let inTable = false;

    const flushTable = (key: string) => {
        if (tableRows.length === 0) return null;
        const header = tableRows[0];
        const body = tableRows.slice(1);
        tableRows = [];
        inTable = false;

        return (
            <div key={key} className="overflow-x-auto my-3 rounded-xl border border-zinc-200 bg-white shadow-2xs">
                <table className="w-full text-xs md:text-sm text-left border-collapse">
                    <thead>
                        <tr className="bg-zinc-50 text-zinc-700 border-b border-zinc-200">
                            {header.map((col, idx) => (
                                <th key={idx} className="py-2.5 px-3.5 font-semibold text-xs uppercase tracking-wider text-zinc-600">
                                    {renderInline(col.trim())}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-zinc-100 text-zinc-700">
                        {body.map((row, rIdx) => (
                            <tr key={rIdx} className="hover:bg-sky-50/30 transition-colors">
                                {row.map((cell, cIdx) => (
                                    <td key={cIdx} className="py-2.5 px-3.5 leading-relaxed">
                                        {renderInline(cell.trim())}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        );
    };

    const flushCodeBlock = (key: string) => {
        if (codeBlockContent.length === 0) return null;
        const code = codeBlockContent.join('\n');
        codeBlockContent = [];
        inCodeBlock = false;

        return (
            <div key={key} className="my-3 rounded-xl bg-zinc-900 border border-zinc-800 p-3.5 font-mono text-xs text-emerald-400 overflow-x-auto shadow-inner">
                <pre>{code}</pre>
            </div>
        );
    };

    const renderInline = (str: string): React.ReactNode => {
        const parts = str.split(/(\*\*.*?\*\*|\*.*?\*|`.*?`)/g);
        return parts.map((part, idx) => {
            if (part.startsWith('**') && part.endsWith('**')) {
                return <strong key={idx} className="font-semibold text-zinc-900">{part.slice(2, -2)}</strong>;
            }
            if (part.startsWith('*') && part.endsWith('*')) {
                return <em key={idx} className="italic text-zinc-800">{part.slice(1, -1)}</em>;
            }
            if (part.startsWith('`') && part.endsWith('`')) {
                return <code key={idx} className="bg-zinc-100 text-sky-700 border border-zinc-200 px-1.5 py-0.5 rounded text-xs font-mono">{part.slice(1, -1)}</code>;
            }
            return part;
        });
    };

    lines.forEach((line, index) => {
        const trimmed = line.trim();

        if (trimmed.startsWith('```')) {
            if (inCodeBlock) {
                const el = flushCodeBlock(`code-${index}`);
                if (el) elements.push(el);
            } else {
                inCodeBlock = true;
            }
            return;
        }

        if (inCodeBlock) {
            codeBlockContent.push(line);
            return;
        }

        // Table row
        if (trimmed.startsWith('|') && trimmed.endsWith('|')) {
            const cols = trimmed.split('|').slice(1, -1);
            if (cols.every(c => /^[:\-\s]+$/.test(c))) {
                return;
            }
            inTable = true;
            tableRows.push(cols);
            return;
        } else if (inTable) {
            const el = flushTable(`table-${index}`);
            if (el) elements.push(el);
        }

        // Headings
        if (trimmed.startsWith('### ')) {
            elements.push(
                <h4 key={`h3-${index}`} className="text-sm font-bold text-zinc-900 mt-4 mb-2 flex items-center gap-2">
                    <span className="w-1 h-3.5 rounded-full bg-sky-600 inline-block"></span>
                    {renderInline(trimmed.replace(/^###\s+/, ''))}
                </h4>
            );
            return;
        }
        if (trimmed.startsWith('## ')) {
            elements.push(
                <h3 key={`h2-${index}`} className="text-base font-bold text-zinc-900 mt-4 mb-2 border-b border-zinc-200 pb-1.5 flex items-center gap-2">
                    <span className="w-1.5 h-4 rounded-full bg-sky-600 inline-block"></span>
                    {renderInline(trimmed.replace(/^##\s+/, ''))}
                </h3>
            );
            return;
        }

        // Blockquotes
        if (trimmed.startsWith('> ')) {
            elements.push(
                <blockquote key={`quote-${index}`} className="my-2.5 border-l-3 border-sky-500 bg-sky-50/60 pl-3.5 py-2 text-xs md:text-sm text-zinc-700 rounded-r-lg">
                    {renderInline(trimmed.replace(/^>\s+/, ''))}
                </blockquote>
            );
            return;
        }

        // Unordered List
        if (trimmed.startsWith('- ') || trimmed.startsWith('* ')) {
            elements.push(
                <li key={`li-${index}`} className="ml-5 list-disc my-1 text-xs md:text-sm text-zinc-700 leading-relaxed">
                    {renderInline(trimmed.replace(/^[-*]\s+/, ''))}
                </li>
            );
            return;
        }

        // Numbered List
        if (/^\d+\.\s+/.test(trimmed)) {
            elements.push(
                <li key={`ol-${index}`} className="ml-5 list-decimal my-1 text-xs md:text-sm text-zinc-700 leading-relaxed">
                    {renderInline(trimmed.replace(/^\d+\.\s+/, ''))}
                </li>
            );
            return;
        }

        // Divider
        if (trimmed === '---' || trimmed === '***') {
            elements.push(<hr key={`hr-${index}`} className="my-3 border-zinc-200" />);
            return;
        }

        // Empty line
        if (trimmed === '') {
            elements.push(<div key={`empty-${index}`} className="h-1.5"></div>);
            return;
        }

        // Paragraph
        elements.push(
            <p key={`p-${index}`} className="my-1 text-xs md:text-sm text-zinc-700 leading-relaxed">
                {renderInline(line)}
            </p>
        );
    });

    if (inCodeBlock) {
        const el = flushCodeBlock('code-end');
        if (el) elements.push(el);
    }
    if (inTable) {
        const el = flushTable('table-end');
        if (el) elements.push(el);
    }

    return <div className="space-y-0.5">{elements}</div>;
}

export default function AiBotHaloAPUPage({ hasApiKey, quickMetrics, geminiModel }: PageProps) {
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const [copiedId, setCopiedId] = useState<string | null>(null);
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    const welcomeMessage: ChatMessage = {
        id: 'welcome-1',
        role: 'model',
        text: `Halo Admin! Saya **HaloAPU AI**, asisten analis data yang dirancang khusus untuk memantau **laporan tiket**, **kepatuhan SLA**, dan **kepuasan pengguna (CSAT)** di HaloAPU.\n\n` +
            (hasApiKey
                ? `⚡ **Mesin AI Aktif**: Google Gemini (\`${geminiModel}\`) terhubung langsung dengan basis data real-time HaloAPU.\n`
                : `💡 **Mode Analitik Lokal**: Kunci API belum diisi; bot menyajikan data laporan terstruktur langsung dari database internal.\n`) +
            `\nSilakan gunakan pilihan cepat di bawah atau ajukan pertanyaan terkait operasional sistem HaloAPU.`,
        timestamp: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
    };

    // Load history from LocalStorage
    useEffect(() => {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                const parsed = JSON.parse(saved);
                if (Array.isArray(parsed) && parsed.length > 0) {
                    setMessages(parsed);
                    return;
                }
            }
        } catch (e) {
            console.error('Failed to parse chat history from localStorage', e);
        }
        setMessages([welcomeMessage]);
    }, []);

    // Save to LocalStorage
    useEffect(() => {
        if (messages.length > 0) {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(messages));
            } catch (e) {
                console.error('Failed to save chat history to localStorage', e);
            }
        }
    }, [messages]);

    // Scroll to bottom
    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages, loading]);

    // Auto resize textarea
    useEffect(() => {
        if (textareaRef.current) {
            textareaRef.current.style.height = 'auto';
            textareaRef.current.style.height = `${Math.min(textareaRef.current.scrollHeight, 140)}px`;
        }
    }, [input]);

    const handleSendMessage = async (textToSend?: string) => {
        const query = (textToSend || input).trim();
        if (!query || loading) return;

        const userMsg: ChatMessage = {
            id: 'u-' + Date.now(),
            role: 'user',
            text: query,
            timestamp: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
        };

        const newHistory = [...messages, userMsg];
        setMessages(newHistory);
        setInput('');
        setLoading(true);

        const historyPayload = newHistory
            .filter(m => m.id !== 'welcome-1')
            .slice(-6)
            .map(m => ({
                role: m.role,
                text: m.text,
            }));

        try {
            const response = await axios.post('/admin/ai/chat', {
                message: query,
                history: historyPayload,
            });

            const botMsg: ChatMessage = {
                id: 'm-' + Date.now(),
                role: 'model',
                text: response.data.reply || 'Tidak ada balasan dari sistem.',
                timestamp: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
                isFallback: response.data.is_fallback,
            };

            setMessages(prev => [...prev, botMsg]);
        } catch (error: any) {
            console.error('Chat error', error);
            const errorMsg: ChatMessage = {
                id: 'err-' + Date.now(),
                role: 'model',
                text: '⚠️ Terjadi kendala saat menghubungi server AI. Silakan periksa koneksi atau coba beberapa saat lagi.',
                timestamp: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
                isFallback: true,
            };
            setMessages(prev => [...prev, errorMsg]);
        } finally {
            setLoading(false);
        }
    };

    const handleClearChat = () => {
        if (window.confirm('Bersihkan seluruh riwayat percakapan pada sesi ini?')) {
            localStorage.removeItem(STORAGE_KEY);
            setMessages([welcomeMessage]);
        }
    };

    const handleCopy = (text: string, id: string) => {
        navigator.clipboard.writeText(text);
        setCopiedId(id);
        setTimeout(() => setCopiedId(null), 2000);
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSendMessage();
        }
    };

    return (
        <AdminLayout title="HaloAPU AI (Uji Coba)">
            <Head title="HaloAPU AI (Uji Coba)" />

            <div className="space-y-4 max-w-7xl mx-auto pb-4">
                {/* Header Card / Page Title */}
                <div className="bg-white border border-zinc-200/80 rounded-2xl p-4 sm:p-5 shadow-2xs flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="h-10 w-10 rounded-xl bg-sky-500/10 border border-sky-200/60 flex items-center justify-center text-sky-600 shrink-0 shadow-2xs">
                            <Bot className="h-6 w-6" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2 flex-wrap">
                                <h1 className="text-lg md:text-xl font-bold text-zinc-900 tracking-tight">
                                    HaloAPU AI
                                </h1>
                                <Badge className="bg-sky-50 text-sky-700 border-sky-200 text-[10px] font-bold tracking-wider uppercase">
                                    Uji Coba
                                </Badge>
                                {hasApiKey ? (
                                    <Badge className="bg-emerald-50 text-emerald-700 border-emerald-200 text-[10px] font-medium flex items-center gap-1">
                                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                        {geminiModel} Terhubung
                                    </Badge>
                                ) : (
                                    <Badge className="bg-amber-50 text-amber-700 border-amber-200 text-[10px] font-medium">
                                        Mode Analitik Lokal
                                    </Badge>
                                )}
                            </div>
                            <p className="text-xs text-zinc-500 mt-0.5">
                                Asisten analitik cerdas laporan tiket, kepatuhan SLA, dan survei kepuasan pemohon.
                            </p>
                        </div>
                    </div>

                    {/* Action Controls */}
                    <div className="flex items-center gap-2 self-start md:self-auto">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={handleClearChat}
                            className="text-xs text-zinc-600 hover:text-red-600 hover:bg-red-50 border-zinc-200"
                        >
                            <Trash2 className="h-3.5 w-3.5 mr-1.5" />
                            Bersihkan Chat
                        </Button>
                    </div>
                </div>

                {/* 4 Quick Stat Cards (Consistent with HaloAPU Dashboard) */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <Card className="bg-white border-zinc-200/80 shadow-2xs">
                        <CardContent className="p-3.5 flex items-center justify-between">
                            <div>
                                <p className="text-[11px] font-medium text-zinc-500 uppercase tracking-wider">Total Tiket</p>
                                <p className="text-xl font-bold text-zinc-900 mt-0.5">{quickMetrics.total_tickets}</p>
                            </div>
                            <div className="h-8 w-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center">
                                <Inbox className="h-4 w-4" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="bg-white border-zinc-200/80 shadow-2xs">
                        <CardContent className="p-3.5 flex items-center justify-between">
                            <div>
                                <p className="text-[11px] font-medium text-zinc-500 uppercase tracking-wider">Menunggu (Open)</p>
                                <p className="text-xl font-bold text-amber-600 mt-0.5">{quickMetrics.open_tickets}</p>
                            </div>
                            <div className="h-8 w-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
                                <Clock className="h-4 w-4" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="bg-white border-zinc-200/80 shadow-2xs">
                        <CardContent className="p-3.5 flex items-center justify-between">
                            <div>
                                <p className="text-[11px] font-medium text-zinc-500 uppercase tracking-wider">Diproses</p>
                                <p className="text-xl font-bold text-sky-600 mt-0.5">{quickMetrics.in_progress}</p>
                            </div>
                            <div className="h-8 w-8 rounded-lg bg-sky-50 text-sky-600 flex items-center justify-center">
                                <RefreshCw className="h-4 w-4" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="bg-white border-zinc-200/80 shadow-2xs">
                        <CardContent className="p-3.5 flex items-center justify-between">
                            <div>
                                <p className="text-[11px] font-medium text-zinc-500 uppercase tracking-wider">Skor CSAT</p>
                                <p className="text-xl font-bold text-yellow-500 mt-0.5">{quickMetrics.avg_csat} / 5.0</p>
                            </div>
                            <div className="h-8 w-8 rounded-lg bg-yellow-50 text-yellow-500 flex items-center justify-center">
                                <Star className="h-4 w-4 fill-yellow-400/30" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Main Chat Box Container */}
                <div className="bg-white border border-zinc-200/80 rounded-2xl shadow-xs overflow-hidden flex flex-col h-[calc(100vh-340px)] min-h-[500px]">
                    {/* Chat Messages Body */}
                    <div className="flex-1 overflow-y-auto p-4 sm:p-6 space-y-4 bg-zinc-50/40">
                        {messages.map((msg) => (
                            <div
                                key={msg.id}
                                className={`flex gap-3 ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                            >
                                {msg.role === 'model' && (
                                    <div className="h-8 w-8 rounded-full bg-sky-50 border border-sky-200 text-sky-600 flex items-center justify-center shrink-0 shadow-2xs mt-0.5">
                                        <Bot className="h-4 w-4" />
                                    </div>
                                )}

                                <div className={`relative max-w-[88%] sm:max-w-[78%] group`}>
                                    <div
                                        className={`rounded-2xl px-4 py-3 text-sm shadow-2xs ${
                                            msg.role === 'user'
                                                ? 'bg-sky-600 text-white rounded-tr-xs shadow-sky-600/10'
                                                : 'bg-white border border-zinc-200 text-zinc-800 rounded-tl-xs'
                                        }`}
                                    >
                                        {/* Sender & Timestamp Header */}
                                        <div className="flex items-center justify-between gap-4 mb-1 text-[11px] opacity-75">
                                            <span className="font-semibold">
                                                {msg.role === 'user' ? 'Anda (Admin)' : 'HaloAPU AI'}
                                            </span>
                                            <span>{msg.timestamp}</span>
                                        </div>

                                        {/* Message Content */}
                                        <div className={msg.role === 'user' ? 'text-white' : 'text-zinc-800'}>
                                            {msg.role === 'user' ? (
                                                <p className="whitespace-pre-wrap leading-relaxed text-sm">{msg.text}</p>
                                            ) : (
                                                <FormattedContent text={msg.text} />
                                            )}
                                        </div>

                                        {/* AI Response Footer: Model Info & Copy */}
                                        {msg.role === 'model' && (
                                            <div className="mt-3 pt-2 border-t border-zinc-100 flex items-center justify-between text-xs text-zinc-400">
                                                <div className="flex items-center gap-1.5 text-[11px]">
                                                    {msg.isFallback ? (
                                                        <span className="inline-flex items-center gap-1 text-amber-600">
                                                            <Info className="h-3 w-3" />
                                                            Database Internal
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 text-sky-600 font-medium">
                                                            <Sparkles className="h-3 w-3" />
                                                            Google Gemini AI
                                                        </span>
                                                    )}
                                                </div>

                                                <button
                                                    onClick={() => handleCopy(msg.text, msg.id)}
                                                    className="inline-flex items-center gap-1 px-2 py-1 rounded-md hover:bg-zinc-100 text-zinc-500 hover:text-zinc-800 transition text-[11px] border border-transparent hover:border-zinc-200"
                                                    title="Salin isi laporan"
                                                >
                                                    {copiedId === msg.id ? (
                                                        <>
                                                            <Check className="h-3 w-3 text-emerald-600" />
                                                            <span className="text-emerald-600 font-medium">Tersalin</span>
                                                        </>
                                                    ) : (
                                                        <>
                                                            <Copy className="h-3 w-3" />
                                                            <span>Salin</span>
                                                        </>
                                                    )}
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {msg.role === 'user' && (
                                    <div className="h-8 w-8 rounded-full bg-zinc-200 border border-zinc-300 flex items-center justify-center shrink-0 font-bold text-xs text-zinc-700 mt-0.5">
                                        <User className="h-4 w-4 text-zinc-600" />
                                    </div>
                                )}
                            </div>
                        ))}

                        {/* Typing / Loading Animation */}
                        {loading && (
                            <div className="flex gap-3 justify-start">
                                <div className="h-8 w-8 rounded-full bg-sky-50 border border-sky-200 text-sky-600 flex items-center justify-center shrink-0 shadow-2xs">
                                    <Bot className="h-4 w-4" />
                                </div>
                                <div className="bg-white border border-zinc-200 rounded-2xl rounded-tl-xs px-4 py-3 text-xs text-zinc-500 flex items-center gap-2 shadow-2xs">
                                    <span className="flex gap-1 items-center">
                                        <span className="w-1.5 h-1.5 bg-sky-500 rounded-full animate-bounce [animation-delay:-0.3s]" />
                                        <span className="w-1.5 h-1.5 bg-sky-500 rounded-full animate-bounce [animation-delay:-0.15s]" />
                                        <span className="w-1.5 h-1.5 bg-sky-500 rounded-full animate-bounce" />
                                    </span>
                                    <span>AI sedang menganalisis data laporan HaloAPU...</span>
                                </div>
                            </div>
                        )}

                        <div ref={messagesEndRef} />
                    </div>

                    {/* Quick Suggestion Chips */}
                    <div className="bg-white border-t border-zinc-100 px-4 py-2.5">
                        <div className="flex items-center gap-2 overflow-x-auto no-scrollbar py-0.5">
                            <span className="text-[11px] font-semibold text-zinc-400 uppercase tracking-wider shrink-0 flex items-center gap-1">
                                <Sparkles className="h-3 w-3 text-sky-600" />
                                Pertanyaan Cepat:
                            </span>
                            {SUGGESTION_PROMPTS.map((item, idx) => {
                                const Icon = item.icon;
                                return (
                                    <button
                                        key={idx}
                                        disabled={loading}
                                        onClick={() => handleSendMessage(item.prompt)}
                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-zinc-50 hover:bg-sky-50 text-zinc-700 hover:text-sky-700 border border-zinc-200 hover:border-sky-300 text-xs font-medium whitespace-nowrap transition-all shadow-2xs shrink-0 active:scale-95 disabled:opacity-50"
                                    >
                                        <Icon className="h-3.5 w-3.5 text-sky-600" />
                                        <span>{item.title}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {/* Chat Input Section */}
                    <div className="border-t border-zinc-200/80 bg-white p-3 sm:p-4">
                        <div className="flex items-end gap-2 bg-zinc-50/80 rounded-xl border border-zinc-200 focus-within:border-sky-500 focus-within:bg-white focus-within:ring-2 focus-within:ring-sky-100 transition-all p-2">
                            <textarea
                                ref={textareaRef}
                                value={input}
                                onChange={(e) => setInput(e.target.value)}
                                onKeyDown={handleKeyDown}
                                disabled={loading}
                                rows={1}
                                placeholder="Ketik pertanyaan terkait data tiket, kepatuhan SLA, atau kepuasan CSAT (Tekan Enter untuk kirim)..."
                                className="flex-1 bg-transparent border-0 resize-none text-zinc-900 placeholder:text-zinc-400 text-xs sm:text-sm focus:outline-none focus:ring-0 px-2 py-1 max-h-32 overflow-y-auto leading-relaxed"
                            />

                            <Button
                                onClick={() => handleSendMessage()}
                                disabled={!input.trim() || loading}
                                size="sm"
                                className="bg-sky-600 hover:bg-sky-700 text-white rounded-lg h-9 w-9 p-0 shrink-0 shadow-xs"
                            >
                                <Send className="h-4 w-4" />
                            </Button>
                        </div>

                        <div className="flex items-center justify-between text-[11px] text-zinc-400 mt-2 px-1">
                            <div className="flex items-center gap-1.5">
                                <Shield className="h-3 w-3 text-sky-600" />
                                <span>Strict Domain Guardrail: Khusus data operasional & laporan sistem HaloAPU</span>
                            </div>
                            <span className="hidden sm:inline">Shift + Enter untuk baris baru</span>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
