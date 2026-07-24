import React from 'react';
import LazyECharts from './LazyECharts';

const COLORS = ['#3b82f6', '#f59e0b', '#10b981', '#8b5cf6', '#ef4444', '#14b8a6', '#f97316', '#6366f1'];

export default function MonthlyUnitChart({ data, xKey = 'bulan' }: { data: any[]; xKey?: string }) {
 const units = data.length > 0 ? Object.keys(data[0]).filter(k => k !== xKey) : [];

 const option = {
 tooltip: {
 trigger: 'axis',
 axisPointer: {
 type: 'shadow'
 }
 },
 legend: {
 data: units,
 bottom: 0
 },
 grid: {
 left: '3%',
 right: '4%',
 bottom: '10%',
 containLabel: true
 },
 xAxis: [
 {
 type: 'category',
 data: data.map(item => item[xKey])
 }
 ],
 yAxis: [
 {
 type: 'value'
 }
 ],
 series: units.map((u, i) => ({
 name: u,
 type: 'bar',
 stack: 'total',
 emphasis: {
 focus: 'series'
 },
 itemStyle: {
 color: COLORS[i % COLORS.length],
 borderRadius: [4, 4, 0, 0]
 },
 data: data.map(item => item[u] || 0)
 }))
 };

 return <LazyECharts option={option} height={300} />;
}
