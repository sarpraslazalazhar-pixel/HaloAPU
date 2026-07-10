import React from 'react';

interface DateRangePickerProps {
    dateFrom: string;
    dateTo: string;
    onDateFromChange: (val: string) => void;
    onDateToChange: (val: string) => void;
}

export function DateRangePicker({ dateFrom, dateTo, onDateFromChange, onDateToChange }: DateRangePickerProps) {
    return (
        <div className="flex items-center gap-2 border rounded-md px-3 py-2 text-sm">
            <span className="text-xs text-slate-500 shrink-0">Tanggal:</span>
            <input
                type="date"
                className="bg-transparent border-none outline-none text-sm w-full"
                value={dateFrom}
                onChange={e => onDateFromChange(e.target.value)}
                placeholder="Dari"
            />
            <span className="text-slate-400">-</span>
            <input
                type="date"
                className="bg-transparent border-none outline-none text-sm w-full"
                value={dateTo}
                onChange={e => onDateToChange(e.target.value)}
                placeholder="Sampai"
            />
        </div>
    );
}
