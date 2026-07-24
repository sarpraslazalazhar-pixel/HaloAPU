import React from 'react';
import LazyECharts from './LazyECharts';

const COLORS = ['#3b82f6', '#f59e0b', '#10b981', '#8b5cf6', '#ef4444', '#14b8a6', '#f97316', '#6366f1'];

export default function SubUnitChart({ data }: { data: { name: string; value: number }[] }) {
 if (!data?.length) return <p className="text-sm text-slate-400 text-center py-8">Tidak ada data untuk unit ini.</p>;

 // Sort data if needed, or keep as is. Usually horizontal bars look best sorted ascending or keeping original order but ECharts renders from bottom to top by default for category axes.
 // Let's reverse the data array so the first item appears at the top.
 const chartData = [...data].reverse();

 const option = {
 tooltip: {
 trigger: 'axis',
 axisPointer: {
 type: 'shadow'
 }
 },
 grid: {
 left: '3%',
 right: '4%',
 bottom: '3%',
 containLabel: true
 },
 xAxis: {
 type: 'value',
 boundaryGap: [0, 0.01]
 },
 yAxis: {
 type: 'category',
 data: chartData.map(d => d.name),
 axisLabel: {
 width: 150,
 overflow: 'truncate'
 }
 },
 series: [
 {
 type: 'bar',
 data: chartData.map((d, i) => ({
 value: d.value,
 itemStyle: {
 color: COLORS[i % COLORS.length],
 borderRadius: [0, 4, 4, 0]
 }
 }))
 }
 ]
 };

 return <LazyECharts option={option} height={250} />;
}
