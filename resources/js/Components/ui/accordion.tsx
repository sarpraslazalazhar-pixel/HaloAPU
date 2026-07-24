import React, { createContext, useContext, useState, useCallback, useId } from 'react';
import { cn } from '@/lib/utils';
import { ChevronDown } from 'lucide-react';

type AccordionContextType = {
 openItems: string[];
 toggleItem: (value: string) => void;
};

const AccordionContext = createContext<AccordionContextType>({
 openItems: [],
 toggleItem: () => {},
});

type ItemContextType = {
 value: string;
};

const ItemContext = createContext<ItemContextType>({ value: '' });

export function Accordion({ children, type = 'multiple', className }: { children: React.ReactNode; type?: 'single' | 'multiple'; className?: string }) {
 const [openItems, setOpenItems] = useState<string[]>([]);

 const toggleItem = useCallback((value: string) => {
 setOpenItems(prev =>
 prev.includes(value) ? prev.filter(v => v !== value) : [...prev, value]
 );
 }, []);

 return (
 <AccordionContext.Provider value={{ openItems, toggleItem }}>
 <div className={cn('space-y-2', className)}>{children}</div>
 </AccordionContext.Provider>
 );
}

export function AccordionItem({ children, value, className }: { children: React.ReactNode; value: string; className?: string }) {
 return (
 <ItemContext.Provider value={{ value }}>
 <div className={cn('border rounded-lg overflow-hidden', className)}>
 {children}
 </div>
 </ItemContext.Provider>
 );
}

export function AccordionTrigger({ children, className }: { children: React.ReactNode; className?: string }) {
 const { openItems, toggleItem } = useContext(AccordionContext);
 const { value } = useContext(ItemContext);
 const isOpen = openItems.includes(value);

 return (
 <button
 type="button"
 onClick={() => toggleItem(value)}
 className={cn(
 'flex items-center justify-between w-full px-4 py-3 text-left font-medium hover:bg-accent/50 transition-colors',
 className
 )}
 >
 {children}
 <ChevronDown className={cn('h-4 w-4 shrink-0 transition-transform duration-200', isOpen && 'rotate-180')} />
 </button>
 );
}

export function AccordionContent({ children, className }: { children: React.ReactNode; className?: string }) {
 const { openItems } = useContext(AccordionContext);
 const { value } = useContext(ItemContext);
 const isOpen = openItems.includes(value);

 return (
 <div className={cn(className, !isOpen && 'hidden')}>
 {children}
 </div>
 );
}
