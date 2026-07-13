import React, { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';

const DAYS = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'] as const;
const DAY_LABELS: Record<string, string> = {
    senin: 'Senin', selasa: 'Selasa', rabu: 'Rabu', kamis: 'Kamis',
    jumat: 'Jumat', sabtu: 'Sabtu', minggu: 'Minggu',
};

export default function KonfigurasiIndex({ configs }: any) {
    const { data, setData, put, processing, errors } = useForm({
        nama_sistem: configs.nama_sistem || 'Halo APU',
        email_admin: configs.email_admin || '',
        wa_api_key: configs.wa_api_key || '',
        wa_gateway_url: configs.wa_gateway_url || '',
        nomor_wa_utama: configs.nomor_wa_utama || '',
        nomor_wa_fallback: configs.nomor_wa_fallback || '',
        jam_kerja: configs.jam_kerja || {},
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(route('admin.konfigurasi.update'));
    };

    const toggleDay = (day: string) => {
        const current = data.jam_kerja[day];
        setData('jam_kerja', {
            ...data.jam_kerja,
            [day]: current ? null : ['08:00', '16:00'],
        });
    };

    const setDayTime = (day: string, index: number, value: string) => {
        const current = data.jam_kerja[day] || ['08:00', '16:00'];
        const updated = [...current];
        updated[index] = value;
        setData('jam_kerja', { ...data.jam_kerja, [day]: updated });
    };

    const handleFileUpload = (field: string, e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        const formData = new window.FormData();
        formData.append(field, file);
        const routeName = field === 'logo' ? 'admin.konfigurasi.upload-logo' : 'admin.konfigurasi.upload-banner';
        router.post(route(routeName), formData, {
            preserveScroll: true,
        });
    };

    return (
        <AdminLayout title="Konfigurasi Sistem">
            <Head title="Konfigurasi Sistem" />
            <div className="max-w-4xl mx-auto space-y-6">
                <h1 className="text-2xl font-bold">Konfigurasi Sistem</h1>

                <Tabs defaultValue="branding">
                    <TabsList>
                        <TabsTrigger value="branding">Branding</TabsTrigger>
                        <TabsTrigger value="notifikasi">Notifikasi</TabsTrigger>
                        <TabsTrigger value="operasional">Operasional</TabsTrigger>
                    </TabsList>

                    <form onSubmit={handleSubmit}>
                        <TabsContent value="branding" className="space-y-4 mt-4">
                            <Card>
                                <CardHeader><CardTitle>Branding</CardTitle></CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <Label htmlFor="nama_sistem">Nama Sistem</Label>
                                        <Input id="nama_sistem" value={data.nama_sistem} onChange={(e) => setData('nama_sistem', e.target.value)} />
                                    </div>
                                    <div>
                                        <Label>Logo</Label>
                                        <p className="text-xs text-muted-foreground mb-2">Format: PNG, JPG, SVG. Maks 2MB.</p>
                                        <Input type="file" accept="image/png,image/jpg,image/jpeg,image/svg+xml" onChange={(e) => handleFileUpload('logo', e)} />
                                        {configs.logo_path && (
                                            <img src={`/storage/${configs.logo_path}`} alt="Logo" className="mt-2 h-16 object-contain" />
                                        )}
                                    </div>
                                    <div>
                                        <Label>Banner</Label>
                                        <p className="text-xs text-muted-foreground mb-2">Format: PNG, JPG. Maks 5MB.</p>
                                        <Input type="file" accept="image/png,image/jpg,image/jpeg" onChange={(e) => handleFileUpload('banner', e)} />
                                        {configs.banner_path && (
                                            <img src={`/storage/${configs.banner_path}`} alt="Banner" className="mt-2 h-24 object-contain" />
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        <TabsContent value="notifikasi" className="space-y-4 mt-4">
                            <Card>
                                <CardHeader><CardTitle>Notifikasi</CardTitle></CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <Label htmlFor="email_admin">Email Admin</Label>
                                        <Input id="email_admin" type="email" value={data.email_admin} onChange={(e) => setData('email_admin', e.target.value)} />
                                    </div>
                                    <div>
                                        <Label htmlFor="wa_api_key">API Key WhatsApp</Label>
                                        <Input id="wa_api_key" type="password" value={data.wa_api_key} onChange={(e) => setData('wa_api_key', e.target.value)} />
                                    </div>
                                    <div>
                                        <Label htmlFor="wa_gateway_url">URL Gateway WhatsApp</Label>
                                        <Input id="wa_gateway_url" value={data.wa_gateway_url} onChange={(e) => setData('wa_gateway_url', e.target.value)} />
                                    </div>
                                    <div>
                                        <Label htmlFor="nomor_wa_utama">Nomor WA Utama</Label>
                                        <Input id="nomor_wa_utama" value={data.nomor_wa_utama} onChange={(e) => setData('nomor_wa_utama', e.target.value)} />
                                    </div>
                                    <div>
                                        <Label htmlFor="nomor_wa_fallback">Nomor WA Fallback</Label>
                                        <Input id="nomor_wa_fallback" value={data.nomor_wa_fallback} onChange={(e) => setData('nomor_wa_fallback', e.target.value)} />
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        <TabsContent value="operasional" className="space-y-4 mt-4">
                            <Card>
                                <CardHeader><CardTitle>Jam Kerja</CardTitle></CardHeader>
                                <CardContent className="space-y-3">
                                    {DAYS.map((day) => (
                                        <div key={day} className="flex items-center gap-4">
                                            <div className="flex items-center gap-2 w-24">
                                                <Switch checked={data.jam_kerja[day] !== null} onCheckedChange={() => toggleDay(day)} />
                                                <span className="text-sm">{DAY_LABELS[day]}</span>
                                            </div>
                                            {data.jam_kerja[day] ? (
                                                <>
                                                    <Input type="time" value={data.jam_kerja[day][0] || '08:00'} onChange={(e) => setDayTime(day, 0, e.target.value)} className="w-32" />
                                                    <span className="text-muted-foreground">—</span>
                                                    <Input type="time" value={data.jam_kerja[day][1] || '16:00'} onChange={(e) => setDayTime(day, 1, e.target.value)} className="w-32" />
                                                </>
                                            ) : (
                                                <span className="text-sm text-muted-foreground">Libur</span>
                                            )}
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        </TabsContent>

                        <div className="flex justify-end mt-4">
                            <Button type="submit" disabled={processing}>Simpan Perubahan</Button>
                        </div>
                    </form>
                </Tabs>
            </div>
        </AdminLayout>
    );
}
