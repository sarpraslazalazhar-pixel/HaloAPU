import React, { useState, useEffect } from 'react';
import { Input } from '@/components/ui/input';
import { Search, X } from 'lucide-react';
import { router } from '@inertiajs/react';
import { useDebounce } from '@/hooks/useDebounce';

interface SearchInputProps {
    placeholder?: string;
    paramName?: string;
}

export function SearchInput({ placeholder = 'Cari...', paramName = 'search' }: SearchInputProps) {
    const [value, setValue] = useState('');
    const debouncedValue = useDebounce(value, 400);

    useEffect(() => {
        router.reload({ data: { [paramName]: debouncedValue || undefined }, only: [paramName], preserveState: true });
    }, [debouncedValue, paramName]);

    return (
        <div className="relative w-full max-w-sm">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder={placeholder}
                className="pl-9 pr-8"
            />
            {value && (
                <button
                    onClick={() => setValue('')}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                >
                    <X className="h-4 w-4" />
                </button>
            )}
        </div>
    );
}
