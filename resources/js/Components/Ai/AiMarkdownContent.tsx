import React from 'react';
import { Link } from '@inertiajs/react';

export interface AiMarkdownContentProps {
    content: string;
}

function isSafeInternalAdminHref(rawHref: string): boolean {
    const trimmed = rawHref.trim();

    if (!trimmed.startsWith('/admin/')) {
        return false;
    }

    if (trimmed.startsWith('//')) {
        return false;
    }

    if (/[\s<>"'`\\]/.test(trimmed)) {
        return false;
    }

    return true;
}

function isTableSeparatorRow(cols: string[]): boolean {
    if (cols.length === 0) {
        return false;
    }

    for (const col of cols) {
        if (!/^[:\-\s]+$/.test(col.trim())) {
            return false;
        }
    }

    return true;
}

function renderFormattedText(text: string, keyPrefix: string): React.ReactNode {
    const parts = text.split(/(\[[^\]]+\]\([^)]+\)|\*\*[^*]+\*\*|\*[^*]+\*|`[^`]+`)/g);
    const nodes: React.ReactNode[] = [];

    for (let idx = 0; idx < parts.length; idx += 1) {
        const part = parts[idx];

        if (!part) {
            continue;
        }

        const linkMatch = /^\[([^\]]+)\]\(([^)]+)\)$/.exec(part);

        if (linkMatch !== null) {
            const label = linkMatch[1];
            const href = linkMatch[2].trim();

            if (isSafeInternalAdminHref(href)) {
                nodes.push(
                    <Link
                        key={`${keyPrefix}-link-${idx}`}
                        href={href}
                        className="font-medium text-sky-600 underline decoration-sky-300 underline-offset-2 transition-colors hover:text-sky-700 hover:decoration-sky-600"
                    >
                        {label}
                    </Link>
                );
            } else {
                nodes.push(<span key={`${keyPrefix}-text-${idx}`}>{label}</span>);
            }

            continue;
        }

        if (part.startsWith('**') && part.endsWith('**') && part.length > 4) {
            nodes.push(
                <strong key={`${keyPrefix}-bold-${idx}`} className="font-semibold text-zinc-900">
                    {part.slice(2, -2)}
                </strong>
            );

            continue;
        }

        if (part.startsWith('*') && part.endsWith('*') && part.length > 2) {
            nodes.push(
                <em key={`${keyPrefix}-em-${idx}`} className="italic text-zinc-800">
                    {part.slice(1, -1)}
                </em>
            );

            continue;
        }

        if (part.startsWith('`') && part.endsWith('`') && part.length > 2) {
            nodes.push(
                <code
                    key={`${keyPrefix}-code-${idx}`}
                    className="rounded border border-zinc-200 bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-sky-700"
                >
                    {part.slice(1, -1)}
                </code>
            );

            continue;
        }

        nodes.push(part);
    }

    return nodes;
}

export default function AiMarkdownContent({ content }: AiMarkdownContentProps) {
    const normalized = content.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    const lines = normalized.split('\n');
    const elements: React.ReactNode[] = [];

    let inCodeBlock = false;
    let codeBlockLines: string[] = [];
    let tableRows: string[][] = [];
    let listItems: string[] = [];
    let listKind: 'ul' | 'ol' | null = null;
    let blockCounter = 0;

    const nextKey = (prefix: string): string => {
        blockCounter += 1;

        return `${prefix}-${blockCounter}`;
    };

    const flushCodeBlock = () => {
        if (codeBlockLines.length === 0) {
            inCodeBlock = false;

            return;
        }

        const code = codeBlockLines.join('\n');
        codeBlockLines = [];
        inCodeBlock = false;

        elements.push(
            <div
                key={nextKey('code')}
                className="my-2.5 overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-900 p-3.5 font-mono text-xs text-emerald-400 shadow-inner"
            >
                <pre className="whitespace-pre-wrap break-words">{code}</pre>
            </div>
        );
    };

    const flushTable = () => {
        if (tableRows.length === 0) {
            return;
        }

        const rows = tableRows;
        tableRows = [];
        const header = rows[0];
        const bodyRows: string[][] = [];

        for (let i = 1; i < rows.length; i += 1) {
            if (i === 1 && isTableSeparatorRow(rows[i])) {
                continue;
            }

            bodyRows.push(rows[i]);
        }

        const tableKey = nextKey('table');

        elements.push(
            <div key={tableKey} className="my-3 overflow-x-auto rounded-xl border border-zinc-200 bg-white shadow-2xs">
                <table className="w-full border-collapse text-left text-xs md:text-sm">
                    <thead>
                        <tr className="border-b border-zinc-200 bg-zinc-50 text-zinc-700">
                            {header.map((col, idx) => (
                                <th
                                    key={`${tableKey}-th-${idx}`}
                                    className="px-3.5 py-2.5 text-[11px] font-semibold tracking-wider text-zinc-600 uppercase"
                                >
                                    {renderFormattedText(col.trim(), `${tableKey}-th-${idx}`)}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-zinc-100 text-zinc-700">
                        {bodyRows.map((row, rIdx) => (
                            <tr key={`${tableKey}-tr-${rIdx}`} className="transition-colors hover:bg-sky-50/40">
                                {row.map((cell, cIdx) => (
                                    <td key={`${tableKey}-td-${rIdx}-${cIdx}`} className="px-3.5 py-2 leading-relaxed">
                                        {renderFormattedText(cell.trim(), `${tableKey}-td-${rIdx}-${cIdx}`)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        );
    };

    const flushList = () => {
        if (listItems.length === 0 || listKind === null) {
            listItems = [];
            listKind = null;

            return;
        }

        const items = listItems;
        const kind = listKind;
        listItems = [];
        listKind = null;
        const listKey = nextKey(kind);

        if (kind === 'ol') {
            elements.push(
                <ol key={listKey} className="my-1.5 ml-5 list-decimal space-y-1 text-xs leading-relaxed text-zinc-700 md:text-sm">
                    {items.map((item, idx) => (
                        <li key={`${listKey}-li-${idx}`}>{renderFormattedText(item, `${listKey}-li-${idx}`)}</li>
                    ))}
                </ol>
            );
        } else {
            elements.push(
                <ul key={listKey} className="my-1.5 ml-5 list-disc space-y-1 text-xs leading-relaxed text-zinc-700 md:text-sm">
                    {items.map((item, idx) => (
                        <li key={`${listKey}-li-${idx}`}>{renderFormattedText(item, `${listKey}-li-${idx}`)}</li>
                    ))}
                </ul>
            );
        }
    };

    for (let idx = 0; idx < lines.length; idx += 1) {
        const line = lines[idx];
        const trimmed = line.trim();

        if (trimmed.startsWith('```')) {
            if (inCodeBlock) {
                flushCodeBlock();
            } else {
                flushTable();
                flushList();
                inCodeBlock = true;
            }

            continue;
        }

        if (inCodeBlock) {
            codeBlockLines.push(line);

            continue;
        }

        if (trimmed.startsWith('|') && trimmed.endsWith('|') && trimmed.length > 2) {
            flushList();
            const cols = trimmed.split('|').slice(1, -1);
            tableRows.push(cols);

            continue;
        }

        if (tableRows.length > 0) {
            flushTable();
        }

        if (trimmed.startsWith('- ') || trimmed.startsWith('* ')) {
            if (listKind === 'ol') {
                flushList();
            }

            listKind = 'ul';
            listItems.push(trimmed.slice(2).trim());

            continue;
        }

        const orderedMatch = /^\d+\.\s+(.+)$/.exec(trimmed);

        if (orderedMatch !== null) {
            if (listKind === 'ul') {
                flushList();
            }

            listKind = 'ol';
            listItems.push(orderedMatch[1].trim());

            continue;
        }

        if (listItems.length > 0) {
            flushList();
        }

        if (trimmed.startsWith('### ')) {
            const key = nextKey('h3');

            elements.push(
                <h4 key={key} className="mt-3.5 mb-1.5 flex items-center gap-2 text-sm font-bold text-zinc-900">
                    <span className="inline-block h-3.5 w-1 rounded-full bg-sky-600" />
                    <span>{renderFormattedText(trimmed.slice(4).trim(), key)}</span>
                </h4>
            );

            continue;
        }

        if (trimmed.startsWith('## ')) {
            const key = nextKey('h2');

            elements.push(
                <h3
                    key={key}
                    className="mt-4 mb-2 flex items-center gap-2 border-b border-zinc-200 pb-1.5 text-base font-bold text-zinc-900"
                >
                    <span className="inline-block h-4 w-1.5 rounded-full bg-sky-600" />
                    <span>{renderFormattedText(trimmed.slice(3).trim(), key)}</span>
                </h3>
            );

            continue;
        }

        if (trimmed.startsWith('# ')) {
            const key = nextKey('h1');

            elements.push(
                <h2
                    key={key}
                    className="mt-4 mb-2 border-b border-zinc-200 pb-1.5 text-base font-bold text-zinc-900 md:text-lg"
                >
                    {renderFormattedText(trimmed.slice(2).trim(), key)}
                </h2>
            );

            continue;
        }

        if (trimmed.startsWith('> ')) {
            const key = nextKey('quote');

            elements.push(
                <blockquote
                    key={key}
                    className="my-2.5 rounded-r-lg border-l-3 border-sky-500 bg-sky-50/70 py-2 pr-3 pl-3.5 text-xs leading-relaxed text-zinc-700 md:text-sm"
                >
                    {renderFormattedText(trimmed.slice(2).trim(), key)}
                </blockquote>
            );

            continue;
        }

        if (trimmed === '---' || trimmed === '***') {
            elements.push(<hr key={nextKey('hr')} className="my-3 border-zinc-200" />);

            continue;
        }

        if (trimmed === '') {
            elements.push(<div key={nextKey('sp')} className="h-1.5" />);

            continue;
        }

        const pKey = nextKey('p');

        elements.push(
            <p key={pKey} className="my-1 text-xs leading-relaxed text-zinc-700 md:text-sm">
                {renderFormattedText(line, pKey)}
            </p>
        );
    }

    if (inCodeBlock) {
        flushCodeBlock();
    }

    if (tableRows.length > 0) {
        flushTable();
    }

    if (listItems.length > 0) {
        flushList();
    }

    return <div className="space-y-0.5">{elements}</div>;
}
