import React from 'react';
import { cn } from '@/lib/utils';
import { Check } from 'lucide-react';
import { motion } from 'framer-motion';

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

  const progressPercentage = (activeStep / (normalizedSteps.length - 1)) * 100;

  return (
    <div className={cn("flex items-center justify-between w-full relative", className)}>
      {/* Background Track */}
      <div className="absolute top-4 left-0 w-full h-[3px] bg-slate-200 z-0 rounded-full" />
      
      {/* Animated Active Track */}
      <motion.div
        className="absolute top-4 left-0 h-[3px] bg-blue-600 z-0 rounded-full"
        initial={{ width: '0%' }}
        animate={{ width: `${progressPercentage}%` }}
        transition={{ type: 'spring', stiffness: 300, damping: 30 }}
      />

      {normalizedSteps.map((step, index) => {
        const isActive = index === activeStep;
        const isCompleted = index < activeStep;

        return (
          <div key={step.label} className="flex flex-col items-center z-10 relative flex-1 px-1">
            <motion.div
              initial={false}
              animate={{
                scale: isActive ? 1.15 : 1,
              }}
              transition={{ type: 'spring', stiffness: 400, damping: 25 }}
              className={cn(
                "w-8 h-8 rounded-full flex items-center justify-center transition-colors duration-300 ring-4 ring-white shadow-sm font-semibold text-xs",
                isCompleted
                  ? "bg-blue-600 text-white"
                  : isActive
                  ? "bg-blue-600 text-white shadow-blue-500/30"
                  : "bg-slate-200 text-slate-500"
              )}
            >
              {isCompleted ? (
                <motion.div
                  initial={{ scale: 0, rotate: -45 }}
                  animate={{ scale: 1, rotate: 0 }}
                  transition={{ type: 'spring', stiffness: 500, damping: 25 }}
                >
                  <Check className="w-4 h-4 stroke-[3]" />
                </motion.div>
              ) : (
                <span>{index + 1}</span>
              )}
            </motion.div>

            <span
              className={cn(
                "mt-2 text-xs font-medium text-center transition-colors duration-200",
                isActive || isCompleted ? "text-slate-900 font-semibold" : "text-slate-500"
              )}
            >
              {step.label}
            </span>
            {step.description && (
              <span className="text-[10px] text-slate-400 text-center mt-0.5 max-w-[96px] leading-tight hidden sm:block">
                {step.description}
              </span>
            )}
          </div>
        );
      })}
    </div>
  );
}
