import React, { useMemo } from 'react';
import LazyECharts from '@/Components/Charts/LazyECharts';
import { BarChart3 } from 'lucide-react';

export interface AiChartSeries {
    name: string;
    data: number[];
    color?: string;
}

export interface AiChartSpec {
    id: string;
    type: 'bar' | 'line' | 'pie';
    title: string;
    subtitle?: string;
    labels: string[];
    series: AiChartSeries[];
    unit?: string;
}

export interface AiChartCardProps {
    chart: AiChartSpec;
}

interface PieDatum {
    name: string;
    value: number;
    itemStyle: {
        color: string;
    };
}

interface CartesianSeriesItem {
    name: string;
    type: 'bar' | 'line';
    data: number[];
    barMaxWidth?: number;
    smooth?: boolean;
    symbolSize?: number;
    itemStyle: {
        color: string;
        borderRadius?: number[];
    };
    lineStyle?: {
        width: number;
        color: string;
    };
    areaStyle?: {
        color: string;
        opacity: number;
    };
}

interface PieSeriesItem {
    name: string;
    type: 'pie';
    radius: [string, string];
    center: [string, string];
    avoidLabelOverlap: boolean;
    itemStyle: {
        borderRadius: number;
        borderColor: string;
        borderWidth: number;
    };
    label: {
        show: boolean;
    };
    emphasis: {
        label: {
            show: boolean;
            fontSize: number;
            fontWeight: string;
        };
    };
    data: PieDatum[];
}

interface EChartsCartesianOption {
    color: string[];
    tooltip: {
        trigger: 'axis';
        backgroundColor: string;
        borderColor: string;
        textStyle: {
            color: string;
            fontSize: number;
        };
    };
    legend?: {
        bottom: number;
        type: 'scroll';
        textStyle: {
            color: string;
            fontSize: number;
        };
    };
    grid: {
        top: number;
        right: number;
        bottom: number;
        left: number;
        containLabel: boolean;
    };
    xAxis: {
        type: 'category';
        data: string[];
        axisLabel: {
            color: string;
            fontSize: number;
            interval: number;
            rotate: number;
        };
        axisLine: {
            lineStyle: {
                color: string;
            };
        };
    };
    yAxis: {
        type: 'value';
        name?: string;
        nameTextStyle?: {
            color: string;
            fontSize: number;
        };
        axisLabel: {
            color: string;
            fontSize: number;
        };
        splitLine: {
            lineStyle: {
                color: string;
            };
        };
    };
    series: CartesianSeriesItem[];
}

interface EChartsPieOption {
    color: string[];
    tooltip: {
        trigger: 'item';
        backgroundColor: string;
        borderColor: string;
        textStyle: {
            color: string;
            fontSize: number;
        };
        formatter: string;
    };
    legend: {
        bottom: number;
        type: 'scroll';
        textStyle: {
            color: string;
            fontSize: number;
        };
    };
    series: PieSeriesItem[];
}

const DEFAULT_PALETTE = ['#0284c7', '#10b981', '#f59e0b', '#6366f1', '#ef4444', '#64748b'];

function buildPieOption(chart: AiChartSpec): EChartsPieOption {
    const firstSeries = chart.series[0];
    const pieData: PieDatum[] = [];

    for (let idx = 0; idx < chart.labels.length; idx += 1) {
        const label = chart.labels[idx];
        const value = firstSeries && idx < firstSeries.data.length ? firstSeries.data[idx] : 0;

        pieData.push({
            name: label,
            value,
            itemStyle: {
                color: DEFAULT_PALETTE[idx % DEFAULT_PALETTE.length],
            },
        });
    }

    const unitSuffix = chart.unit ? ` ${chart.unit}` : '';

    return {
        color: DEFAULT_PALETTE,
        tooltip: {
            trigger: 'item',
            backgroundColor: '#ffffff',
            borderColor: '#e4e4e7',
            textStyle: {
                color: '#18181b',
                fontSize: 12,
            },
            formatter: `{b}: <b>{c}${unitSuffix}</b> ({d}%)`,
        },
        legend: {
            bottom: 0,
            type: 'scroll',
            textStyle: {
                color: '#52525b',
                fontSize: 11,
            },
        },
        series: [
            {
                name: firstSeries ? firstSeries.name : chart.title,
                type: 'pie',
                radius: ['44%', '70%'],
                center: ['50%', '45%'],
                avoidLabelOverlap: true,
                itemStyle: {
                    borderRadius: 6,
                    borderColor: '#ffffff',
                    borderWidth: 2,
                },
                label: {
                    show: false,
                },
                emphasis: {
                    label: {
                        show: true,
                        fontSize: 12,
                        fontWeight: 'bold',
                    },
                },
                data: pieData,
            },
        ],
    };
}

