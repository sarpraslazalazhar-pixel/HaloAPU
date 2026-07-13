import React from 'react';
import { Head } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
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

interface UserMonitorProps {
    assets: AssetData[];
    calendarData: CalendarDay[];
    lastUpdated: string;
}

export default function UserMonitor({ assets, calendarData, lastUpdated }: UserMonitorProps) {
    return (
        <UserLayout title="Live Monitor">
            <Head title="Live Monitor" />
            <div className="container mx-auto py-6">
                <MonitorGrid assets={assets} calendarData={calendarData || []} lastUpdated={lastUpdated} />
            </div>
        </UserLayout>
    );
}
