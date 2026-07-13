import React from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { NotificationItem, PaginatedData } from '@/types';
import axios from 'axios';
import { Clock, Check, CheckCircle2 } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';

interface Props {
    notifications: PaginatedData<NotificationItem>;
    filters: {
        status?: string;
        type?: string;
    };
}

export default function NotificationsIndex({ notifications, filters }: Props) {
    
    const handleFilterChange = (key: string, value: string | null) => {
        router.get(
            route('admin.notifications.index'),
            { ...filters, [key]: value === 'all' ? undefined : value },
            { replace: true }
        );
    };

    const handleMarkAllRead = async () => {
        try {
            await axios.post(route('admin.notifications.mark-all-read'));
            router.reload({ only: ['notifications'] });
        } catch (error) {
            console.error('Gagal memproses:', error);
        }
    };

    const handleAction = async (action: 'read' | 'snooze' | 'done', id: string, extra?: any) => {
        try {
            if (action === 'read') {
                await axios.patch(route('admin.notifications.read', { id }));
            } else if (action === 'snooze') {
                await axios.patch(route('admin.notifications.snooze', { id }), { snooze_minutes: extra });
            } else if (action === 'done') {
                await axios.patch(route('admin.notifications.done', { id }));
            }
            router.reload({ only: ['notifications'] });
        } catch (error) {
            console.error('Gagal memproses:', error);
        }
    };

    const formatTimeAgo = (dateStr: string): string => {
        const date = new Date(dateStr);
        const now = new Date();
        const diffMs = now.getTime() - date.getTime();
        const diffMinutes = Math.floor(diffMs / 60000);

        if (diffMinutes < 1) return 'Baru saja';
        if (diffMinutes < 60) return `${diffMinutes} menit lalu`;
        const diffHours = Math.floor(diffMinutes / 60);
        if (diffHours < 24) return `${diffHours} jam lalu`;
        const diffDays = Math.floor(diffHours / 24);
        return `${diffDays} hari lalu`;
    };

    return (
        <AdminLayout title="Semua Notifikasi">
            <Head title="Semua Notifikasi" />

            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">Notifikasi</h1>
                    <p className="text-muted-foreground mt-2">
                        Pusat notifikasi dan pengingat
                    </p>
                </div>
            </div>

            <Card>
                <div className="p-4 border-b flex flex-wrap items-center gap-4 bg-muted/20">
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-medium">Status:</span>
                        <Select 
                            value={filters.status || 'all'} 
                            onValueChange={(val) => handleFilterChange('status', val)}
                        >
                            <SelectTrigger className="w-[150px]">
                                <SelectValue placeholder="Semua Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua Status</SelectItem>
                                <SelectItem value="unread">Belum Dibaca</SelectItem>
                                <SelectItem value="read">Sudah Dibaca</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex items-center gap-2">
                        <span className="text-sm font-medium">Tipe:</span>
                        <Select 
                            value={filters.type || 'all'} 
                            onValueChange={(val) => handleFilterChange('type', val)}
                        >
                            <SelectTrigger className="w-[200px]">
                                <SelectValue placeholder="Semua Tipe" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua Tipe</SelectItem>
                                <SelectItem value="BookingReminderNotification">Reminder Booking</SelectItem>
                                <SelectItem value="SlaEscalationNotification">Eskalasi SLA</SelectItem>
                                <SelectItem value="PendingTicketReminderNotification">Tiket Pending</SelectItem>
                                <SelectItem value="CsatReminderNotification">CSAT</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="ml-auto">
                        <Button variant="outline" size="sm" onClick={handleMarkAllRead}>
                            <Check className="h-4 w-4 mr-2" />
                            Tandai Semua Dibaca
                        </Button>
                    </div>
                </div>
                
                <CardContent className="p-0">
                    {notifications.data.length === 0 ? (
                        <div className="p-12 text-center text-muted-foreground">
                            Tidak ada notifikasi yang sesuai dengan kriteria.
                        </div>
                    ) : (
                        <div className="divide-y">
                            {notifications.data.map((notification) => (
                                <div 
                                    key={notification.id} 
                                    className={`p-4 flex flex-col sm:flex-row gap-4 hover:bg-muted/50 transition-colors ${!notification.read_at ? 'bg-blue-50/50 dark:bg-blue-950/20' : ''}`}
                                >
                                    <div className="flex-1 min-w-0 flex items-start gap-3">
                                        {!notification.read_at && (
                                            <div className="w-2 h-2 rounded-full bg-blue-500 mt-2 flex-shrink-0" />
                                        )}
                                        <div className="flex-1 cursor-pointer" onClick={() => {
                                            if (!notification.read_at) handleAction('read', notification.id);
                                            if (notification.data.aksi_url) window.location.href = notification.data.aksi_url;
                                        }}>
                                            <div className="flex items-center gap-2 mb-1">
                                                <h4 className="font-semibold">{notification.data.judul}</h4>
                                                <span className="text-xs text-muted-foreground">{formatTimeAgo(notification.created_at)}</span>
                                            </div>
                                            <p className="text-sm text-muted-foreground">{notification.data.pesan}</p>
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2 sm:self-center">
                                        {!notification.data.done_at && (
                                            <>
                                                <Button 
                                                    variant="ghost" 
                                                    size="sm" 
                                                    className="text-green-600 hover:text-green-700 hover:bg-green-50"
                                                    onClick={() => handleAction('done', notification.id)}
                                                    title="Tandai Selesai"
                                                >
                                                    <CheckCircle2 className="h-4 w-4" />
                                                </Button>

                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button variant="ghost" size="sm" title="Snooze">
                                                            <Clock className="h-4 w-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem onClick={() => handleAction('snooze', notification.id, 15)}>Snooze 15 menit</DropdownMenuItem>
                                                        <DropdownMenuItem onClick={() => handleAction('snooze', notification.id, 30)}>Snooze 30 menit</DropdownMenuItem>
                                                        <DropdownMenuItem onClick={() => handleAction('snooze', notification.id, 60)}>Snooze 1 jam</DropdownMenuItem>
                                                        <DropdownMenuItem onClick={() => handleAction('snooze', notification.id, 1440)}>Snooze 1 hari</DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </>
                                        )}
                                        {notification.data.done_at && (
                                            <span className="text-xs text-green-600 font-medium bg-green-50 px-2 py-1 rounded border border-green-200 flex items-center gap-1">
                                                <CheckCircle2 className="h-3 w-3" /> Selesai
                                            </span>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {/* Pagination - Simplified */}
                    {notifications.last_page > 1 && (
                        <div className="p-4 border-t flex justify-between items-center bg-muted/10">
                            <Button 
                                variant="outline" 
                                disabled={notifications.current_page === 1}
                                onClick={() => router.get(notifications.links[0].url || '')}
                            >
                                ← Sebelumnya
                            </Button>
                            <span className="text-sm text-muted-foreground">
                                Halaman {notifications.current_page} dari {notifications.last_page}
                            </span>
                            <Button 
                                variant="outline" 
                                disabled={notifications.current_page === notifications.last_page}
                                onClick={() => router.get(notifications.links[notifications.links.length - 1].url || '')}
                            >
                                Selanjutnya →
                            </Button>
                        </div>
                    )}
                </CardContent>
            </Card>
        </AdminLayout>
    );
}
