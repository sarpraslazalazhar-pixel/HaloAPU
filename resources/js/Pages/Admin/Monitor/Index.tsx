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

interface AdminMonitorProps {
    assets: AssetData[];
    lastUpdated: string;
}

export default function AdminMonitor({ assets, lastUpdated }: AdminMonitorProps) {
    return (
        <AdminLayout title="Live Monitor">
            <Head title="Live Monitor" />
            <div className="container mx-auto py-6">
                <MonitorGrid assets={assets} lastUpdated={lastUpdated} />
            </div>
        </AdminLayout>
    );
}
