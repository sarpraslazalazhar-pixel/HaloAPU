import React, { useState } from 'react';
import { Input } from '@/Components/ui/input';
import { Search, X } from 'lucide-react';
import { router } from '@inertiajs/react';

interface SearchInputProps {
  placeholder?: string;
  paramName?: string;
  defaultValue?: string;
  className?: string;
}

export function SearchInput({ placeholder = 'Cari...', paramName = 'search', defaultValue, className }: SearchInputProps) {
  const [value, setValue] = useState(() => {
    if (defaultValue !== undefined) return defaultValue;
    if (typeof window !== 'undefined') {
      return new URLSearchParams(window.location.search).get(paramName) || '';
    }
    return '';
  });

  const executeSearch = (term: string) => {
    const url = new URL(window.location.href);
    const currentParam = url.searchParams.get(paramName) || '';
    const cleanTerm = term.trim();
    if (cleanTerm === currentParam) {
      return;
    }

    if (cleanTerm) {
      url.searchParams.set(paramName, cleanTerm);
    } else {
      url.searchParams.delete(paramName);
    }
    url.searchParams.delete('page');

    router.get(url.pathname + url.search, {}, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      executeSearch(value);
    }
  };

  const handleClear = () => {
    setValue('');
    executeSearch('');
  };

  return (
    <div className={`relative w-full max-w-sm ${className || ''}`}>
      <button
        type="button"
        onClick={() => executeSearch(value)}
        className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
      >
        <Search className="h-4 w-4" />
      </button>
      <Input
        value={value}
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={handleKeyDown}
        placeholder={placeholder}
        className="pl-9 pr-8"
      />
      {value && (
        <button
          type="button"
          onClick={handleClear}
          className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
        >
          <X className="h-4 w-4" />
        </button>
      )}
    </div>
  );
}
