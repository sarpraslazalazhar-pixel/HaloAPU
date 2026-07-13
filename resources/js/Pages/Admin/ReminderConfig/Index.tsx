import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Checkbox } from '@/Components/ui/checkbox';
import { ReminderConfig } from '@/types';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';

interface Props {
    configs: ReminderConfig[];
}

const JENIS_LABELS: Record<string, string> = {
    booking: 'Reminder Booking',
    sla: 'Reminder SLA',
    pending_lama: 'Tiket Pending Lama',
    csat: 'CSAT Belum Diisi',
};

const LEAD_TIME_UNITS: Record<string, string> = {
    booking: 'hari sebelum',
    sla: 'otomatis',
    pending_lama: 'hari pending',
    csat: 'hari setelah solve',
};

export default function ReminderConfigIndex({ configs }: Props) {
    const { data, setData, put, processing } = useForm({
        configs: configs.map(c => ({
            id: c.id,
            jenis_reminder: c.jenis_reminder,
            lead_time_value: c.lead_time_value,
            channel_aktif: c.channel_aktif || [],
            aktif: c.aktif,
        })),
    });

    const toggleChannel = (index: number, channel: string) => {
        const updated = [...data.configs];
        const channels = updated[index].channel_aktif;
        if (channels.includes(channel)) {
            updated[index].channel_aktif = channels.filter(c => c !== channel);
        } else {
            updated[index].channel_aktif = [...channels, channel];
        }
        setData('configs', updated);
    };

    const updateLeadTime = (index: number, value: number) => {
        const updated = [...data.configs];
        updated[index].lead_time_value = value;
        setData('configs', updated);
    };

    const toggleAktif = (index: number, checked: boolean) => {
        const updated = [...data.configs];
        updated[index].aktif = checked;
        setData('configs', updated);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(route('admin.reminder-config.update'));
    };

    return (
        <AdminLayout title="Konfigurasi Reminder">
            <Head title="Konfigurasi Reminder" />

            <div className="flex justify-between items-center mb-6">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">Konfigurasi Reminder</h1>
                    <p className="text-muted-foreground mt-2">
                        Atur jenis, channel, dan waktu pengiriman reminder
                    </p>
                </div>
            </div>

            <Card>
                <form onSubmit={handleSubmit}>
                    <CardHeader>
                        <CardTitle>Daftar Reminder</CardTitle>
                        <CardDescription>
                            Pastikan Anda mengaktifkan channel yang sesuai dengan kebutuhan.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Jenis Reminder</TableHead>
                                        <TableHead className="w-[200px]">Lead Time</TableHead>
                                        <TableHead className="text-center">In-App</TableHead>
                                        <TableHead className="text-center">Email</TableHead>
                                        <TableHead className="text-center">WhatsApp</TableHead>
                                        <TableHead className="text-center">Aktif</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {data.configs.map((config, index) => (
                                        <TableRow key={config.id}>
                                            <TableCell className="font-medium">
                                                {JENIS_LABELS[config.jenis_reminder] || config.jenis_reminder}
                                            </TableCell>
                                            <TableCell>
                                                {config.jenis_reminder === 'sla' ? (
                                                    <span className="text-sm text-muted-foreground italic">Otomatis dari SLA</span>
                                                ) : (
                                                    <div className="flex items-center gap-2">
                                                        <Input
                                                            type="number"
                                                            min={0}
                                                            value={config.lead_time_value}
                                                            onChange={(e) => updateLeadTime(index, parseInt(e.target.value) || 0)}
                                                            className="w-20"
                                                        />
                                                        <span className="text-sm text-muted-foreground whitespace-nowrap">
                                                            {LEAD_TIME_UNITS[config.jenis_reminder]}
                                                        </span>
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Checkbox
                                                    checked={config.channel_aktif.includes('in_app')}
                                                    onCheckedChange={() => toggleChannel(index, 'in_app')}
                                                    disabled={config.jenis_reminder === 'booking' || config.jenis_reminder === 'sla' || config.jenis_reminder === 'pending_lama' || config.jenis_reminder === 'csat'} // In app selalu aktif
                                                />
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Checkbox
                                                    checked={config.channel_aktif.includes('email')}
                                                    onCheckedChange={() => toggleChannel(index, 'email')}
                                                />
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Checkbox
                                                    checked={config.channel_aktif.includes('whatsapp')}
                                                    onCheckedChange={() => toggleChannel(index, 'whatsapp')}
                                                />
                                            </TableCell>
                                            <TableCell className="text-center">
                                                <Checkbox
                                                    checked={config.aktif}
                                                    onCheckedChange={(checked) => toggleAktif(index, checked === true)}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <div className="mt-6 flex justify-end">
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Menyimpan...' : 'Simpan Perubahan'}
                            </Button>
                        </div>
                    </CardContent>
                </form>
            </Card>
        </AdminLayout>
    );
}
