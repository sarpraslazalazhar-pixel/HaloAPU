import React from 'react';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { cn } from '@/lib/utils';

interface Column<T> {
 key: string;
 header: string;
 render?: (item: T) => React.ReactNode;
 className?: string;
}

interface DataTableProps<T> {
 columns: Column<T>[];
 data: T[];
 keyExtractor: (item: T) => string | number;
 emptyMessage?: string;
}

export function DataTable<T>({ columns, data, keyExtractor, emptyMessage = 'Tidak ada data.' }: DataTableProps<T>) {
 if (data.length === 0) {
 return (
 <div className="text-center py-12 text-muted-foreground">
 {emptyMessage}
 </div>
 );
 }

 return (
 <Table>
 <TableHeader>
 <TableRow>
 {columns.map((col) => (
 <TableHead key={col.key} className={cn(col.className)}>
 {col.header}
 </TableHead>
 ))}
 </TableRow>
 </TableHeader>
 <TableBody>
 {data.map((item) => (
 <TableRow key={keyExtractor(item)}>
 {columns.map((col) => (
 <TableCell key={col.key} className={cn(col.className)}>
 {col.render ? col.render(item) : String((item as any)[col.key] ?? '')}
 </TableCell>
 ))}
 </TableRow>
 ))}
 </TableBody>
 </Table>
 );
}
