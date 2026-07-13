import React from 'react';
import { usePoll } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Car, DoorOpen, User, Clock } from 'lucide-react';

interface AssetData {
    nama_aset: string;
    tipe: 'ruang' | 'kendaraan';
    status: 'Tersedia' | 'Dipesan' | 'Sedang Dipakai';
    user: string | null;
    waktu_mulai: string | null;
    waktu_selesai: string | null;
    booking_id: number | null;
}

interface MonitorGridProps {
    assets: AssetData[];
    lastUpdated: string;
}

const STATUS_COLORS = {
    'Tersedia': 'bg-green-500/10 border-green-500/30 dark:bg-green-500/20',
    'Dipesan': 'bg-yellow-500/10 border-yellow-500/30 dark:bg-yellow-500/20',
    'Sedang Dipakai': 'bg-red-500/10 border-red-500/30 dark:bg-red-500/20',
};

const STATUS_BADGE_COLORS = {
    'Tersedia': 'bg-green-500 text-white hover:bg-green-600',
    'Dipesan': 'bg-yellow-500 text-black hover:bg-yellow-600',
    'Sedang Dipakai': 'bg-red-500 text-white hover:bg-red-600',
};

export default function MonitorGrid({ assets = [], lastUpdated }: MonitorGridProps) {
    // Auto-refresh setiap 10 detik
    usePoll(10000);

    // Kelompokkan aset berdasarkan tipe
    const groupedAssets = assets.reduce((groups, asset) => {
        const type = asset.tipe || 'Lainnya';
        if (!groups[type]) {
            groups[type] = [];
        }
        groups[type].push(asset);
        return groups;
    }, {} as Record<string, AssetData[]>);

    const renderAssetCard = (asset: AssetData) => (
        <Card
            key={asset.nama_aset}
            className={`border-2 transition-all duration-300 ${STATUS_COLORS[asset.status]}`}
        >
            <CardHeader className="pb-2">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <CardTitle className="text-base font-semibold">{asset.nama_aset}</CardTitle>
                    </div>
                    <Badge className={STATUS_BADGE_COLORS[asset.status]}>
                        {asset.status}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent>
                {asset.status !== 'Tersedia' ? (
                    <div className="space-y-1 text-sm">
                        <div className="flex items-center gap-2">
                            <User className="h-4 w-4 text-muted-foreground" />
                            <span>{asset.user}</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <Clock className="h-4 w-4 text-muted-foreground" />
                            <span>{asset.waktu_mulai} — {asset.waktu_selesai}</span>
                        </div>
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Tidak ada booking saat ini
                    </p>
                )}
            </CardContent>
        </Card>
    );

    return (
        <div className="space-y-8">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold">Live Monitor</h1>
                    <p className="text-sm text-muted-foreground">
                        Status aset diperbarui otomatis setiap 10 detik
                    </p>
                </div>
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <div className="w-2 h-2 rounded-full bg-green-500 animate-pulse" />
                    Terakhir diperbarui: {lastUpdated}
                </div>
            </div>

            {/* Legenda */}
            <div className="flex gap-4 flex-wrap">
                <div className="flex items-center gap-2">
                    <div className="w-3 h-3 rounded-full bg-green-500" />
                    <span className="text-sm">Tersedia</span>
                </div>
                <div className="flex items-center gap-2">
                    <div className="w-3 h-3 rounded-full bg-yellow-500" />
                    <span className="text-sm">Dipesan</span>
                </div>
                <div className="flex items-center gap-2">
                    <div className="w-3 h-3 rounded-full bg-red-500" />
                    <span className="text-sm">Sedang Dipakai</span>
                </div>
            </div>

            {/* Section Dinamis */}
            {Object.keys(groupedAssets).length > 0 ? (
                Object.entries(groupedAssets).map(([tipe, items]) => (
                    <section key={tipe}>
                        <h2 className="text-lg font-semibold mb-4 flex items-center gap-2 border-b pb-2">
                            <span className="bg-primary/10 text-primary px-2 py-1 rounded text-sm">{tipe}</span>
                        </h2>
                        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                            {items.map(renderAssetCard)}
                        </div>
                    </section>
                ))
            ) : (
                <div className="text-center py-12 border rounded-lg bg-slate-50 dark:bg-slate-900/50">
                    <p className="text-muted-foreground">Tidak ada data aset untuk ditampilkan.</p>
                </div>
            )}
        </div>
    );
}
