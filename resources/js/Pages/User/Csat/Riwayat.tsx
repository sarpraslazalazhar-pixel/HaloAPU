import React from 'react';
import { Head, Link } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import { Star } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { formatTicketId } from '@/lib/utils';

interface CsatItem {
    id: number;
    rating: number;
    komentar: string | null;
    created_at: string;
    ticket: {
        id: number;
        judul?: string;
        status: string;
        created_at: string;
        sub_unit?: { nama_layanan: string };
    };
}

export default function Riwayat({ csats }: { csats: any }) {
    return (
        <UserLayout title="Riwayat Rating Saya">
            <Head title="Riwayat Rating Saya" />
            <div className="max-w-4xl mx-auto py-8 px-4">
                <h1 className="text-2xl font-bold mb-6">Riwayat Rating Saya</h1>

                {csats?.data?.length > 0 ? (
                    <div className="space-y-4">
                        {csats.data.map((csat: CsatItem) => (
                            <Card key={csat.id}>
                                <CardHeader className="pb-2">
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="text-base">
                                            Tiket #TKT-{formatTicketId(csat.ticket.id)}
                                            {csat.ticket.sub_unit && (
                                                <span className="text-sm font-normal text-muted-foreground ml-2">
                                                    {csat.ticket.sub_unit.nama_layanan}
                                                </span>
                                            )}
                                        </CardTitle>
                                        <div className="flex items-center gap-0.5">
                                            {[1, 2, 3, 4, 5].map((star) => (
                                                <Star
                                                    key={star}
                                                    className={`h-4 w-4 ${
                                                        star <= csat.rating
                                                            ? 'fill-yellow-400 text-yellow-400'
                                                            : 'text-gray-300 dark:text-gray-600'
                                                    }`}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    {csat.komentar && (
                                        <p className="text-sm text-muted-foreground mb-2">
                                            "{csat.komentar}"
                                        </p>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        {new Date(csat.created_at).toLocaleDateString('id-ID', {
                                            year: 'numeric', month: 'long', day: 'numeric'
                                        })}
                                    </p>
                                </CardContent>
                            </Card>
                        ))}

                        <div className="flex items-center justify-between mt-4">
                            <div className="text-sm text-muted-foreground">
                                Menampilkan {csats.from}-{csats.to} dari {csats.total}
                            </div>
                            <div className="flex gap-2">
                                {csats.links?.filter((l: any) => l.url).map((link: any, i: number) => (
                                    <Link
                                        key={i}
                                        href={link.url}
                                        className={`px-3 py-1 rounded text-sm border ${
                                            link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'
                                        }`}
                                        preserveState
                                    >
                                        {link.label}
                                    </Link>
                                ))}
                            </div>
                        </div>
                    </div>
                ) : (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <Star className="h-12 w-12 mx-auto mb-3 opacity-30" />
                            <p>Belum ada rating yang diberikan.</p>
                            <p className="text-sm mt-1">
                                Berikan rating setelah tiket Anda selesai diproses.
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </UserLayout>
    );
}
