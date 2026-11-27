import React, { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import {
  Bell,
  CheckCircle2,
  Download,
  Edit3,
  Plus,
  Power,
  PowerOff,
  RotateCcw,
  ShieldCheck,
  Smartphone,
  Trash2,
} from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/Components/ui/alert';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';

export type AppReleaseTargetAudience = 'all' | 'users' | 'admins';

export interface AppReleaseItem {
  id: number;
  platform: 'android' | 'ios';
  channel: 'production' | 'staging';
  target_audience: AppReleaseTargetAudience;
  version_name: string;
  build_number: number;
  min_supported_build_number: number;
  force_update: boolean;
  title: string | null;
  release_notes: string | null;
  download_url: string;
  sha256: string | null;
  file_size_bytes: number | null;
  is_active: boolean;
  published_at: string | null;
  last_notified_at: string | null;
  last_notified_count: number;
  last_notified_target: string | null;
  created_by_name: string | null;
  created_at: string | null;
}

interface AppUpdateTabProps {
  appReleases: AppReleaseItem[];
  allowedReleaseHosts: string[];
}

interface ReleaseFormData {
  platform: 'android' | 'ios';
  channel: 'production' | 'staging';
  target_audience: AppReleaseTargetAudience;
  version_name: string;
  build_number: string;
  min_supported_build_number: number;
  force_update: boolean;
  title: string;
  release_notes: string;
  download_url: string;
  sha256: string;
  file_size_bytes: string;
  is_active: boolean;
}

interface NotifyFormData {
  target: AppReleaseTargetAudience;
  notification_title: string;
  notification_body: string;
}

function formatFileSize(bytes: number | null): string {
  if (!bytes || bytes <= 0) {
    return '-';
  }

  const mb = bytes / (1024 * 1024);

  if (mb >= 1) {
    return `${mb.toFixed(1)} MB (${bytes.toLocaleString('id-ID')} B)`;
  }

  return `${(bytes / 1024).toFixed(1)} KB`;
}

function formatDateTime(iso: string | null): string {
  if (!iso) {
    return '-';
  }

  const parsed = new Date(iso);

  if (Number.isNaN(parsed.getTime())) {
    return iso;
  }

  return parsed.toLocaleString('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
  });
}

function normalizeTargetAudience(value?: string | null): AppReleaseTargetAudience {
  const normalized = (value ?? '').trim().toLowerCase();

  if (normalized === 'admins' || normalized === 'users') {
    return normalized;
  }

  return 'all';
}

function formatTargetLabel(target: string | null): string {
  if (target === 'users') {
    return 'Hanya Pengaju';
  }

  if (target === 'admins') {
    return 'Hanya Admin';
  }

  return 'Semua Pengguna & Admin';
}

interface AudienceBadgeInfo {
  label: string;
  className: string;
}

const AUDIENCE_BADGE_MAP: Record<AppReleaseTargetAudience, AudienceBadgeInfo> = {
  admins: {
    label: 'Khusus Admin',
    className: 'border-indigo-300 bg-indigo-50 text-indigo-700',
  },
  users: {
    label: 'Khusus Pengaju',
    className: 'border-teal-300 bg-teal-50 text-teal-700',
  },
  all: {
    label: 'Semua (Pengaju & Admin)',
    className: 'border-slate-300 bg-slate-50 text-slate-700',
  },
};

function formatAudienceBadge(audience: AppReleaseTargetAudience): AudienceBadgeInfo {
  return AUDIENCE_BADGE_MAP[audience];
}

function resolveEffectiveActiveRelease(
  releases: AppReleaseItem[],
  platform: 'android' | 'ios',
  channel: 'production' | 'staging',
  audience: 'admins' | 'users'
): AppReleaseItem | undefined {
  const candidates = releases.filter((item) => {
    if (item.platform !== platform || item.channel !== channel || !item.is_active) {
      return false;
    }

    const itemAudience = normalizeTargetAudience(item.target_audience);

    return itemAudience === 'all' || itemAudience === audience;
  });

  if (candidates.length === 0) {
    return undefined;
  }

  return [...candidates].sort((a, b) => {
    if (b.build_number !== a.build_number) {
      return b.build_number - a.build_number;
    }

    const aSpecific = normalizeTargetAudience(a.target_audience) !== 'all' ? 1 : 0;
    const bSpecific = normalizeTargetAudience(b.target_audience) !== 'all' ? 1 : 0;

    if (bSpecific !== aSpecific) {
      return bSpecific - aSpecific;
    }

    return b.id - a.id;
  })[0];
}

