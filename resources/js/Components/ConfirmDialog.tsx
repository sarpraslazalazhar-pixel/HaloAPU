import React from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { AlertTriangle } from 'lucide-react';

interface ConfirmDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title?: string;
    message?: string;
    confirmText?: string;
    cancelText?: string;
    onConfirm: () => void;
    variant?: 'default' | 'destructive';
}

export function ConfirmDialog({
    open,
    onOpenChange,
    title = 'Konfirmasi',
    message = 'Yakin ingin melanjutkan?',
    confirmText = 'Ya',
    cancelText = 'Batal',
    onConfirm,
    variant = 'destructive',
}: ConfirmDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-sm">
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <div className={variant === 'destructive'
                            ? 'p-2 rounded-full bg-red-100 dark:bg-red-900/20'
                            : 'p-2 rounded-full bg-blue-100 dark:bg-blue-900/20'}>
                            <AlertTriangle className={variant === 'destructive' ? 'h-5 w-5 text-red-600' : 'h-5 w-5 text-blue-600'} />
                        </div>
                        <DialogTitle>{title}</DialogTitle>
                    </div>
                    <DialogDescription className="pt-2">
                        {message}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter showCloseButton>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        {cancelText}
                    </Button>
                    <Button
                        variant={variant === 'destructive' ? 'destructive' : 'default'}
                        onClick={() => {
                            onConfirm();
                            onOpenChange(false);
                        }}
                    >
                        {confirmText}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
