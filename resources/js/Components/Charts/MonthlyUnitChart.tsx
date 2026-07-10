import React from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const COLORS = ['#3b82f6', '#f59e0b', '#10b981', '#8b5cf6', '#ef4444', '#14b8a6', '#f97316', '#6366f1'];

export default function MonthlyUnitChart({ data, xKey = 'bulan' }: { data: any[]; xKey?: string }) {
    const units = data.length > 0 ? Object.keys(data[0]).filter(k => k !== xKey) : [];
    return (
        <ResponsiveContainer width="100%" height={300}>
            <BarChart data={data}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey={xKey} />
                <YAxis />
                <Tooltip />
                <Legend />
                {units.map((u, i) => (
                    <Bar key={u} dataKey={u} fill={COLORS[i % COLORS.length]} stackId="a" />
                ))}
            </BarChart>
        </ResponsiveContainer>
    );
}
