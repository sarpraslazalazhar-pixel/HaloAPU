import React from 'react';
import { Building2, HeartHandshake, Coins, Building } from 'lucide-react';
import { DynamicIcon } from '@/Components/DynamicIcon';
import { motion } from 'framer-motion';

const divisiIcons: Record<string, React.ReactNode> = {
  sekretariat: <Building2 className="w-6 h-6 mb-2 relative z-10" />,
  laz: <HeartHandshake className="w-6 h-6 mb-2 relative z-10" />,
  keuangan: <Coins className="w-6 h-6 mb-2 relative z-10" />,
};

function getIconForOption(opt: any, labelKey: string) {
  if (opt.icon) {
    return <DynamicIcon name={opt.icon} className="w-6 h-6 mb-2 relative z-10" />;
  }
  const label = opt[labelKey] || '';
  const key = Object.keys(divisiIcons).find(k => label.toLowerCase().includes(k));
  return key ? divisiIcons[key] : <Building className="w-6 h-6 mb-2 relative z-10" />;
}

interface RadioCardGridProps {
  options: any[];
  value: string | number;
  onChange: (value: string) => void;
  labelKey: string;
  valueKey?: string;
  disabled?: boolean;
  showIcon?: boolean;
  emptyMessage?: string;
  gridId?: string;
}

export function RadioCardGrid({
  options,
  value,
  onChange,
  labelKey,
  valueKey = 'id',
  disabled = false,
  showIcon = false,
  emptyMessage = 'Tidak ada pilihan tersedia.',
  gridId = 'radioGrid',
}: RadioCardGridProps) {
  if (!options?.length) {
    return <p className="text-sm text-gray-500 italic p-3 bg-slate-50 border rounded-lg">{emptyMessage}</p>;
  }

  return (
    <div className="flex flex-wrap justify-center gap-3">
      {options.map((opt: any) => {
        const optValue = String(opt[valueKey]);
        const isSelected = value === optValue;

        return (
          <motion.button
            key={optValue}
            type="button"
            disabled={disabled}
            whileHover={disabled ? undefined : { y: -3, transition: { duration: 0.15 } }}
            whileTap={disabled ? undefined : { scale: 0.96 }}
            onClick={() => onChange(optValue)}
            className={`relative flex-1 basis-[150px] max-w-[220px] p-3 border rounded-xl text-center flex flex-col items-center justify-center transition-colors min-h-[4.5rem] font-medium text-sm overflow-hidden outline-none ${
              disabled
                ? 'opacity-50 cursor-not-allowed bg-gray-50 border-gray-200 text-gray-400'
                : isSelected
                ? 'border-blue-600 text-white shadow-md shadow-blue-500/20 font-semibold'
                : 'bg-white border-blue-200 text-blue-600 hover:border-blue-500 hover:bg-blue-50/50 hover:shadow-sm'
            }`}
          >
            {/* Fluid Active Selection Background */}
            {isSelected && (
              <motion.div
                layoutId={`activeCardBg-${gridId}`}
                className="absolute inset-0 bg-blue-600 z-0"
                transition={{ type: 'spring', stiffness: 400, damping: 30 }}
              />
            )}

            <div className="relative z-10 flex flex-col items-center justify-center">
              {showIcon && getIconForOption(opt, labelKey)}
              <span className="relative z-10 leading-snug">{opt[labelKey]}</span>
            </div>
          </motion.button>
        );
      })}
    </div>
  );
}
