import React, { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { formatDateId } from '@/lib/utils';
import { ChevronDown, ChevronUp, History, Info } from 'lucide-react';

export interface EditHistoryChange {
  label?: string;
  old: unknown;
  new: unknown;
}

export interface EditHistoryEntry {
  id: number;
  edit_reason?: string | null;
  changes: Record<string, EditHistoryChange>;
  created_at: string;
  editor?: { name?: string | null; username?: string | null } | null;
}

const describe = (value: unknown): string =>
  value === null || value === undefined || value === ''
    ? '(kosong)'
    : Array.isArray(value)
      ? (value.length ? value.join(', ') : '(kosong)')
      : typeof value === 'boolean'
        ? (value ? 'Ya' : 'Tidak')
        : String(value);

export function TicketEditHistory({ histories }: { histories?: EditHistoryEntry[] }) {
  const [expanded, setExpanded] = useState(false);
  if (!histories || histories.length === 0) return null;

  const latest = histories[0];
  const changedLabels = Object.values(latest.changes ?? {})
    .map((c) => c.label)
    .filter(Boolean)
    .join(', ');

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <Info className="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-600" />
          <div>
            <p className="font-semibold">
              Formulir tiket diperbarui oleh {latest.editor?.name || latest.editor?.username || 'Admin'} pada {formatDateId(latest.created_at)}
            </p>
            <p className="text-xs text-amber-800">
              {changedLabels ? `Perubahan: ${changedLabels}. ` : ''}
              Alasan: {latest.edit_reason || '-'}
            </p>
          </div>
        </div>
        <Button variant="outline" size="sm" className="border-amber-300 bg-white text-amber-900 hover:bg-amber-100" onClick={() => setExpanded((v) => !v)}>
          {expanded ? <ChevronUp className="mr-1 h-4 w-4" /> : <ChevronDown className="mr-1 h-4 w-4" />}
          {expanded ? 'Sembunyikan Riwayat' : `Riwayat Perubahan (${histories.length})`}
        </Button>
      </div>

      {expanded && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <History className="h-5 w-5" /> Riwayat Perubahan Formulir
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-5">
            {histories.map((entry) => (
              <div key={entry.id} className="border-l-2 border-slate-200 pl-4">
                <div className="flex flex-wrap items-center gap-x-2 text-sm">
                  <span className="font-semibold text-slate-800">{entry.editor?.name || entry.editor?.username || 'Admin'}</span>
                  <span className="text-xs text-slate-500">{formatDateId(entry.created_at)}</span>
                </div>
                {entry.edit_reason && (
                  <p className="mt-1 text-xs italic text-slate-600">Alasan: {entry.edit_reason}</p>
                )}
                <div className="mt-2 space-y-1.5">
                  {Object.entries(entry.changes ?? {}).map(([key, change]) => (
                    <div key={key} className="flex flex-wrap items-center gap-2 text-xs">
                      <span className="font-medium text-slate-600">{change.label || key}:</span>
                      <span className="rounded bg-rose-50 px-1.5 py-0.5 text-rose-700 line-through">
                        {describe(change.old)}
                      </span>
                      <span className="text-slate-400">➔</span>
                      <span className="rounded bg-emerald-50 px-1.5 py-0.5 font-semibold text-emerald-700">
                        {describe(change.new)}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

export default TicketEditHistory;
