import React, { lazy, Suspense } from 'react';
import type { EChartsReactProps } from 'echarts-for-react';

const ReactEChartsCore = lazy(async () => {
    const [{ default: echarts }, { default: ReactEChartsCoreComponent }] = await Promise.all([
        import('@/lib/echarts'),
        import('echarts-for-react/lib/core'),
    ]);
    return {
        default: (props: EChartsReactProps) => <ReactEChartsCoreComponent echarts={echarts} {...props} />,
    };
});

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
                    className={`flex items-center justify-center rounded-lg bg-slate-100/50 dark:bg-slate-800/40 animate-pulse ${className}`}
                    style={finalStyle}
                >
                    <div className="flex flex-col items-center gap-2 text-slate-400">
                        <div className="h-6 w-6 rounded-full border-2 border-slate-300 border-t-sky-500 animate-spin" />
                        <span className="text-xs">Memuat grafik...</span>
                    </div>
                </div>
            }
        >
            <ReactEChartsCore
                option={option}
                style={finalStyle}
                className={className}
                notMerge={true}
                lazyUpdate={true}
            />
        </Suspense>
    );
}
