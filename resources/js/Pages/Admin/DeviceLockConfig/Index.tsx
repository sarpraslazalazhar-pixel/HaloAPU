import React, { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Badge } from '@/Components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger,
} from '@/Components/ui/alert-dialog';
import { motion } from 'framer-motion';
import {
  Smartphone, Shield, Users, Search, Unlock, Trash2,
  Settings, MonitorSmartphone, RotateCcw, Lock
} from 'lucide-react';

interface DeviceRecord {
  id: number;
  device_id: string;
  device_name: string | null;
  ip_address: string | null;
  last_login_at: string | null;
}

interface AccountRecord {
  id: number;
  account_type: 'user' | 'admin';
  account_type_label: string;
  name: string;
  username: string;
  email: string;
  department: string;
  device_id: string | null;
  device_name: string | null;
  is_locked: boolean;
  devices: DeviceRecord[];
  device_count: number;
}

interface Stats {
  total_accounts: number;
  accounts_locked: number;
  total_devices: number;
  total_users: number;
  total_admins: number;
  users_locked: number;
  admins_locked: number;
}

interface Configs {
  device_lock_enabled: boolean;
  device_lock_target: string;
  device_lock_max_devices: number;
  device_lock_auto_unlock_days: number;
}

interface Filters {
  search: string;
  type: string;
  status: string;
}

interface Props {
  configs: Configs;
  stats: Stats;
  accounts: AccountRecord[];
  filters: Filters;
}

