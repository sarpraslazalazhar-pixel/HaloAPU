import React from 'react';
import Flatpickr from 'react-flatpickr';
import 'flatpickr/dist/flatpickr.min.css';
import { Calendar } from 'lucide-react';

interface DateRangePickerProps {
    dateFrom: string;
    dateTo: string;
    onDateFromChange: (val: string) => void;
    onDateToChange: (val: string) => void;
}

export function DateRangePicker({ dateFrom, dateTo, onDateFromChange, onDateToChange }: DateRangePickerProps) {
    const dates = [];
    if (dateFrom) dates.push(new Date(dateFrom));
    if (dateTo) dates.push(new Date(dateTo));

    const handleDateChange = (selectedDates: Date[]) => {
        if (selectedDates.length === 0) {
            onDateFromChange('');
            onDateToChange('');
        } else if (selectedDates.length === 1) {
            // Adjust offset to avoid timezone shift saving wrong day
            const d1 = selectedDates[0];
            const start = new Date(d1.getTime() - (d1.getTimezoneOffset() * 60000)).toISOString().split('T')[0];
            onDateFromChange(start);
            onDateToChange('');
        } else if (selectedDates.length === 2) {
            const d1 = selectedDates[0];
            const d2 = selectedDates[1];
            const start = new Date(d1.getTime() - (d1.getTimezoneOffset() * 60000)).toISOString().split('T')[0];
            const end = new Date(d2.getTime() - (d2.getTimezoneOffset() * 60000)).toISOString().split('T')[0];
            onDateFromChange(start);
            onDateToChange(end);
        }
    };

    return (
        <div className="flex items-center gap-2 border rounded-md px-3 py-1 bg-white dark:bg-slate-900 text-sm focus-within:ring-1 focus-within:ring-primary w-full relative">
            <Calendar className="w-4 h-4 text-slate-500 shrink-0" />
            <span className="text-xs text-slate-500 shrink-0 hidden sm:inline-block">Tanggal:</span>
            <Flatpickr
                value={dates}
                onChange={handleDateChange}
                options={{
                    mode: 'range',
                    dateFormat: 'Y-m-d',
                    altInput: true,
                    altFormat: 'd/m/Y',
                    allowInput: false,
                    placeholder: 'Pilih rentang tanggal...'
                }}
                className="bg-transparent border-none outline-none text-sm w-full cursor-pointer h-8 placeholder-slate-400"
            />
        </div>
    );
}
