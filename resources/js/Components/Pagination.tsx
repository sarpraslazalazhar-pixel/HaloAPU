import React from 'react';
import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginationProps {
    links: PaginationLink[];
}

export function Pagination({ links }: PaginationProps) {
    if (!links || links.length <= 3) return null;

    return (
        <div className="flex items-center justify-center gap-1 mt-4">
            {links.map((link, i) => {
                if (link.label.includes('Previous')) {
                    return link.url ? (
                        <Link key={i} href={link.url} preserveScroll preserveState>
                            <Button variant="outline" size="icon" className="h-8 w-8">
                                <ChevronLeft className="h-4 w-4" />
                            </Button>
                        </Link>
                    ) : (
                        <Button key={i} variant="outline" size="icon" className="h-8 w-8" disabled>
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                    );
                }

                if (link.label.includes('Next')) {
                    return link.url ? (
                        <Link key={i} href={link.url} preserveScroll preserveState>
                            <Button variant="outline" size="icon" className="h-8 w-8">
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        </Link>
                    ) : (
                        <Button key={i} variant="outline" size="icon" className="h-8 w-8" disabled>
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                    );
                }

                return link.url ? (
                    <Link key={i} href={link.url} preserveScroll preserveState>
                        <Button
                            variant={link.active ? 'default' : 'outline'}
                            size="icon"
                            className={cn('h-8 w-8 text-xs', link.active && 'pointer-events-none')}
                        >
                            {link.label}
                        </Button>
                    </Link>
                ) : (
                    <Button key={i} variant="outline" size="icon" className="h-8 w-8 text-xs" disabled>
                        {link.label}
                    </Button>
                );
            })}
        </div>
    );
}