function buildCartesianOption(chart: AiChartSpec): EChartsCartesianOption {
    const builtSeries: CartesianSeriesItem[] = [];
    const paletteColors: string[] = [];

    for (let idx = 0; idx < chart.series.length; idx += 1) {
        const item = chart.series[idx];
        const color = item.color ?? DEFAULT_PALETTE[idx % DEFAULT_PALETTE.length];
        paletteColors.push(color);

        if (chart.type === 'line') {
            builtSeries.push({
                name: item.name,
                type: 'line',
                data: item.data,
                smooth: true,
                symbolSize: 6,
                itemStyle: {
                    color,
                },
                lineStyle: {
                    width: 2.5,
                    color,
                },
                areaStyle: chart.series.length === 1
                    ? {
                        color,
                        opacity: 0.12,
                    }
                    : undefined,
            });
        } else {
            builtSeries.push({
                name: item.name,
                type: 'bar',
                data: item.data,
                barMaxWidth: 34,
                itemStyle: {
                    color,
                    borderRadius: [6, 6, 0, 0],
                },
            });
        }
    }

    const hasMultipleSeries = chart.series.length > 1;

    return {
        color: paletteColors,
        tooltip: {
            trigger: 'axis',
            backgroundColor: '#ffffff',
            borderColor: '#e4e4e7',
            textStyle: {
                color: '#18181b',
                fontSize: 12,
            },
        },
        legend: hasMultipleSeries
            ? {
                bottom: 0,
                type: 'scroll',
                textStyle: {
                    color: '#52525b',
                    fontSize: 11,
                },
            }
            : undefined,
        grid: {
            top: chart.unit ? 28 : 16,
            right: 14,
            bottom: hasMultipleSeries ? 36 : 16,
            left: 12,
            containLabel: true,
        },
        xAxis: {
            type: 'category',
            data: chart.labels,
            axisLabel: {
                color: '#52525b',
                fontSize: 11,
                interval: 0,
                rotate: chart.labels.length > 5 ? 18 : 0,
            },
            axisLine: {
                lineStyle: {
                    color: '#e4e4e7',
                },
            },
        },
        yAxis: {
            type: 'value',
            name: chart.unit,
            nameTextStyle: chart.unit
                ? {
                    color: '#71717a',
                    fontSize: 10,
                }
                : undefined,
            axisLabel: {
                color: '#71717a',
                fontSize: 11,
            },
            splitLine: {
                lineStyle: {
                    color: '#f4f4f5',
                },
            },
        },
        series: builtSeries,
    };
}

export default function AiChartCard({ chart }: AiChartCardProps) {
    const option = useMemo(() => {
        if (chart.type === 'pie') {
            return buildPieOption(chart);
        }

        return buildCartesianOption(chart);
    }, [chart]);

    if (chart.labels.length === 0 || chart.series.length === 0) {
        return null;
    }

    return (
        <div className="my-3 rounded-xl border border-zinc-200/90 bg-zinc-50/40 p-3.5 shadow-2xs">
            <div className="mb-2 flex items-start justify-between gap-2">
                <div className="flex items-start gap-2">
                    <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-sky-200 bg-sky-50 text-sky-600">
                        <BarChart3 className="h-3.5 w-3.5" />
                    </div>
                    <div>
                        <h4 className="text-xs font-semibold text-zinc-900 md:text-sm">{chart.title}</h4>
                        {chart.subtitle && <p className="text-[11px] text-zinc-500">{chart.subtitle}</p>}
                    </div>
                </div>
                {chart.unit && (
                    <span className="shrink-0 rounded-full border border-zinc-200 bg-white px-2 py-0.5 text-[10px] font-medium text-zinc-500">
                        {chart.unit}
                    </span>
                )}
            </div>

            <div className="rounded-lg border border-zinc-100 bg-white p-2">
                <LazyECharts option={option} height={240} />
            </div>
        </div>
    );
}
