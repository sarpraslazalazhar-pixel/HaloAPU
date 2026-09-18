import React, { lazy, Suspense } from 'react';
import type { EChartsReactProps } from 'echarts-for-react';

const ReactEChartsCore = lazy(async () => {
    const [echartsModule, coreModule] = await Promise.all([
        import('@/lib/echarts'),
        import('echarts-for-react/lib/core'),
    ]);

    // SAFETY: Dynamic import of @/lib/echarts may export the ECharts instance as `.default` or directly; the `as any` is required to handle both module formats.
    const echarts = (echartsModule as any)?.default || echartsModule;
    // SAFETY: echarts-for-react/lib/core may double-wrap its export in `.default.default` depending on the bundler; the `as any` is required to handle both CJS and ESM output.
    const CoreComponent = (coreModule as any)?.default?.default || (coreModule as any)?.default || coreModule;

    return {
        default: (props: EChartsReactProps) => <CoreComponent echarts={echarts} {...props} />,
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