export default function DeviceLockConfigIndex({ configs, stats, accounts, filters }: Props) {
  const { data, setData, put, processing } = useForm({
    device_lock_enabled: configs.device_lock_enabled,
    device_lock_target: configs.device_lock_target || 'all',
    device_lock_max_devices: configs.device_lock_max_devices || 1,
    device_lock_auto_unlock_days: configs.device_lock_auto_unlock_days || 0,
  });

  const [search, setSearch] = useState(filters?.search || '');
  const [typeFilter, setTypeFilter] = useState(filters?.type || 'all');
  const [statusFilter, setStatusFilter] = useState(filters?.status || 'all');
  const [expandedAccount, setExpandedAccount] = useState<string | null>(null);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    put(route('admin.device-lock-config.update'));
  };

  const applyFilters = () => {
    router.get(route('admin.device-lock-config.index'), {
      search: search || undefined,
      type: typeFilter !== 'all' ? typeFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
    }, { preserveState: true });
  };

  const resetFilters = () => {
    setSearch('');
    setTypeFilter('all');
    setStatusFilter('all');
    router.get(route('admin.device-lock-config.index'), {}, { preserveState: true });
  };

  const toggleExpand = (key: string) => {
    setExpandedAccount(expandedAccount === key ? null : key);
  };

  return (
    <AdminLayout title="Peraturan Lock Device">
      <Head title="Peraturan Lock Device" />
      <div className="max-w-6xl mx-auto space-y-6">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Peraturan Lock Device</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Atur kebijakan pengikatan perangkat (1 Akun 1 HP) dan kelola perangkat terdaftar.
          </p>
        </div>

        <Tabs defaultValue="kebijakan">
          <TabsList>
            <TabsTrigger value="kebijakan" className="gap-1.5">
              <Settings className="h-3.5 w-3.5" />
              Kebijakan
            </TabsTrigger>
            <TabsTrigger value="monitoring" className="gap-1.5">
              <MonitorSmartphone className="h-3.5 w-3.5" />
              Monitoring Perangkat
            </TabsTrigger>
          </TabsList>

          {/* ========== TAB 1: KEBIJAKAN ========== */}
          <TabsContent value="kebijakan" className="space-y-4 mt-4">
            <form onSubmit={handleSubmit}>
              {/* Status Penguncian */}
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <Lock className="h-5 w-5 text-sky-500" />
                    Status Penguncian Perangkat
                  </CardTitle>
                </CardHeader>
                <CardContent className="space-y-6">
                  <div className="flex items-center justify-between p-4 border rounded-lg bg-muted/30">
                    <div className="space-y-0.5">
                      <Label className="text-base font-semibold">
                        Aktifkan Pembatasan Perangkat
                      </Label>
                      <p className="text-sm text-muted-foreground">
                        Jika diaktifkan, setiap akun hanya dapat digunakan pada sejumlah perangkat sesuai batas yang ditetapkan.
                      </p>
                    </div>
                    <Switch
                      checked={data.device_lock_enabled}
                      onCheckedChange={(checked) => setData('device_lock_enabled', checked)}
                    />
                  </div>

                  {/* Target Penerapan */}
                  <div className="space-y-3">
                    <Label className="text-sm font-semibold">Target Penerapan</Label>
                    <p className="text-xs text-muted-foreground -mt-2">
                      Pilih tipe akun yang terkena aturan pembatasan perangkat.
                    </p>
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                      {[
                        { value: 'all', label: 'Semua (User & Operator)', desc: 'Berlaku untuk seluruh akun', icon: Users },
                        { value: 'user', label: 'Hanya User', desc: 'Hanya akun karyawan/pengguna', icon: Users },
                        { value: 'admin', label: 'Hanya Operator', desc: 'Hanya akun operator/admin', icon: Shield },
                      ].map((opt) => (
                        <button
                          key={opt.value}
                          type="button"
                          onClick={() => setData('device_lock_target', opt.value)}
                          className={`relative flex flex-col items-start p-4 border rounded-xl transition-all text-left ${
                            data.device_lock_target === opt.value
                              ? 'border-sky-500 bg-sky-50 ring-2 ring-sky-500/20'
                              : 'border-border hover:border-muted-foreground/30 hover:bg-muted/30'
                          }`}
                        >
                          <opt.icon className={`h-5 w-5 mb-2 ${data.device_lock_target === opt.value ? 'text-sky-600' : 'text-muted-foreground'}`} />
                          <span className={`text-sm font-medium ${data.device_lock_target === opt.value ? 'text-sky-700' : ''}`}>
                            {opt.label}
                          </span>
                          <span className="text-xs text-muted-foreground">{opt.desc}</span>
                        </button>
                      ))}
                    </div>
                  </div>

                  {/* Batas Maksimal Perangkat */}
                  <div className="space-y-2">
                    <Label className="text-sm font-semibold">Batas Maksimal Perangkat per Akun</Label>
                    <p className="text-xs text-muted-foreground">
                      Jumlah perangkat HP yang diizinkan untuk satu akun. Default: 1 perangkat.
                    </p>
                    <select
                      className="flex h-10 w-full max-w-xs rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                      value={data.device_lock_max_devices}
                      onChange={(e) => setData('device_lock_max_devices', parseInt(e.target.value))}
                    >
                      <option value={1}>1 Perangkat (Default)</option>
                      <option value={2}>2 Perangkat</option>
                      <option value={3}>3 Perangkat</option>
                      <option value={4}>4 Perangkat</option>
                      <option value={5}>5 Perangkat</option>
                    </select>
                  </div>

                  {/* Auto Unlock */}
                  <div className="space-y-2">
                    <Label className="text-sm font-semibold">Kadaluarsa Pengikatan Perangkat</Label>
                    <p className="text-xs text-muted-foreground">
                      Perangkat yang tidak aktif selama X hari akan otomatis dilepaskan ikatannya. Pilih "Selamanya" jika harus reset manual oleh Admin.
                    </p>
                    <select
                      className="flex h-10 w-full max-w-xs rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                      value={data.device_lock_auto_unlock_days}
                      onChange={(e) => setData('device_lock_auto_unlock_days', parseInt(e.target.value))}
                    >
                      <option value={0}>Selamanya (Reset Manual oleh Admin)</option>
                      <option value={7}>7 Hari</option>
                      <option value={14}>14 Hari</option>
                      <option value={30}>30 Hari</option>
                      <option value={60}>60 Hari</option>
                      <option value={90}>90 Hari</option>
                    </select>
                  </div>
                </CardContent>
              </Card>

              <div className="flex justify-end mt-4">
                <Button type="submit" disabled={processing}>
                  Simpan Kebijakan
                </Button>
              </div>
            </form>
          </TabsContent>

          {/* ========== TAB 2: MONITORING ========== */}
          <TabsContent value="monitoring" className="space-y-4 mt-4">
            {/* Stats Cards */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <Card>
                <CardContent className="p-4 text-center">
                  <Users className="h-6 w-6 mx-auto text-sky-500 mb-1" />
                  <div className="text-2xl font-bold">{stats.total_accounts}</div>
                  <div className="text-xs text-muted-foreground">Total Akun</div>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-4 text-center">
                  <Lock className="h-6 w-6 mx-auto text-amber-500 mb-1" />
                  <div className="text-2xl font-bold">{stats.accounts_locked}</div>
                  <div className="text-xs text-muted-foreground">Akun Terikat HP</div>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-4 text-center">
                  <Smartphone className="h-6 w-6 mx-auto text-green-500 mb-1" />
                  <div className="text-2xl font-bold">{stats.total_devices}</div>
                  <div className="text-xs text-muted-foreground">Total Perangkat</div>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-4 text-center">
                  <Unlock className="h-6 w-6 mx-auto text-gray-400 mb-1" />
                  <div className="text-2xl font-bold">{stats.total_accounts - stats.accounts_locked}</div>
                  <div className="text-xs text-muted-foreground">Akun Belum Terikat</div>
                </CardContent>
              </Card>
            </div>

            {/* Filters */}
            <Card>
              <CardContent className="p-4">
                <div className="flex flex-wrap gap-2 items-end">
                  <div className="flex-1 min-w-[200px]">
                    <Label className="text-xs mb-1 block">Cari</Label>
                    <div className="relative">
                      <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                      <Input
                        placeholder="Nama, email, username, atau HP..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                        className="pl-9"
                      />
                    </div>
                  </div>
                  <div>
                    <Label className="text-xs mb-1 block">Tipe Akun</Label>
                    <select
                      className="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                      value={typeFilter}
                      onChange={(e) => setTypeFilter(e.target.value)}
                    >
                      <option value="all">Semua</option>
                      <option value="user">User</option>
                      <option value="admin">Operator</option>
                    </select>
                  </div>
                  <div>
                    <Label className="text-xs mb-1 block">Status</Label>
                    <select
                      className="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                      value={statusFilter}
                      onChange={(e) => setStatusFilter(e.target.value)}
                    >
                      <option value="all">Semua</option>
                      <option value="locked">Terkunci</option>
                      <option value="unlocked">Belum Terikat</option>
                    </select>
                  </div>
                  <Button variant="outline" size="sm" onClick={applyFilters}>Cari</Button>
                  {(search || typeFilter !== 'all' || statusFilter !== 'all') && (
                    <Button variant="ghost" size="sm" onClick={resetFilters}>Reset</Button>
                  )}
                </div>
              </CardContent>
            </Card>

            {/* Bulk Action */}
            <div className="flex justify-end">
              <AlertDialog>
                <AlertDialogTrigger asChild>
                  <Button variant="destructive" size="sm" className="gap-1.5">
                    <RotateCcw className="h-3.5 w-3.5" />
                    Reset Semua Perangkat
                  </Button>
                </AlertDialogTrigger>
                <AlertDialogContent>
                  <AlertDialogHeader>
                    <AlertDialogTitle>⚠️ Reset Semua Perangkat di Sistem?</AlertDialogTitle>
                    <AlertDialogDescription>
                      Tindakan ini akan <strong>menghapus seluruh ikatan perangkat</strong> untuk semua akun User dan Operator di sistem.
                      <br /><br />
                      Semua pengguna harus login ulang dan perangkat mereka akan diikat kembali saat login berikutnya.
                      <br /><br />
                      <strong>Tindakan ini tidak dapat dibatalkan.</strong>
                    </AlertDialogDescription>
                  </AlertDialogHeader>
                  <AlertDialogFooter>
                    <AlertDialogCancel>Batal</AlertDialogCancel>
                    <AlertDialogAction
                      onClick={() => router.post(route('admin.device-lock-config.reset-all'), {}, { preserveScroll: true })}
                      className="bg-red-600 text-white hover:bg-red-700"
                    >
                      Ya, Reset Semua
                    </AlertDialogAction>
                  </AlertDialogFooter>
                </AlertDialogContent>
              </AlertDialog>
            </div>

            {/* Accounts / Devices Table */}
            <Card>
              <CardContent className="p-0">
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b bg-muted/30 text-left text-muted-foreground">
                        <th className="p-3 font-medium w-12">#</th>
                        <th className="p-3 font-medium">Nama Akun</th>
                        <th className="p-3 font-medium">Tipe</th>
                        <th className="p-3 font-medium">Divisi / Role</th>
                        <th className="p-3 font-medium">Perangkat Utama</th>
                        <th className="p-3 font-medium">Jumlah HP</th>
                        <th className="p-3 font-medium">Status</th>
                        <th className="p-3 font-medium w-28">Aksi</th>
                      </tr>
                    </thead>
                    <tbody>
                      {accounts.length === 0 && (
                        <tr>
                          <td colSpan={8} className="p-8 text-center text-muted-foreground">
                            <div className="flex flex-col items-center gap-2">
                              <Smartphone className="h-10 w-10 text-muted-foreground/50" />
                              <p className="font-medium">Tidak ada akun ditemukan</p>
                              <p className="text-xs">Coba ubah filter pencarian</p>
                            </div>
                          </td>
                        </tr>
                      )}
                      {accounts.map((account, i) => {
                        const key = `${account.account_type}-${account.id}`;
                        const isExpanded = expandedAccount === key;
                        return (
                          <React.Fragment key={key}>
                            <tr
                              className={`border-b last:border-0 hover:bg-muted/50 transition-colors cursor-pointer ${isExpanded ? 'bg-muted/30' : ''}`}
                              onClick={() => account.devices.length > 0 && toggleExpand(key)}
                            >
                              <td className="p-3 text-muted-foreground">{i + 1}</td>
                              <td className="p-3">
                                <div>
                                  <span className="font-medium">{account.name}</span>
                                  <div className="text-xs text-muted-foreground">{account.email}</div>
                                </div>
                              </td>
                              <td className="p-3">
                                <Badge variant="outline" className={
                                  account.account_type === 'admin'
                                    ? 'bg-purple-50 text-purple-700 border-purple-200'
                                    : 'bg-blue-50 text-blue-700 border-blue-200'
                                }>
                                  {account.account_type_label}
                                </Badge>
                              </td>
                              <td className="p-3 text-muted-foreground text-xs">{account.department}</td>
                              <td className="p-3">
                                {account.device_name ? (
                                  <Badge variant="outline" className="font-normal gap-1 bg-amber-50 text-amber-800 border-amber-300">
                                    <Smartphone className="h-3 w-3" />
                                    {account.device_name}
                                  </Badge>
                                ) : (
                                  <span className="text-muted-foreground/50 text-xs">—</span>
                                )}
                              </td>
                              <td className="p-3 text-center">
                                <span className="font-semibold">{account.device_count}</span>
                              </td>
                              <td className="p-3">
                                {account.is_locked ? (
                                  <Badge className="bg-amber-100 text-amber-800 border border-amber-300 hover:bg-amber-100">
                                    <Lock className="h-3 w-3 mr-1" />
                                    Terkunci
                                  </Badge>
                                ) : (
                                  <Badge variant="outline" className="text-muted-foreground">
                                    Bebas
                                  </Badge>
                                )}
                              </td>
                              <td className="p-3" onClick={(e) => e.stopPropagation()}>
                                {account.is_locked && (
                                  <AlertDialog>
                                    <AlertDialogTrigger asChild>
                                      <Button variant="outline" size="icon" className="h-8 w-8 text-amber-600 hover:text-amber-700 hover:bg-amber-50" title="Buka Kunci Semua Perangkat Akun Ini">
                                        <Unlock className="w-3.5 h-3.5" />
                                      </Button>
                                    </AlertDialogTrigger>
                                    <AlertDialogContent>
                                      <AlertDialogHeader>
                                        <AlertDialogTitle>Buka Kunci Semua Perangkat?</AlertDialogTitle>
                                        <AlertDialogDescription>
                                          Reset semua perangkat untuk akun <strong>"{account.name}"</strong> ({account.account_type_label})?
                                          <br /><br />
                                          Perangkat saat ini: <strong>{account.device_name || '-'}</strong>.
                                          Setelah dibuka, akun dapat login kembali menggunakan HP baru.
                                        </AlertDialogDescription>
                                      </AlertDialogHeader>
                                      <AlertDialogFooter>
                                        <AlertDialogCancel>Batal</AlertDialogCancel>
                                        <AlertDialogAction
                                          onClick={() => router.post(
                                            route('admin.device-lock-config.reset-account', { type: account.account_type, id: account.id }),
                                            {},
                                            { preserveScroll: true }
                                          )}
                                          className="bg-amber-600 text-white hover:bg-amber-700"
                                        >
                                          Buka Kunci
                                        </AlertDialogAction>
                                      </AlertDialogFooter>
                                    </AlertDialogContent>
                                  </AlertDialog>
                                )}
                              </td>
                            </tr>

                            {/* Expanded: Device detail rows */}
                            {isExpanded && account.devices.length > 0 && (
                              <tr>
                                <td colSpan={8} className="p-0 bg-muted/20">
                                  <motion.div
                                    initial={{ opacity: 0, height: 0 }}
                                    animate={{ opacity: 1, height: 'auto' }}
                                    exit={{ opacity: 0, height: 0 }}
                                    className="overflow-hidden"
                                  >
                                    <div className="px-6 py-3 space-y-2">
                                      <p className="text-xs font-semibold text-muted-foreground mb-2">Rincian Perangkat Terdaftar:</p>
                                      <table className="w-full text-xs">
                                        <thead>
                                          <tr className="text-left text-muted-foreground">
                                            <th className="pb-1 pr-3">Device ID</th>
                                            <th className="pb-1 pr-3">Nama/Model HP</th>
                                            <th className="pb-1 pr-3">IP Address</th>
                                            <th className="pb-1 pr-3">Terakhir Login</th>
                                            <th className="pb-1 w-16"></th>
                                          </tr>
                                        </thead>
                                        <tbody>
                                          {account.devices.map((device) => (
                                            <tr key={device.id} className="border-t border-muted/50">
                                              <td className="py-2 pr-3 font-mono text-[11px] break-all max-w-[200px]">{device.device_id}</td>
                                              <td className="py-2 pr-3">{device.device_name || '-'}</td>
                                              <td className="py-2 pr-3 text-muted-foreground">{device.ip_address || '-'}</td>
                                              <td className="py-2 pr-3 text-muted-foreground">{device.last_login_at || '-'}</td>
                                              <td className="py-2">
                                                <AlertDialog>
                                                  <AlertDialogTrigger asChild>
                                                    <Button variant="ghost" size="icon" className="h-6 w-6 text-red-500 hover:text-red-600 hover:bg-red-50" title="Cabut Perangkat Ini">
                                                      <Trash2 className="w-3 h-3" />
                                                    </Button>
                                                  </AlertDialogTrigger>
                                                  <AlertDialogContent>
                                                    <AlertDialogHeader>
                                                      <AlertDialogTitle>Cabut Perangkat Ini?</AlertDialogTitle>
                                                      <AlertDialogDescription>
                                                        Hapus perangkat <strong>"{device.device_name || device.device_id}"</strong> dari akun <strong>"{account.name}"</strong>?
                                                      </AlertDialogDescription>
                                                    </AlertDialogHeader>
                                                    <AlertDialogFooter>
                                                      <AlertDialogCancel>Batal</AlertDialogCancel>
                                                      <AlertDialogAction
                                                        onClick={() => router.post(
                                                          route('admin.device-lock-config.revoke-device', { deviceId: device.id }),
                                                          {},
                                                          { preserveScroll: true }
                                                        )}
                                                        className="bg-red-600 text-white hover:bg-red-700"
                                                      >
                                                        Cabut
                                                      </AlertDialogAction>
                                                    </AlertDialogFooter>
                                                  </AlertDialogContent>
                                                </AlertDialog>
                                              </td>
                                            </tr>
                                          ))}
                                        </tbody>
                                      </table>
                                    </div>
                                  </motion.div>
                                </td>
                              </tr>
                            )}
                          </React.Fragment>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </div>
    </AdminLayout>
  );
}
