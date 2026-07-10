import React from 'react';
import { cn } from '@/lib/utils';
import { Check } from 'lucide-react';

interface StepItem {
    label: string;
    description?: string;
}

interface StepperProps {
    steps: string[] | StepItem[];
    activeStep: number;
    className?: string;
}

export function Stepper({ steps, activeStep, className }: StepperProps) {
    const normalizedSteps: StepItem[] = steps.map((step) =>
        typeof step === 'string' ? { label: step } : step
    );

    return (
        <div className={cn("flex items-center justify-between w-full relative", className)}>
            <div className="absolute top-4 left-0 w-full h-[2px] bg-slate-200 z-0"></div>
            {normalizedSteps.map((step, index) => {
                const isActive = index === activeStep;
                const isCompleted = index < activeStep;

                return (
                    <div key={step.label} className="flex flex-col items-center z-10 relative flex-1 px-1">
                        <div className={cn(
                            "w-8 h-8 rounded-full flex items-center justify-center transition-colors duration-300 ring-4 ring-white",
                            isCompleted ? "bg-blue-600 text-white" : isActive ? "bg-blue-600 text-white" : "bg-slate-200 text-slate-500"
                        )}>
                            {isCompleted ? <Check className="w-5 h-5" /> : <span>{index + 1}</span>}
                        </div>

                        <span className={cn(
                            "mt-2 text-xs font-medium text-center",
                            isActive || isCompleted ? "text-slate-900" : "text-slate-500"
                        )}>
                            {step.label}
                        </span>
                        {step.description && (
                            <span className="text-[10px] text-slate-400 text-center mt-0.5 max-w-[96px] leading-tight">
                                {step.description}
                            </span>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
