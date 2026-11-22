import React from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Switch } from '@/Components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import AppUpdateTab, { type AppReleaseItem } from './AppUpdateTab';

const DAYS = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'] as const;

const DAY_LABELS = {
 senin: 'Senin', selasa: 'Selasa', rabu: 'Rabu', kamis: 'Kamis',
 jumat: 'Jumat', sabtu: 'Sabtu', minggu: 'Minggu',
} satisfies Record<string, string>;

interface HumasAdminItem {
 id: number | string;
 name: string;
 username: string;
}

interface FcmDiagnosticStatus {
 enabled: boolean;
 configured: boolean;
 mode: 'http_v1_panel' | 'http_v1_env' | 'legacy_key' | 'unconfigured';
 project_id: string | null;
 client_email_masked: string | null;
 uploaded_at: string | null;
 registered_users_count: number;
 registered_admins_count: number;
}

const VALID_TABS = new Set([
 'branding',
 'notifikasi',
 'operasional',
 'sparc',
 'scheduler',
 'update_aplikasi',
]);

function getInitialTab(): string {
 if (typeof window === 'undefined') {
  return 'branding';
 }

 const tab = new URLSearchParams(window.location.search).get('tab');

 return tab && VALID_TABS.has(tab) ? tab : 'branding';
}

