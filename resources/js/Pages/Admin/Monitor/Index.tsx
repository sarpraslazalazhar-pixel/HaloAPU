import React from 'react';
import { Head } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import MonitorGrid from '@/Components/MonitorGrid';

interface AssetData {
    nama_aset: string;
    tipe: 'ruang' | 'kendaraan';
    status: 'Tersedia' | 'Dipesan' | 'Sedang Dipakai';
    user: string | null;
    waktu_mulai: string | null;
    waktu_selesai: string | null;
    booking_id: number | null;
}

interface CalendarDay {
    date: string;
    tanggal: string;
    bookings: {
        nama_aset: string;
        tipe: string;
        jam_mulai: string;
        jam_selesai: string;
        user: string;
        status: string;
    }[];
}

interface AdminMonitorProps {
    assets: AssetData[];
    calendarData: CalendarDay[];
    lastUpdated: string;
}

export default function AdminMonitor({ assets, calendarData, lastUpdated }: AdminMonitorProps) {
    return (
        <AdminLayout title="Live Monitor">
            <Head title="Live Monitor" />
            <div className="container mx-auto py-6">
                <MonitorGrid assets={assets} calendarData={calendarData || []} lastUpdated={lastUpdated} />
            </div>
        </AdminLayout>
    );
}
