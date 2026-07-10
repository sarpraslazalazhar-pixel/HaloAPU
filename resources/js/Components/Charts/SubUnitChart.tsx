import React from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';

const COLORS = ['#3b82f6', '#f59e0b', '#10b981', '#8b5cf6', '#ef4444', '#14b8a6', '#f97316', '#6366f1'];

export default function SubUnitChart({ data }: { data: { name: string; value: number }[] }) {
    if (!data?.length) return <p className="text-sm text-slate-400 text-center py-8">Tidak ada data untuk unit ini.</p>;
    return (
        <ResponsiveContainer width="100%" height={250}>
            <BarChart data={data} layout="vertical">
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis type="number" />
                <YAxis type="category" dataKey="name" width={150} />
                <Tooltip />
                <Bar dataKey="value" fill={COLORS[0]} radius={[0, 4, 4, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}