export default function KonfigurasiIndex({
 configs,
 appReleases = [],
 allowedReleaseHosts = [],
 fcmStatus,
}: {
 configs: any;
 appReleases?: AppReleaseItem[];
 allowedReleaseHosts?: string[];
 fcmStatus?: FcmDiagnosticStatus;
}) {
 const [activeTab, setActiveTab] = React.useState<string>(getInitialTab);

 const showConfigSaveButton =
  activeTab === 'branding' ||
  activeTab === 'notifikasi' ||
  activeTab === 'operasional';

 const { data, setData, put, processing, errors } = useForm({
 nama_sistem: configs.nama_sistem || 'Halo APU',
 email_admin: configs.email_admin || '',
 wa_api_key: configs.wa_api_key || '',
 wa_number_key: configs.wa_number_key || '',
 wa_gateway_url: configs.wa_gateway_url || '',
 wa_notification_enabled: configs.wa_notification_enabled !== undefined ? Boolean(configs.wa_notification_enabled) : true,
 fcm_enabled: configs.fcm_enabled !== undefined ? Boolean(configs.fcm_enabled) : true,
 device_lock_enabled: configs.device_lock_enabled !== undefined ? Boolean(configs.device_lock_enabled) : true,
 nomor_wa_utama: configs.nomor_wa_utama || '',
 nomor_wa_fallback: configs.nomor_wa_fallback || '',
 app_timezone: configs.app_timezone || 'Asia/Jakarta',
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

 const [testPhone, setTestPhone] = React.useState('');
 const [testingWa, setTestingWa] = React.useState(false);
 const [uploadingFirebase, setUploadingFirebase] = React.useState(false);
 const [testingFcm, setTestingFcm] = React.useState(false);

 const handleUploadFirebase = (e: React.ChangeEvent<HTMLInputElement>) => {
  const file = e.target.files?.[0];

  if (!file) return;

  const formData = new window.FormData();
  formData.append('firebase_credentials', file);

  setUploadingFirebase(true);

  router.post(route('admin.konfigurasi.upload-firebase'), formData, {
   preserveScroll: true,
   onFinish: () => {
    setUploadingFirebase(false);
    e.target.value = '';
   },
  });
 };

 const handleDeleteFirebase = () => {
  if (!window.confirm('Hapus kredensial Firebase Service Account JSON yang diunggah melalui panel?')) {
   return;
  }

  router.delete(route('admin.konfigurasi.delete-firebase'), {
   preserveScroll: true,
  });
 };

 const handleTestFcm = () => {
  setTestingFcm(true);

  router.post(route('admin.konfigurasi.test-fcm'), {}, {
   preserveScroll: true,
   onFinish: () => setTestingFcm(false),
  });
 };

 const handleTestWa = () => {
 if (!testPhone) return;
 setTestingWa(true);
 router.post(route('admin.konfigurasi.test-wa'), { target_phone: testPhone }, {
 preserveScroll: true,
 onFinish: () => setTestingWa(false),
 });
 };

 const handleFileUpload = (field: string, e: React.ChangeEvent<HTMLInputElement>) => {
 const file = e.target.files?.[0];

 if (!file) return;
 const formData = new window.FormData();
 formData.append(field, file);
 let routeName = 'admin.konfigurasi.upload-logo';

 if (field === 'banner') routeName = 'admin.konfigurasi.upload-banner';

 if (field === 'favicon') routeName = 'admin.konfigurasi.upload-favicon';
 
 router.post(route(routeName), formData, {
 preserveScroll: true,
 });
 };

 const [sparcState, setSparcState] = React.useState({
 sparc_integration_enabled: Boolean(configs.sparc_integration_enabled),
 sparc_base_url: configs.sparc_base_url || '',
 sparc_api_token: configs.sparc_api_token || '',
 sparc_webhook_secret: configs.sparc_webhook_secret || '',
 sparc_default_admin_id: configs.sparc_default_admin_id || '',
 });

 const [isSavingSparc, setIsSavingSparc] = React.useState(false);
 const [isGeneratingCreds, setIsGeneratingCreds] = React.useState(false);
 const [isTestingSparc, setIsTestingSparc] = React.useState(false);
 const [testSparcResult, setTestSparcResult] = React.useState<{ success: boolean; message: string } | null>(null);

 const handleGenerateCreds = async () => {
 setIsGeneratingCreds(true);

 try {
 const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

 const res = await fetch(route('admin.konfigurasi.generate-sparc-credentials'), {
 method: 'POST',
 headers: {
 'Content-Type': 'application/json',
 'X-CSRF-TOKEN': csrfToken,
 },
 });

 const json = await res.json();

 if (json.success && json.data) {
 setSparcState((prev) => ({
 ...prev,
 sparc_api_token: json.data.api_token,
 sparc_webhook_secret: json.data.webhook_secret,
 }));
 }
 } finally {
 setIsGeneratingCreds(false);
 }
 };

 const handleTestSparc = async () => {
 setIsTestingSparc(true);
 setTestSparcResult(null);

 try {
 const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

 const res = await fetch(route('admin.konfigurasi.test-sparc'), {
 method: 'POST',
 headers: {
 'Content-Type': 'application/json',
 'X-CSRF-TOKEN': csrfToken,
 },
 body: JSON.stringify({
 sparc_base_url: sparcState.sparc_base_url,
 sparc_webhook_secret: sparcState.sparc_webhook_secret,
 }),
 });

 const json = await res.json();

 setTestSparcResult({ success: json.success, message: json.message });
 } catch (e: unknown) {
 const msg = e instanceof Error ? e.message : 'Gagal menghubungi server.';
 setTestSparcResult({ success: false, message: msg });
 setIsTestingSparc(false);
 }
 };

 const handleSaveSparc = () => {
 setIsSavingSparc(true);
 router.put(
 route('admin.konfigurasi.update'),
 {
 ...data,
 ...sparcState,
 },
 {
 preserveScroll: true,
 onFinish: () => setIsSavingSparc(false),
 }
 );
 };

 return (
 <AdminLayout title="Konfigurasi Sistem">
 <Head title="Konfigurasi Sistem" />
 <div className="max-w-4xl mx-auto space-y-6">
 <h1 className="text-2xl font-bold">Konfigurasi Sistem</h1>

 <Tabs value={activeTab} onValueChange={setActiveTab}>
 <TabsList className="flex-wrap h-auto">
 <TabsTrigger value="branding">Branding</TabsTrigger>
 <TabsTrigger value="notifikasi">Notifikasi</TabsTrigger>
 <TabsTrigger value="operasional">Operasional</TabsTrigger>
 <TabsTrigger value="sparc">Integrasi SPARC</TabsTrigger>
 <TabsTrigger value="scheduler">Scheduler</TabsTrigger>
 <TabsTrigger value="update_aplikasi">Update Aplikasi</TabsTrigger>
 </TabsList>

 <form onSubmit={handleSubmit}>
 <TabsContent value="branding" className="space-y-4 mt-4">
 <Card>
 <CardHeader><CardTitle>Branding</CardTitle></CardHeader>
 <CardContent className="space-y-4">
 <div>
 <Label htmlFor="nama_sistem">Nama Sistem</Label>
 <Input id="nama_sistem" value={data.nama_sistem} onChange={(e) => setData('nama_sistem', e.target.value)} />
 {errors.nama_sistem && <div className="text-destructive text-xs mt-1">{errors.nama_sistem}</div>}
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
 <Label>Favicon</Label>
 <p className="text-xs text-muted-foreground mb-2">Format: PNG, JPG, ICO, SVG. Maks 2MB. (Akan tampil di tab browser)</p>
 <Input type="file" accept="image/png,image/jpg,image/jpeg,image/x-icon,image/svg+xml" onChange={(e) => handleFileUpload('favicon', e)} />
 {configs.favicon_path && (
 <img src={`/storage/${configs.favicon_path}`} alt="Favicon" className="mt-2 h-12 object-contain border rounded p-1" />
 )}
 </div>
 <div>
 <Label>Background Login</Label>
 <p className="text-xs text-muted-foreground mb-2">Format: PNG, JPG. Maks 5MB.</p>
 <Input type="file" accept="image/png,image/jpg,image/jpeg" onChange={(e) => handleFileUpload('banner', e)} />
 {configs.banner_path && (
 <img src={`/storage/${configs.banner_path}`} alt="Background Login" className="mt-2 h-24 object-contain" />
 )}
 </div>
 </CardContent>
 </Card>
 </TabsContent>

 <TabsContent value="notifikasi" className="space-y-4 mt-4">
 <Card>
 <CardHeader><CardTitle>Notifikasi WhatsApp & Email</CardTitle></CardHeader>
 <CardContent className="space-y-4">
 <div className="flex items-center justify-between p-3 border rounded-lg bg-muted/30 mb-4">
 <div>
 <Label className="font-semibold text-base">Aktifkan Notifikasi WhatsApp Sistem</Label>
 <p className="text-xs text-muted-foreground">Sakelar utama untuk mengaktifkan atau mematikan pengiriman pesan WhatsApp secara global untuk seluruh sistem.</p>
 </div>
 <Switch
 checked={data.wa_notification_enabled}
 onCheckedChange={(checked) => setData('wa_notification_enabled', checked)}
 />
 </div>
                <div>
                  <Label htmlFor="email_admin">Email Penerima Pesan Bantuan / Admin</Label>
                  <Input id="email_admin" type="email" placeholder="sarpras@alazharpeduli.or.id" value={data.email_admin} onChange={(e) => setData('email_admin', e.target.value)} />
                  {errors.email_admin && <div className="text-destructive text-xs mt-1">{errors.email_admin}</div>}
                </div>
 <div>
 <Label htmlFor="wa_api_key">API Key WhatsApp</Label>
 <Input id="wa_api_key" type="password" value={data.wa_api_key} onChange={(e) => setData('wa_api_key', e.target.value)} />
 {errors.wa_api_key && <div className="text-destructive text-xs mt-1">{errors.wa_api_key}</div>}
 </div>
 <div>
 <Label htmlFor="wa_number_key">Number Key WhatsApp (Watzap)</Label>
 <Input id="wa_number_key" type="password" value={data.wa_number_key} onChange={(e) => setData('wa_number_key', e.target.value)} />
 {errors.wa_number_key && <div className="text-destructive text-xs mt-1">{errors.wa_number_key}</div>}
 </div>
 <div>
 <Label htmlFor="wa_gateway_url">URL Gateway WhatsApp</Label>
 <Input id="wa_gateway_url" value={data.wa_gateway_url} onChange={(e) => setData('wa_gateway_url', e.target.value)} />
 {errors.wa_gateway_url && <div className="text-destructive text-xs mt-1">{errors.wa_gateway_url}</div>}
 </div>
 <div>
 <Label htmlFor="nomor_wa_utama">Nomor WA Utama</Label>
 <Input id="nomor_wa_utama" value={data.nomor_wa_utama} onChange={(e) => setData('nomor_wa_utama', e.target.value)} />
 {errors.nomor_wa_utama && <div className="text-destructive text-xs mt-1">{errors.nomor_wa_utama}</div>}
 </div>
 <div>
 <Label htmlFor="nomor_wa_fallback">Nomor WA Fallback</Label>
 <Input id="nomor_wa_fallback" value={data.nomor_wa_fallback} onChange={(e) => setData('nomor_wa_fallback', e.target.value)} />
 {errors.nomor_wa_fallback && <div className="text-destructive text-xs mt-1">{errors.nomor_wa_fallback}</div>}
 </div>
 <div className="p-3 border rounded-lg bg-muted/20 space-y-2">
 <Label className="font-semibold text-sm">Uji Pengiriman WhatsApp</Label>
 <p className="text-xs text-muted-foreground">Kirim pesan tes langsung untuk memverifikasi gateway dan koneksi nomor.</p>
 <div className="flex gap-2">
 <Input
 placeholder="Nomor tujuan (cth: 081234567890)"
 value={testPhone}
 onChange={(e) => setTestPhone(e.target.value)}
 className="max-w-xs"
 />
 <Button
 type="button"
 variant="outline"
 disabled={testingWa || !testPhone}
 onClick={handleTestWa}
 >
 {testingWa ? 'Mengirim...' : 'Kirim Pesan Tes'}
 </Button>
 </div>
 </div>
 <div className="pt-4 border-t">
 <Label>Suara Tiket Masuk</Label>
 <p className="text-xs text-muted-foreground mb-2">Format: MP3, WAV. Maks 5MB. (Diputar saat ada tiket baru)</p>
 <Input type="file" accept="audio/mp3,audio/wav" onChange={(e) => {
 const file = e.target.files?.[0];

 if (!file) return;
 const formData = new window.FormData();
 formData.append('sound', file);
 router.post(route('admin.konfigurasi.upload-sound'), formData, { preserveScroll: true });
 }} />
              {configs.notification_sound_path && (
                <audio controls className="mt-2" src={`${route('system.notification-sound')}?v=${encodeURIComponent(configs.notification_sound_path)}`}>
                  Browser kamu tidak mendukung elemen audio.
                </audio>
              )}
 </div>
 </CardContent>
 </Card>

 <Card>
  <CardHeader className="flex flex-row items-center justify-between gap-2">
   <CardTitle className="text-base">Push Notification Mobile (Firebase Cloud Messaging HTTP v1)</CardTitle>
   {fcmStatus?.configured ? (
    <Badge className="bg-emerald-600 text-white">
     {fcmStatus.mode === 'http_v1_panel'
      ? 'Aktif — HTTP v1 (Panel)'
      : fcmStatus.mode === 'http_v1_env'
       ? 'Aktif — HTTP v1 (ENV)'
       : 'Aktif — Legacy Key'}
    </Badge>
   ) : (
    <Badge variant="outline" className="border-amber-400 bg-amber-50 text-amber-900">
     Mode Fallback Polling (Belum Dikonfigurasi)
    </Badge>
   )}
  </CardHeader>
  <CardContent className="space-y-4">
   <div className="flex items-center justify-between p-3 border rounded-lg bg-slate-50">
    <div className="pr-4">
     <Label htmlFor="fcm_enabled" className="font-semibold text-sm text-slate-900">
      Aktifkan Push Notification Mobile (FCM)
     </Label>
     <p className="text-xs text-slate-600">
      Mengirim push real-time ke aplikasi Android meskipun aplikasi sedang ditutup (terminated).
     </p>
    </div>
    <Switch
     id="fcm_enabled"
     checked={data.fcm_enabled}
     onCheckedChange={(checked) => setData('fcm_enabled', checked)}
    />
   </div>

   <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3 rounded-lg border bg-slate-50 text-xs">
    <div>
     <div className="text-slate-500">Project ID</div>
     <div className="font-mono font-semibold text-slate-900 mt-0.5">
      {fcmStatus?.project_id || '-'}
     </div>
    </div>
    <div>
     <div className="text-slate-500">Service Account</div>
     <div className="font-mono font-semibold text-slate-900 mt-0.5">
      {fcmStatus?.client_email_masked || '-'}
     </div>
    </div>
    <div>
     <div className="text-slate-500">Perangkat Terdaftar (FCM Token)</div>
     <div className="font-semibold text-slate-900 mt-0.5">
      {fcmStatus ? `${fcmStatus.registered_users_count} User • ${fcmStatus.registered_admins_count} Admin` : '0 User • 0 Admin'}
     </div>
    </div>
    <div>
     <div className="text-slate-500">Diunggah Pada</div>
     <div className="font-semibold text-slate-900 mt-0.5">
      {fcmStatus?.uploaded_at ? new Date(fcmStatus.uploaded_at).toLocaleString('id-ID') : '-'}
     </div>
    </div>
   </div>

   <div className="space-y-2">
    <Label htmlFor="firebase_credentials">Unggah Firebase Service Account JSON (.json)</Label>
    <p className="text-xs text-slate-600">
     Unduh dari Firebase Console → Project Settings → Service Accounts → Generate New Private Key. File disimpan aman di direktori privat server di luar webroot.
    </p>
    <div className="flex flex-wrap items-center gap-2">
     <Input
      id="firebase_credentials"
      type="file"
      accept=".json,application/json"
      disabled={uploadingFirebase}
      onChange={handleUploadFirebase}
      className="max-w-md"
     />
     {fcmStatus?.mode === 'http_v1_panel' && (
      <Button
       type="button"
       variant="destructive"
       size="sm"
       onClick={handleDeleteFirebase}
      >
       Hapus Kredensial
      </Button>
     )}
    </div>
   </div>

   <div className="pt-3 border-t flex flex-wrap items-center justify-between gap-2">
    <p className="text-xs text-slate-600">
     Kirim notifikasi uji ke perangkat Admin/User yang memiliki token FCM terdaftar.
    </p>
    <Button
     type="button"
     variant="outline"
     disabled={testingFcm || !fcmStatus?.configured || !data.fcm_enabled}
     onClick={handleTestFcm}
    >
     {testingFcm ? 'Mengirim...' : 'Uji Push Notification Mobile'}
    </Button>
   </div>
  </CardContent>
 </Card>
 </TabsContent>

 <TabsContent value="operasional" className="space-y-4 mt-4">
 <Card>
 <CardHeader><CardTitle>Wilayah Waktu</CardTitle></CardHeader>
 <CardContent className="space-y-4">
 <div>
 <Label htmlFor="app_timezone">Zona Waktu</Label>
 <select
 id="app_timezone"
 className="w-full border rounded-md p-2"
 value={data.app_timezone}
 onChange={(e) => setData('app_timezone', e.target.value)}
 >
 <option value="Asia/Jakarta">WIB (Asia/Jakarta, UTC+7)</option>
 <option value="Asia/Pontianak">WIB (Asia/Pontianak, UTC+7)</option>
 <option value="Asia/Makassar">WITA (Asia/Makassar, UTC+8)</option>
 <option value="Asia/Jayapura">WIT (Asia/Jayapura, UTC+9)</option>
 </select>
 {errors.app_timezone && <div className="text-red-500 text-xs mt-1">{errors.app_timezone}</div>}
 </div>
 </CardContent>
 </Card>

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

 <Card>
 <CardHeader><CardTitle>Keamanan & Batasan Perangkat (1 Akun 1 HP)</CardTitle></CardHeader>
 <CardContent className="space-y-4">
 <div className="flex items-center justify-between">
 <div className="space-y-0.5">
 <Label htmlFor="device_lock_enabled" className="text-base font-medium">
 Aktifkan Kunci 1 Akun 1 HP
 </Label>
 <p className="text-sm text-muted-foreground">
 Setiap akun user/admin hanya bisa login pada 1 perangkat smartphone. Admin dapat membuka kunci perangkat melalui menu Manajemen User/Operator.
 </p>
 </div>
 <Switch
 id="device_lock_enabled"
 checked={data.device_lock_enabled}
 onCheckedChange={(checked) => setData('device_lock_enabled', checked)}
 />
 </div>
 </CardContent>
 </Card>
 </TabsContent>

 {showConfigSaveButton && (
  <div className="flex justify-end mt-4">
   <Button type="submit" disabled={processing}>Simpan Perubahan</Button>
  </div>
 )}
 </form>

 <TabsContent value="sparc" className="space-y-4 mt-4">
 <Card>
 <CardHeader>
 <CardTitle>Integrasi Ruang Kerja HUMAS SPARC v3</CardTitle>
 </CardHeader>
 <CardContent className="space-y-5">
 <p className="text-sm text-muted-foreground">
 Menghubungkan HaloAPU dengan SPARC v3 untuk menyinkronkan pengajuan tiket Humas (Reels &amp; Desain Feed/Meme) dua arah.
 Tiket otomatis terkirim via Webhook saat diajukan, dan pembaruan dari SPARC diterima melalui REST API.
 </p>

 <div className="flex items-center justify-between p-3.5 rounded-lg border bg-muted/30">
 <div className="space-y-0.5">
 <Label className="text-base font-semibold">Aktifkan Integrasi SPARC</Label>
 <p className="text-xs text-muted-foreground">Kirimkan webhook otomatis ke SPARC saat tiket Humas dibuat atau diubah.</p>
 </div>
 <Switch
 checked={sparcState.sparc_integration_enabled}
 onCheckedChange={(val) => setSparcState((prev) => ({ ...prev, sparc_integration_enabled: val }))}
 />
 </div>

 <div className="space-y-2">
 <Label>Base URL SPARC</Label>
 <Input
 placeholder="http://localhost:8000 atau https://sparc.alazharpeduli.or.id"
 value={sparcState.sparc_base_url}
 onChange={(e) => setSparcState((prev) => ({ ...prev, sparc_base_url: e.target.value }))}
 />
 <p className="text-xs text-muted-foreground">URL root instansi SPARC v3 (tanpa trailing slash).</p>
 </div>

 <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
 <div className="space-y-2">
 <Label>SPARC API Bearer Token</Label>
 <Input
 readOnly
 value={sparcState.sparc_api_token || 'Belum di-generate'}
 className="font-mono text-xs bg-muted/40"
 />
 <p className="text-xs text-muted-foreground">Token untuk memvalidasi request S2S dari SPARC ke HaloAPU.</p>
 </div>
 <div className="space-y-2">
 <Label>Webhook HMAC Secret</Label>
 <Input
 readOnly
 value={sparcState.sparc_webhook_secret || 'Belum di-generate'}
 className="font-mono text-xs bg-muted/40"
 />
 <p className="text-xs text-muted-foreground">Kunci rahasia untuk menyegel payload webhook HMAC-SHA256 ke SPARC.</p>
 </div>
 </div>

 <div className="flex flex-wrap items-center gap-2">
 <Button
 type="button"
 variant="outline"
 size="sm"
 onClick={handleGenerateCreds}
 disabled={isGeneratingCreds}
 >
 {isGeneratingCreds ? 'Memproses...' : '🔑 Generate / Perbarui Kredensial'}
 </Button>
 </div>

 <div className="space-y-2">
 <Label>Operator Default Humas</Label>
 <select
 className="w-full border rounded-md px-3 py-2 text-sm bg-background"
 value={sparcState.sparc_default_admin_id}
 onChange={(e) => setSparcState((prev) => ({ ...prev, sparc_default_admin_id: e.target.value }))}
 >
 <option value="">-- Pilih Operator Humas --</option>
 {(configs.humasAdmins || []).map((adm: HumasAdminItem) => (
 <option key={adm.id} value={adm.id}>
 {adm.name} ({adm.username})
 </option>
 ))}
 </select>
 <p className="text-xs text-muted-foreground">Admin default yang dicatat pada TicketLog jika nama PIC SPARC tidak cocok dengan akun admin HaloAPU.</p>
 </div>

 <div className="p-3.5 rounded-lg border bg-muted/20 space-y-2 text-xs">
 <p className="font-semibold text-foreground">Endpoint S2S HaloAPU (Didaftarkan di Settings SPARC):</p>
 <code className="block p-2 rounded bg-muted font-mono text-[11px] select-all">
 {typeof window !== 'undefined' ? `${window.location.origin}/api/integrations/sparc` : '/api/integrations/sparc'}
 </code>
 <p className="text-muted-foreground">
 Salin <strong>SPARC API Bearer Token</strong> dan <strong>Webhook Secret</strong> di atas ke menu <em>Settings → HALO APU</em> di aplikasi SPARC.
 </p>
 </div>

 {testSparcResult && (
 <div className={`p-3 rounded-lg text-xs border ${testSparcResult.success ? 'bg-green-500/10 border-green-500/30 text-green-700 dark:text-green-300' : 'bg-destructive/10 border-destructive/30 text-destructive'}`}>
 {testSparcResult.message}
 </div>
 )}

 <div className="flex flex-wrap items-center justify-between pt-4 border-t gap-2">
 <Button
 type="button"
 variant="outline"
 onClick={handleTestSparc}
 disabled={isTestingSparc || !sparcState.sparc_base_url}
 >
 {isTestingSparc ? 'Menguji...' : '🔌 Tes Koneksi Webhook ke SPARC'}
 </Button>
 <Button
 type="button"
 onClick={handleSaveSparc}
 disabled={isSavingSparc}
 >
 {isSavingSparc ? 'Menyimpan...' : 'Simpan Integrasi SPARC'}
 </Button>
 </div>
 </CardContent>
 </Card>
 </TabsContent>

 <TabsContent value="scheduler" className="space-y-4 mt-4">
 <Card>
 <CardHeader><CardTitle>Jalankan Scheduler Manual</CardTitle></CardHeader>
 <CardContent className="space-y-4">
 <p className="text-sm text-muted-foreground">
 Gunakan tombol di bawah untuk menjalankan pengecekan SLA dan reminder secara manual.
 Ini berguna untuk testing atau jika cron job di server belum aktif.
 </p>
 <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
 <Button
 variant="outline"
 onClick={() => router.post(route('admin.scheduler.sla-check'))}
 className="justify-start"
 >
 🔍 Cek SLA Sekarang
 </Button>
 <Button
 variant="outline"
 onClick={() => router.post(route('admin.scheduler.booking-reminder'))}
 className="justify-start"
 >
 📅 Kirim Reminder Booking
 </Button>
 <Button
 variant="outline"
 onClick={() => router.post(route('admin.scheduler.pending-reminder'))}
 className="justify-start"
 >
 ⏳ Kirim Reminder Tiket Pending
 </Button>
 <Button
 variant="outline"
 onClick={() => router.post(route('admin.scheduler.csat-reminder'))}
 className="justify-start"
 >
 ⭐ Kirim Reminder CSAT
 </Button>
 </div>
 <div className="pt-3 border-t">
 <Button
 onClick={() => router.post(route('admin.scheduler.run-all'))}
 className="w-full"
 >
 🚀 Jalankan Semua Scheduler
 </Button>
 </div>
 </CardContent>
 </Card>
 </TabsContent>

 <TabsContent value="update_aplikasi" className="space-y-4 mt-4">
 <AppUpdateTab
  appReleases={appReleases}
  allowedReleaseHosts={allowedReleaseHosts}
 />
 </TabsContent>
 </Tabs>
 </div>
 </AdminLayout>
 );
}
