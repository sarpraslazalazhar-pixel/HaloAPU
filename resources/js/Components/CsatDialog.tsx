import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Star } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
    DialogFooter,
} from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { Label } from '@/Components/ui/label';

interface CsatDialogProps {
    ticketId: number;
    disabled?: boolean;
    existingRating?: number | null;
}

export function CsatDialog({ ticketId, disabled = false, existingRating }: CsatDialogProps) {
    const [open, setOpen] = useState(false);
    const [hoverRating, setHoverRating] = useState(0);

    const { data, setData, post, processing, errors, reset } = useForm({
        rating: 0,
        komentar: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('csat.store', { ticket: ticketId }), {
            onSuccess: () => {
                setOpen(false);
                reset();
            },
        });
    };

    if (existingRating) {
        return (
            <div className="flex items-center gap-1">
                <span className="text-sm text-muted-foreground mr-1">Rating Anda:</span>
                {[1, 2, 3, 4, 5].map((star) => (
                    <Star
                        key={star}
                        className={`h-5 w-5 ${
                            star <= existingRating
                                ? 'fill-yellow-400 text-yellow-400'
                                : 'text-gray-300'
                        }`}
                    />
                ))}
            </div>
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" disabled={disabled}>
                    <Star className="h-4 w-4 mr-2" />
                    Berikan Rating
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Berikan Rating Layanan</DialogTitle>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div>
                        <Label>Rating</Label>
                        <div className="flex items-center gap-1 mt-2">
                            {[1, 2, 3, 4, 5].map((star) => (
                                <button
                                    key={star}
                                    type="button"
                                    onMouseEnter={() => setHoverRating(star)}
                                    onMouseLeave={() => setHoverRating(0)}
                                    onClick={() => setData('rating', star)}
                                    className="p-1 transition-transform hover:scale-110"
                                >
                                    <Star
                                        className={`h-8 w-8 transition-colors ${
                                            star <= (hoverRating || data.rating)
                                                ? 'fill-yellow-400 text-yellow-400'
                                                : 'text-gray-300 dark:text-gray-600'
                                        }`}
                                    />
                                </button>
                            ))}
                        </div>
                        <div className="text-sm text-muted-foreground mt-1">
                            {data.rating === 1 && 'Sangat Buruk'}
                            {data.rating === 2 && 'Buruk'}
                            {data.rating === 3 && 'Cukup'}
                            {data.rating === 4 && 'Baik'}
                            {data.rating === 5 && 'Sangat Baik'}
                        </div>
                        {errors.rating && (
                            <p className="text-sm text-destructive mt-1">{errors.rating}</p>
                        )}
                    </div>

                    <div>
                        <Label htmlFor="komentar">Komentar (opsional)</Label>
                        <Textarea
                            id="komentar"
                            value={data.komentar}
                            onChange={(e) => setData('komentar', e.target.value)}
                            placeholder="Berikan komentar tentang layanan yang Anda terima..."
                            rows={4}
                            maxLength={1000}
                        />
                        <p className="text-xs text-muted-foreground mt-1">
                            {data.komentar.length}/1000 karakter
                        </p>
                        {errors.komentar && (
                            <p className="text-sm text-destructive mt-1">{errors.komentar}</p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                            Batal
                        </Button>
                        <Button type="submit" disabled={processing || data.rating === 0}>
                            Kirim Rating
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
