import React from 'react';
import { Building2, HeartHandshake, Coins, Building } from 'lucide-react';

const divisiIcons: Record<string, React.ReactNode> = {
    sekretariat: <Building2 className="w-6 h-6 mb-2" />,
    laz: <HeartHandshake className="w-6 h-6 mb-2" />,
    keuangan: <Coins className="w-6 h-6 mb-2" />,
};

function getIconForDivisi(nama: string) {
    const key = Object.keys(divisiIcons).find(k => nama.toLowerCase().includes(k));
    return key ? divisiIcons[key] : <Building className="w-6 h-6 mb-2" />;
};

interface RadioCardGridProps {
    options: any[];
    value: string | number;
    onChange: (value: string) => void;
    labelKey: string;
    valueKey?: string;
    disabled?: boolean;
    showIcon?: boolean;
    emptyMessage?: string;
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
                    <button
                        key={optValue}
                        type="button"
                        disabled={disabled}
                        onClick={() => onChange(optValue)}
                        className={`flex-1 basis-[150px] max-w-[220px] p-3 border rounded-lg text-center flex flex-col items-center justify-center transition-all min-h-[4rem] font-medium text-sm
                            ${disabled ? 'opacity-50 cursor-not-allowed bg-gray-50 border-gray-200 text-gray-400' :
                            isSelected ? 'bg-blue-600 border-blue-600 text-white shadow-md' :
                            'bg-white border-blue-600 text-blue-600 hover:bg-blue-50 hover:shadow-sm'}
                        `}
                    >
                        {showIcon && getIconForDivisi(opt[labelKey])}
                        <span>{opt[labelKey]}</span>
                    </button>
                );
            })}
        </div>
    );
}
