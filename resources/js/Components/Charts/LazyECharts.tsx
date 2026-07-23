import React, { lazy, Suspense } from 'react';

const ReactEChartsCore = lazy(() => import('echarts-for-react'));

interface LazyEChartsProps {
    option: any;
    style?: React.CSSProperties;
    className?: string;
    height?: number | string;
}

export default function LazyECharts({ option, style = {}, className = '', height = 300 }: LazyEChartsProps) {
    const finalStyle = { height, width: '100%', ...style };

    return (
        <Suspense
            fallback={
                <div
                    className={`flex items-center justify-center rounded-lg bg-slate-100/50 dark:bg-slate-900/50 animate-pulse ${className}`}
                    style={finalStyle}
                >
                    <div className="flex flex-col items-center gap-2 text-slate-400">
                        <div className="h-6 w-6 rounded-full border-2 border-slate-300 border-t-sky-500 animate-spin" />
                        <span className="text-xs">Memuat grafik...</span>
                    </div>
                </div>
            }
        >
            <ReactEChartsCore option={option} style={finalStyle} className={className} />
        </Suspense>
    );
}
