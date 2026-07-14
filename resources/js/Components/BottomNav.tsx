import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export interface BottomNavItem {
  label: string;
  icon: React.ElementType;
  route?: string;
  onClick?: () => void;
  badge?: React.ReactNode;
}

interface BottomNavProps {
  items: BottomNavItem[];
  className?: string;
}

export function BottomNav({ items, className }: BottomNavProps) {
  const url = usePage().url;

  return (
    <nav
      className={cn(
        'fixed bottom-0 inset-x-0 z-50 flex items-center border-t bg-white/80 dark:bg-zinc-950/80 backdrop-blur-md supports-[backdrop-filter]:bg-white/60 md:hidden',
        className
      )}
      style={{ paddingBottom: 'env(safe-area-inset-bottom, 0px)' }}
    >
      {items.map((item) => {
        const Icon = item.icon;
        const isActive = item.route ? url.startsWith(item.route) : false;

        const content = (
          <div
            className={cn(
              'relative flex flex-1 flex-col items-center justify-center gap-0.5 min-h-14 py-1.5 transition-colors',
              isActive
                ? 'text-primary'
                : 'text-muted-foreground hover:text-foreground'
            )}
          >
            {isActive && (
              <div className="absolute top-0 left-1/4 right-1/4 h-0.5 bg-primary rounded-b-full" />
            )}
            <Icon className="h-5 w-5" />
            <span className="text-[10px] leading-none font-medium">{item.label}</span>
            {item.badge && (
              <div className="absolute -top-0.5 right-1/4 -translate-x-1/2">
                {item.badge}
              </div>
            )}
          </div>
        );

        if (item.onClick) {
          return (
            <button key={item.label} type="button" onClick={item.onClick} className="flex-1">
              {content}
            </button>
          );
        }

        return (
          <Link key={item.label} href={item.route!} className="flex-1">
            {content}
          </Link>
        );
      })}
    </nav>
  );
}