const INITIAL_RELEASE_FORM: ReleaseFormData = {
  platform: 'android',
  channel: 'production',
  target_audience: 'all',
  version_name: '',
  build_number: '',
  min_supported_build_number: 0,
  force_update: false,
  title: '',
  release_notes: '',
  download_url: '',
  sha256: '',
  file_size_bytes: '',
  is_active: true,
};

export default function AppUpdateTab({
  appReleases,
  allowedReleaseHosts,
}: AppUpdateTabProps) {
  const [editingRelease, setEditingRelease] = useState<AppReleaseItem | null>(null);

  const [notifyTargetRelease, setNotifyTargetRelease] = useState<AppReleaseItem | null>(null);

  const [busyActionId, setBusyActionId] = useState<number | null>(null);

  const releaseForm = useForm<ReleaseFormData>(INITIAL_RELEASE_FORM);

  const notifyForm = useForm<NotifyFormData>({
    target: 'all',
    notification_title: '',
    notification_body: '',
  });

  const activeProdAdmin = resolveEffectiveActiveRelease(
    appReleases,
    'android',
    'production',
    'admins'
  );

  const activeProdUser = resolveEffectiveActiveRelease(
    appReleases,
    'android',
    'production',
    'users'
  );

  const activeStagingAdmin = resolveEffectiveActiveRelease(
    appReleases,
    'android',
    'staging',
    'admins'
  );

  const activeStagingUser = resolveEffectiveActiveRelease(
    appReleases,
    'android',
    'staging',
    'users'
  );

  const resetReleaseForm = () => {
    setEditingRelease(null);

    releaseForm.reset();

    releaseForm.clearErrors();
  };

  const startEditRelease = (release: AppReleaseItem) => {
    setEditingRelease(release);

    releaseForm.clearErrors();

    releaseForm.setData({
      platform: release.platform,
      channel: release.channel,
      target_audience: normalizeTargetAudience(release.target_audience),
      version_name: release.version_name,
      build_number: String(release.build_number),
      min_supported_build_number: 0,
      force_update: false,
      title: release.title ?? '',
      release_notes: release.release_notes ?? '',
      download_url: release.download_url,
      sha256: release.sha256 ?? '',
      file_size_bytes: release.file_size_bytes ? String(release.file_size_bytes) : '',
      is_active: release.is_active,
    });
  };

  const handleSubmitRelease = (e: React.FormEvent) => {
    e.preventDefault();

    if (editingRelease) {
      releaseForm.put(route('admin.app-releases.update', editingRelease.id), {
        preserveScroll: true,
        onSuccess: () => {
          resetReleaseForm();
        },
      });

      return;
    }

    releaseForm.post(route('admin.app-releases.store'), {
      preserveScroll: true,
      onSuccess: () => {
        resetReleaseForm();
      },
    });
  };

  const handleActivate = (release: AppReleaseItem) => {
    setBusyActionId(release.id);

    router.post(
      route('admin.app-releases.activate', release.id),
      {},
      {
        preserveScroll: true,
        onFinish: () => setBusyActionId(null),
      }
    );
  };

  const handleDeactivate = (release: AppReleaseItem) => {
    setBusyActionId(release.id);

    router.post(
      route('admin.app-releases.deactivate', release.id),
      {},
      {
        preserveScroll: true,
        onFinish: () => setBusyActionId(null),
      }
    );
  };

  const handleDelete = (release: AppReleaseItem) => {
    if (
      !window.confirm(
        `Hapus riwayat rilis v${release.version_name} (Build #${release.build_number})?`
      )
    ) {
      return;
    }

    setBusyActionId(release.id);

    router.delete(route('admin.app-releases.destroy', release.id), {
      preserveScroll: true,
      onFinish: () => setBusyActionId(null),
    });
  };

  const openNotifyModal = (release: AppReleaseItem) => {
    const fallbackBody =
      release.release_notes?.trim() ||
      `Versi ${release.version_name} (Build #${release.build_number}) telah tersedia. Ketuk untuk memperbarui aplikasi.`;

    setNotifyTargetRelease(release);

    notifyForm.clearErrors();

    notifyForm.setData({
      target: normalizeTargetAudience(release.target_audience),
      notification_title:
        release.title?.trim() || `Update Halo APU v${release.version_name} Tersedia`,
      notification_body: fallbackBody.slice(0, 280),
    });
  };

  const handleSendNotify = (e: React.FormEvent) => {
    e.preventDefault();

    if (!notifyTargetRelease) {
      return;
    }

    notifyForm.post(route('admin.app-releases.notify', notifyTargetRelease.id), {
      preserveScroll: true,
      onSuccess: () => {
        setNotifyTargetRelease(null);
      },
    });
  };

  const parsedSizeBytes = Number(releaseForm.data.file_size_bytes);

  const previewSizeText =
    Number.isFinite(parsedSizeBytes) && parsedSizeBytes > 0
      ? formatFileSize(parsedSizeBytes)
      : null;

  const lockedAudience =
    notifyTargetRelease !== null
      ? normalizeTargetAudience(notifyTargetRelease.target_audience)
      : 'all';

  const isTargetLocked = lockedAudience !== 'all';

  return (
    <div className="space-y-6">
      <Alert className="border-amber-300 bg-amber-50 text-amber-950">
        <ShieldCheck className="h-4 w-4 text-amber-700" />
        <AlertTitle className="font-semibold text-amber-950">
          Pemberitahuan Versi Baru &amp; Update Langsung Tanpa Uninstall (In-Place Update)
        </AlertTitle>
        <AlertDescription className="text-xs text-amber-900 space-y-1 [&_p:not(:last-child)]:mb-1.5">
          <p>
            Pemberitahuan update di aplikasi mobile selalu bersifat <strong>opsional</strong> —
            hanya memberi tahu pengguna ada versi baru, pengguna tetap dapat memilih{' '}
            <strong>Nanti Saja</strong>, dan dapat langsung memperbarui tanpa uninstall saat siap.
          </p>
          <p>
            1. <strong>Build Number (versionCode)</strong> harus selalu{' '}
            <strong>lebih tinggi</strong> dari versi yang terpasang di HP pengguna (Android menolak
            downgrade secara in-place).
          </p>
          <p>
            2. File APK harus ditandatangani dengan <strong>keystore rilis yang sama</strong> dan
            menyertakan checksum <strong>SHA-256 (64 hex)</strong> dari GitHub Releases.
          </p>
          <p>
            3. Setiap rilis dapat ditujukan ke <strong>Semua (Pengaju &amp; Admin)</strong>,{' '}
            <strong>Khusus Admin / Operator</strong>, atau <strong>Khusus Pengaju</strong>.
            Mengaktifkan rilis khusus role tetap mempertahankan rilis aktif untuk audiens lainnya.
          </p>
        </AlertDescription>
      </Alert>

      {/* Active Release Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <ActiveChannelSummaryCard
          label="Production (Android)"
          adminRelease={activeProdAdmin}
          userRelease={activeProdUser}
          onNotify={openNotifyModal}
        />
        <ActiveChannelSummaryCard
          label="Staging (Android)"
          adminRelease={activeStagingAdmin}
          userRelease={activeStagingUser}
          onNotify={openNotifyModal}
        />
      </div>

      {/* Add / Edit Release Form */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between gap-2">
          <CardTitle className="flex items-center gap-2 text-base">
            {editingRelease ? <Edit3 className="h-4 w-4" /> : <Plus className="h-4 w-4" />}
            {editingRelease
              ? `Edit Rilis v${editingRelease.version_name} (Build #${editingRelease.build_number})`
              : 'Tambah Rilis Mobile Baru'}
          </CardTitle>
          {editingRelease && (
            <Button type="button" variant="ghost" size="sm" onClick={resetReleaseForm}>
              Batal Edit
            </Button>
          )}
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmitRelease} className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <div className="space-y-1.5">
                <Label htmlFor="release_platform">Platform</Label>
                <select
                  id="release_platform"
                  disabled={Boolean(editingRelease)}
                  value={releaseForm.data.platform}
                  onChange={(e) =>
                    releaseForm.setData(
                      'platform',
                      e.target.value === 'ios' ? 'ios' : 'android'
                    )
                  }
                  className="w-full h-9 rounded-md border border-input bg-background px-3 text-sm disabled:opacity-60"
                >
                  <option value="android">Android (APK)</option>
                  <option value="ios">iOS</option>
                </select>
                {releaseForm.errors.platform && (
                  <p className="text-xs text-destructive">{releaseForm.errors.platform}</p>
                )}
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="release_channel">Channel</Label>
                <select
                  id="release_channel"
                  disabled={Boolean(editingRelease)}
                  value={releaseForm.data.channel}
                  onChange={(e) =>
                    releaseForm.setData(
                      'channel',
                      e.target.value === 'staging' ? 'staging' : 'production'
                    )
                  }
                  className="w-full h-9 rounded-md border border-input bg-background px-3 text-sm disabled:opacity-60"
                >
                  <option value="production">Production</option>
                  <option value="staging">Staging</option>
                </select>
                {releaseForm.errors.channel && (
                  <p className="text-xs text-destructive">{releaseForm.errors.channel}</p>
                )}
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="release_version_name">Versi (versionName)</Label>
                <Input
                  id="release_version_name"
                  placeholder="cth: 1.0.2"
                  required
                  value={releaseForm.data.version_name}
                  onChange={(e) => releaseForm.setData('version_name', e.target.value)}
                />
                {releaseForm.errors.version_name && (
                  <p className="text-xs text-destructive">{releaseForm.errors.version_name}</p>
                )}
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="release_build_number">Build Number (versionCode)</Label>
                <Input
                  id="release_build_number"
                  type="number"
                  min={1}
                  placeholder="cth: 12"
                  required
                  value={releaseForm.data.build_number}
                  onChange={(e) => releaseForm.setData('build_number', e.target.value)}
                />
                <p className="text-[11px] text-muted-foreground">
                  Harus lebih tinggi dari build terpasang untuk in-place update.
                </p>
                {releaseForm.errors.build_number && (
                  <p className="text-xs text-destructive">{releaseForm.errors.build_number}</p>
                )}
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div className="space-y-1.5">
                <Label htmlFor="release_target_audience">Target Rilis (Audiens)</Label>
                <select
                  id="release_target_audience"
                  value={releaseForm.data.target_audience}
                  onChange={(e) =>
                    releaseForm.setData('target_audience', normalizeTargetAudience(e.target.value))
                  }
                  className="w-full h-9 rounded-md border border-input bg-background px-3 text-sm"
                >
                  <option value="all">Semua (Pengaju &amp; Admin)</option>
                  <option value="admins">Khusus Admin / Operator</option>
                  <option value="users">Khusus Pengaju (User)</option>
                </select>
                <p className="text-[11px] text-muted-foreground">
                  Pilih Khusus Admin jika pembaruan hanya untuk fitur operator agar Pengaju tidak
                  mendapat popup update. Rilis aktif Pengaju sebelumnya tetap dipertahankan.
                </p>
                {releaseForm.errors.target_audience && (
                  <p className="text-xs text-destructive">{releaseForm.errors.target_audience}</p>
                )}
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="release_title">Judul Pemberitahuan Update</Label>
                <Input
                  id="release_title"
                  placeholder="cth: Pembaruan Keamanan & Performa"
                  value={releaseForm.data.title}
                  onChange={(e) => releaseForm.setData('title', e.target.value)}
                />
                {releaseForm.errors.title && (
                  <p className="text-xs text-destructive">{releaseForm.errors.title}</p>
                )}
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="release_file_size">Ukuran File (Bytes, Opsional)</Label>
                <Input
                  id="release_file_size"
                  type="number"
                  min={1}
                  placeholder="cth: 44564480"
                  value={releaseForm.data.file_size_bytes}
                  onChange={(e) => releaseForm.setData('file_size_bytes', e.target.value)}
                />
                <p className="text-[11px] text-muted-foreground">
                  {previewSizeText ? `Terbaca: ${previewSizeText}` : 'Ditampilkan di dialog unduhan.'}
                </p>
                {releaseForm.errors.file_size_bytes && (
                  <p className="text-xs text-destructive">{releaseForm.errors.file_size_bytes}</p>
                )}
              </div>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="release_download_url">URL GitHub Release / APK (Wajib HTTPS)</Label>
              <Input
                id="release_download_url"
                type="url"
                required
                placeholder="https://github.com/org/repo/releases/download/v1.0.2/haloapu-production-v1.0.2-b12.apk"
                value={releaseForm.data.download_url}
                onChange={(e) => releaseForm.setData('download_url', e.target.value)}
              />
              <p className="text-[11px] text-muted-foreground">
                Host yang diizinkan: {allowedReleaseHosts.join(', ')}
              </p>
              {releaseForm.errors.download_url && (
                <p className="text-xs text-destructive">{releaseForm.errors.download_url}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="release_sha256">
                SHA-256 Checksum (
                {releaseForm.data.platform === 'android' ? 'Wajib 64 Hex' : 'Opsional'})
              </Label>
              <Input
                id="release_sha256"
                className="font-mono text-xs"
                placeholder="64 karakter hex dari checksums-sha256.txt"
                required={releaseForm.data.platform === 'android'}
                value={releaseForm.data.sha256}
                onChange={(e) => releaseForm.setData('sha256', e.target.value)}
              />
              {releaseForm.errors.sha256 && (
                <p className="text-xs text-destructive">{releaseForm.errors.sha256}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="release_notes">Catatan Pembaruan (Changelog)</Label>
              <Textarea
                id="release_notes"
                rows={4}
                placeholder="- Perbaikan sesi login mobile&#10;- Peningkatan performa dashboard"
                value={releaseForm.data.release_notes}
                onChange={(e) => releaseForm.setData('release_notes', e.target.value)}
              />
              {releaseForm.errors.release_notes && (
                <p className="text-xs text-destructive">{releaseForm.errors.release_notes}</p>
              )}
            </div>

            <div className="flex items-center justify-between p-3 rounded-lg border bg-muted/20">
              <div className="space-y-0.5 pr-3">
                <Label htmlFor="release_is_active" className="font-medium">
                  Langsung Aktifkan Rilis Ini
                </Label>
                <p className="text-xs text-muted-foreground">
                  Pemberitahuan di mobile selalu bersifat opsional (pengguna bisa pilih Nanti Saja).
                  Rilis aktif sebelumnya pada platform &amp; channel yang sama otomatis dinonaktifkan.
                </p>
              </div>
              <Switch
                id="release_is_active"
                checked={releaseForm.data.is_active}
                onCheckedChange={(checked) => releaseForm.setData('is_active', checked)}
              />
            </div>

            <div className="flex justify-end gap-2 pt-2">
              {editingRelease && (
                <Button type="button" variant="outline" onClick={resetReleaseForm}>
                  Batal
                </Button>
              )}
              <Button type="submit" disabled={releaseForm.processing}>
                {releaseForm.processing
                  ? 'Menyimpan...'
                  : editingRelease
                    ? 'Simpan Perubahan Rilis'
                    : 'Simpan Rilis Baru'}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      {/* Release History Table */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Riwayat Rilis Aplikasi Mobile</CardTitle>
        </CardHeader>
        <CardContent>
          {appReleases.length === 0 ? (
            <div className="text-center py-8 text-sm text-muted-foreground">
              Belum ada rilis mobile yang terdaftar. Tambahkan rilis baru di form atas atau hubungkan
              webhook GitHub Actions.
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Versi &amp; Build</TableHead>
                  <TableHead>Target</TableHead>
                  <TableHead>Mode Update</TableHead>
                  <TableHead>Status &amp; Notifikasi</TableHead>
                  <TableHead className="text-right">Aksi</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {appReleases.map((release) => {
                  const isBusy = busyActionId === release.id;
                  const audience = normalizeTargetAudience(release.target_audience);
                  const audienceBadge = formatAudienceBadge(audience);

                  return (
                    <TableRow key={release.id}>
                      <TableCell className="align-top whitespace-normal">
                        <div className="font-semibold text-sm">
                          v{release.version_name}{' '}
                          <span className="font-mono text-xs text-primary">
                            (Build #{release.build_number})
                          </span>
                        </div>
                        <div className="text-xs text-muted-foreground mt-0.5">
                          Ukuran: {formatFileSize(release.file_size_bytes)}
                        </div>
                        {release.title && (
                          <div className="text-xs text-foreground/80 mt-0.5">{release.title}</div>
                        )}
                      </TableCell>

                      <TableCell className="align-top">
                        <div className="flex flex-col gap-1">
                          <Badge variant="outline" className="uppercase w-fit">
                            {release.platform}
                          </Badge>
                          <Badge
                            variant={release.channel === 'production' ? 'default' : 'secondary'}
                            className="capitalize w-fit"
                          >
                            {release.channel}
                          </Badge>
                          <Badge variant="outline" className={`w-fit ${audienceBadge.className}`}>
                            {audienceBadge.label}
                          </Badge>
                        </div>
                      </TableCell>

                      <TableCell className="align-top">
                        <Badge variant="secondary">Pemberitahuan Opsional</Badge>
                      </TableCell>

                      <TableCell className="align-top whitespace-normal">
                        <div className="flex flex-col gap-1">
                          {release.is_active ? (
                            <Badge className="bg-emerald-600 text-white w-fit gap-1">
                              <CheckCircle2 className="h-3 w-3" />
                              Aktif
                            </Badge>
                          ) : (
                            <Badge variant="outline" className="w-fit text-muted-foreground">
                              Nonaktif
                            </Badge>
                          )}
                          <span className="text-[11px] text-muted-foreground">
                            {release.last_notified_at
                              ? `Notif: ${formatDateTime(release.last_notified_at)} (${release.last_notified_count} HP • ${formatTargetLabel(release.last_notified_target)})`
                              : 'Belum pernah dikirim notifikasi'}
                          </span>
                        </div>
                      </TableCell>

                      <TableCell className="align-top text-right">
                        <div className="flex flex-wrap justify-end gap-1.5">
                          {release.is_active ? (
                            <>
                              <Button
                                type="button"
                                size="sm"
                                variant="default"
                                disabled={isBusy}
                                onClick={() => openNotifyModal(release)}
                              >
                                <Bell className="h-3.5 w-3.5 mr-1" />
                                Kirim Notif
                              </Button>
                              <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={isBusy}
                                onClick={() => handleDeactivate(release)}
                              >
                                <PowerOff className="h-3.5 w-3.5 mr-1" />
                                Nonaktifkan
                              </Button>
                            </>
                          ) : (
                            <Button
                              type="button"
                              size="sm"
                              variant="outline"
                              title="Aktifkan rilis ini (Android hanya mendukung upgrade in-place ke build number lebih tinggi, tidak bisa downgrade build yang sudah terpasang)"
                              disabled={isBusy}
                              onClick={() => handleActivate(release)}
                            >
                              <RotateCcw className="h-3.5 w-3.5 mr-1" />
                              Aktifkan / Rollback
                            </Button>
                          )}

                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={isBusy}
                            onClick={() => startEditRelease(release)}
                          >
                            <Edit3 className="h-3.5 w-3.5 mr-1" />
                            Edit
                          </Button>

                          {!release.is_active && (
                            <Button
                              type="button"
                              size="sm"
                              variant="destructive"
                              disabled={isBusy}
                              onClick={() => handleDelete(release)}
                            >
                              <Trash2 className="h-3.5 w-3.5" />
                            </Button>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Broadcast Push Notification Dialog */}
      <Dialog
        open={Boolean(notifyTargetRelease)}
        onOpenChange={(open) => {
          if (!open) {
            setNotifyTargetRelease(null);
          }
        }}
      >
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Bell className="h-4 w-4 text-primary" />
              Kirim Notifikasi Update ke HP Pengguna
            </DialogTitle>
            <DialogDescription>
              {notifyTargetRelease
                ? `Rilis aktif v${notifyTargetRelease.version_name} (Build #${notifyTargetRelease.build_number} • ${notifyTargetRelease.channel.toUpperCase()}). Saat notifikasi diketuk, aplikasi langsung membuka dialog pemberitahuan versi baru (opsional).`
                : ''}
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSendNotify} className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="notify_target">Target Penerima</Label>
              <select
                id="notify_target"
                disabled={isTargetLocked}
                value={notifyForm.data.target}
                onChange={(e) => {
                  notifyForm.setData('target', normalizeTargetAudience(e.target.value));
                }}
                className="w-full h-9 rounded-md border border-input bg-background px-3 text-sm disabled:opacity-60 disabled:cursor-not-allowed"
              >
                <option value="all">Semua Pengguna &amp; Admin</option>
                <option value="users">Hanya Pengaju (User)</option>
                <option value="admins">Hanya Admin / Operator</option>
              </select>
              {isTargetLocked && (
                <p className="text-[11px] text-muted-foreground">
                  {lockedAudience === 'admins'
                    ? 'Terkunci otomatis: Rilis Khusus Admin hanya mengirim notifikasi ke Admin / Operator.'
                    : 'Terkunci otomatis: Rilis Khusus Pengaju hanya mengirim notifikasi ke Pengaju (User).'}
                </p>
              )}
              {notifyForm.errors.target && (
                <p className="text-xs text-destructive">{notifyForm.errors.target}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="notify_title">Judul Notifikasi</Label>
              <Input
                id="notify_title"
                required
                maxLength={120}
                value={notifyForm.data.notification_title}
                onChange={(e) => notifyForm.setData('notification_title', e.target.value)}
              />
              {notifyForm.errors.notification_title && (
                <p className="text-xs text-destructive">{notifyForm.errors.notification_title}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="notify_body">Isi Pesan Notifikasi</Label>
              <Textarea
                id="notify_body"
                required
                rows={3}
                maxLength={300}
                value={notifyForm.data.notification_body}
                onChange={(e) => notifyForm.setData('notification_body', e.target.value)}
              />
              <p className="text-[11px] text-muted-foreground text-right">
                {notifyForm.data.notification_body.length}/300 karakter
              </p>
              {notifyForm.errors.notification_body && (
                <p className="text-xs text-destructive">{notifyForm.errors.notification_body}</p>
              )}
            </div>

            <div className="rounded-lg border bg-muted/30 p-3 space-y-1">
              <div className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                Pratinjau Notifikasi HP
              </div>
              <div className="text-sm font-semibold">
                {notifyForm.data.notification_title || 'Judul Notifikasi'}
              </div>
              <div className="text-xs text-muted-foreground whitespace-pre-line">
                {notifyForm.data.notification_body || 'Isi pesan notifikasi akan tampil di sini.'}
              </div>
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setNotifyTargetRelease(null)}
              >
                Batal
              </Button>
              <Button type="submit" disabled={notifyForm.processing}>
                <Download className="h-4 w-4 mr-1.5" />
                {notifyForm.processing ? 'Mengirim...' : 'Kirim Notifikasi Sekarang'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}

interface ActiveChannelSummaryCardProps {
  label: string;
  adminRelease?: AppReleaseItem;
  userRelease?: AppReleaseItem;
  onNotify: (release: AppReleaseItem) => void;
}

interface AudienceTrackSummaryRowProps {
  audienceLabel: string;
  emptyLabel: string;
  release?: AppReleaseItem;
  onNotify: (release: AppReleaseItem) => void;
}

function AudienceTrackSummaryRow({
  audienceLabel,
  emptyLabel,
  release,
  onNotify,
}: AudienceTrackSummaryRowProps) {
  if (!release) {
    return (
      <div className="rounded-md border border-dashed bg-muted/20 p-2.5 space-y-1">
        <div className="font-semibold text-foreground/80">{audienceLabel}</div>
        <p className="text-muted-foreground">{emptyLabel}</p>
      </div>
    );
  }

  const badge = formatAudienceBadge(normalizeTargetAudience(release.target_audience));

  return (
    <div className="rounded-md border bg-muted/20 p-2.5 space-y-1.5">
      <div className="flex flex-wrap items-center justify-between gap-1.5">
        <span className="font-semibold text-foreground">{audienceLabel}</span>
        <Badge variant="outline" className={badge.className}>
          {badge.label}
        </Badge>
      </div>
      <div className="text-sm font-bold">
        v{release.version_name}{' '}
        <span className="font-mono text-xs font-medium text-primary">
          (Build #{release.build_number})
        </span>
      </div>
      <div className="text-muted-foreground">
        Dipublikasikan: {formatDateTime(release.published_at)} • Ukuran:{' '}
        {formatFileSize(release.file_size_bytes)}
      </div>
      <div className="text-muted-foreground">
        Notifikasi terakhir:{' '}
        {release.last_notified_at
          ? `${formatDateTime(release.last_notified_at)} (${release.last_notified_count} target • ${formatTargetLabel(release.last_notified_target)})`
          : 'Belum pernah dikirim'}
      </div>
      <div className="pt-0.5">
        <Button type="button" size="sm" variant="outline" onClick={() => onNotify(release)}>
          <Bell className="h-3.5 w-3.5 mr-1" />
          Kirim Notif
        </Button>
      </div>
    </div>
  );
}

function ActiveChannelSummaryCard({
  label,
  adminRelease,
  userRelease,
  onNotify,
}: ActiveChannelSummaryCardProps) {
  const hasAnyActive = Boolean(adminRelease || userRelease);

  const unifiedRelease =
    adminRelease && userRelease && adminRelease.id === userRelease.id ? adminRelease : undefined;

  const unifiedBadge = unifiedRelease
    ? formatAudienceBadge(normalizeTargetAudience(unifiedRelease.target_audience))
    : null;

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center justify-between text-sm font-semibold">
          <span className="flex items-center gap-2">
            <Smartphone className="h-4 w-4 text-primary" />
            Rilis Aktif — {label}
          </span>
          {hasAnyActive ? (
            <Badge className="bg-emerald-600 text-white gap-1">
              <Power className="h-3 w-3" />
              {adminRelease && userRelease ? 'Aktif' : 'Aktif Sebagian'}
            </Badge>
          ) : (
            <Badge variant="outline">Belum Ada</Badge>
          )}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-2 text-xs">
        {!hasAnyActive ? (
          <p className="text-muted-foreground">
            Belum ada rilis aktif untuk {label}. Aplikasi mobile tidak akan menampilkan popup
            pembaruan.
          </p>
        ) : unifiedRelease && unifiedBadge ? (
          <>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="text-base font-bold">
                v{unifiedRelease.version_name}{' '}
                <span className="font-mono text-xs font-medium text-primary">
                  (Build #{unifiedRelease.build_number})
                </span>
              </span>
              <div className="flex flex-wrap items-center gap-1.5">
                <Badge variant="outline" className={unifiedBadge.className}>
                  {unifiedBadge.label}
                </Badge>
                <Badge variant="secondary">Pemberitahuan Opsional</Badge>
              </div>
            </div>
            <div className="text-muted-foreground">
              Dipublikasikan: {formatDateTime(unifiedRelease.published_at)} • Ukuran:{' '}
              {formatFileSize(unifiedRelease.file_size_bytes)}
            </div>
            <div className="text-muted-foreground">
              Notifikasi terakhir:{' '}
              {unifiedRelease.last_notified_at
                ? `${formatDateTime(unifiedRelease.last_notified_at)} (${unifiedRelease.last_notified_count} target • ${formatTargetLabel(unifiedRelease.last_notified_target)})`
                : 'Belum pernah dikirim'}
            </div>
            <div className="pt-1">
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => onNotify(unifiedRelease)}
              >
                <Bell className="h-3.5 w-3.5 mr-1" />
                Kirim Notif Update
              </Button>
            </div>
          </>
        ) : (
          <div className="grid grid-cols-1 gap-2">
            <AudienceTrackSummaryRow
              audienceLabel="Admin / Operator"
              emptyLabel="Tidak ada rilis aktif untuk Admin / Operator."
              release={adminRelease}
              onNotify={onNotify}
            />
            <AudienceTrackSummaryRow
              audienceLabel="Pengaju (User)"
              emptyLabel="Tidak ada rilis aktif untuk Pengaju (User)."
              release={userRelease}
              onNotify={onNotify}
            />
          </div>
        )}
      </CardContent>
    </Card>
  );
}
