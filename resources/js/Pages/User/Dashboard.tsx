import React from 'react';
import UserLayout from '@/Layouts/UserLayout';

export default function Dashboard() {
    return (
        <UserLayout title="Dashboard User">
            <div className="flex items-center justify-between space-y-2">
                <h2 className="text-3xl font-bold tracking-tight">Dashboard User</h2>
            </div>
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <div className="rounded-xl border bg-card text-card-foreground shadow">
                    <div className="p-6 flex flex-row items-center justify-between space-y-0 pb-2">
                        <h3 className="tracking-tight text-sm font-medium">Tiket Aktif</h3>
                    </div>
                    <div className="p-6 pt-0">
                        <div className="text-2xl font-bold">0</div>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
