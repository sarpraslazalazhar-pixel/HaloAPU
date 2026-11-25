import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Button } from '@/Components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import {
  AlertTriangle,
  CalendarDays,
  CheckCircle2,
  Clock,
  ExternalLink,
  PackageCheck,
  Paperclip,
  User,
  Wrench,
} from 'lucide-react';
import {
  HandoverEquipmentModal,
  ReturnEquipmentModal,
  resolveConditionMeta,
  type EquipmentLoanModalTarget,
} from '@/Components/EquipmentLoanModals';

export interface AlatPinjamItem {
  ticket_id: number;
  formatted_id: string;
  ticket_url: string | null;
  peminjam: string;
  layanan: string;
  alat: string;
  waktu: string | null;
  status_tiket: string;
  status_pengembalian:
    | 'Sedang Dipinjam'
    | 'Menunggu Diserahkan'
    | 'Menunggu Persetujuan'
    | 'Belum Dikembalikan'
    | 'Dikembalikan';
  diserahkan_at?: string | null;
  diserahkan_oleh?: string | null;
  penerima_alat_user_id?: number | null;
  penerima_alat?: string | null;
  kondisi_serah?: string | null;
  catatan_serah?: string | null;
  dikembalikan_at: string | null;
  diterima_kembali_oleh?: string | null;
  kondisi_kembali?: string | null;
  catatan_kembali?: string | null;
  lampiran_serah_count?: number;
  lampiran_kembali_count?: number;
  is_overdue_schedule?: boolean;
  can_serahkan?: boolean;
  can_kembalikan?: boolean;
}

export interface KetersediaanAlatItem {
  nama_alat: string;
  status:
    | 'Tersedia'
    | 'Sedang Dipinjam'
    | 'Menunggu Diserahkan'
    | 'Menunggu Persetujuan'
    | 'Belum Dikembalikan';
  user: string | null;
  waktu: string | null;
  ticket_id: number | null;
  formatted_id: string | null;
  ticket_url: string | null;
}

interface MonitorAlatProps {
  items: AlatPinjamItem[];
  ketersediaan?: KetersediaanAlatItem[];
  canQuickAction?: boolean;
}

function toModalTarget(item: AlatPinjamItem): EquipmentLoanModalTarget {
  return {
    id: item.ticket_id,
    formatted_id: item.formatted_id,
    borrowerName: item.peminjam,
    equipmentText: item.alat,
    scheduleText: item.waktu,
    hasHandedOver: Boolean(item.diserahkan_at),
  };
}

export default function MonitorAlat({
  items = [],
  ketersediaan = [],
  canQuickAction = false,
}: MonitorAlatProps) {
  const [search, setSearch] = useState('');
  const [filterStatus, setFilterStatus] = useState<string>('all');
  const [filterKatalog, setFilterKatalog] = useState<string>('all');
  const [handoverTarget, setHandoverTarget] = useState<EquipmentLoanModalTarget | null>(null);
  const [returnTarget, setReturnTarget] = useState<EquipmentLoanModalTarget | null>(null);

  // Filter untuk tabel tiket
  const filteredTickets = items.filter((item) => {
    const matchSearch =
      item.peminjam.toLowerCase().includes(search.toLowerCase()) ||
      item.alat.toLowerCase().includes(search.toLowerCase()) ||
      item.formatted_id.toLowerCase().includes(search.toLowerCase());

    const matchStatus = filterStatus === 'all' || item.status_pengembalian === filterStatus;

    return matchSearch && matchStatus;
  });

  // Filter untuk kartu ketersediaan alat
  const filteredKatalog = ketersediaan.filter((tool) => {
    if (filterKatalog === 'all') return true;

    if (filterKatalog === 'tersedia') return tool.status === 'Tersedia';

    if (filterKatalog === 'dipinjam') return tool.status !== 'Tersedia';

    return true;
  });

  const totalAlat = ketersediaan.length;
  const totalTersedia = ketersediaan.filter((t) => t.status === 'Tersedia').length;
  const totalDipinjam = totalAlat - totalTersedia;

  return (
    <div className="mt-8 space-y-6">
      {/* SECTION 1: KATALOG KETERSEDIAAN ALAT */}
      {ketersediaan.length > 0 && (
        <Card className="border border-slate-200 shadow-sm">
          <CardHeader className="border-b border-slate-100 bg-slate-50/50 pb-4">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-lg bg-teal-50 text-teal-700 border border-teal-200">
                  <PackageCheck className="w-5 h-5" />
                </div>
                <div>
                  <CardTitle className="text-lg font-bold text-slate-800">
                    Status Ketersediaan Alat
                  </CardTitle>
                  <p className="text-xs text-slate-500 mt-0.5">
                    Cek langsung peralatan apa saja yang siap dipinjam atau sedang digunakan
                  </p>
                </div>
              </div>

              {/* Filter Tabs */}
              <div className="flex items-center gap-1.5 bg-slate-100 p-1 rounded-lg text-xs font-medium">
                <button
                  type="button"
                  onClick={() => setFilterKatalog('all')}
                  className={`px-3 py-1 rounded-md transition-all ${
                    filterKatalog === 'all'
                      ? 'bg-white text-slate-800 shadow-xs font-semibold'
                      : 'text-slate-600 hover:text-slate-900'
                  }`}
                >
                  Semua ({totalAlat})
                </button>
                <button
                  type="button"
                  onClick={() => setFilterKatalog('tersedia')}
                  className={`px-3 py-1 rounded-md transition-all ${
                    filterKatalog === 'tersedia'
                      ? 'bg-emerald-600 text-white shadow-xs font-semibold'
                      : 'text-emerald-700 hover:text-emerald-800'
                  }`}
                >
                  Tersedia ({totalTersedia})
                </button>
                <button
                  type="button"
                  onClick={() => setFilterKatalog('dipinjam')}
                  className={`px-3 py-1 rounded-md transition-all ${
                    filterKatalog === 'dipinjam'
                      ? 'bg-blue-600 text-white shadow-xs font-semibold'
                      : 'text-blue-700 hover:text-blue-800'
                  }`}
                >
                  Diproses / Dipinjam ({totalDipinjam})
                </button>
              </div>
            </div>
          </CardHeader>

          <CardContent className="p-4 sm:p-6">
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3.5">
              {filteredKatalog.map((tool, idx) => {
                const isAvailable = tool.status === 'Tersedia';
                const detailUrl = tool.ticket_url;

                return (
                  <div
                    key={idx}
                    className={`p-3.5 rounded-xl border transition-all ${
                      isAvailable
                        ? 'bg-emerald-50/20 border-emerald-200/80 hover:border-emerald-300'
                        : tool.status === 'Sedang Dipinjam'
                        ? 'bg-sky-50/30 border-sky-200/80 hover:border-sky-300'
                        : tool.status === 'Belum Dikembalikan'
                        ? 'bg-rose-50/30 border-rose-200/80 hover:border-rose-300'
                        : tool.status === 'Menunggu Persetujuan'
                        ? 'bg-purple-50/30 border-purple-200/80 hover:border-purple-300'
                        : 'bg-amber-50/30 border-amber-200/80 hover:border-amber-300'
                    }`}
                  >
                    <div className="flex items-start justify-between gap-2 mb-2">
                      <span
                        className="font-semibold text-sm text-slate-800 line-clamp-1"
                        title={tool.nama_alat}
                      >
                        {tool.nama_alat}
                      </span>
                      {isAvailable ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-800 border border-emerald-200 shrink-0">
                          <CheckCircle2 className="w-3 h-3" /> Tersedia
                        </span>
                      ) : tool.status === 'Sedang Dipinjam' ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-sky-100 text-sky-800 border border-sky-200 shrink-0">
                          <Clock className="w-3 h-3" /> Dipinjam
                        </span>
                      ) : tool.status === 'Belum Dikembalikan' ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-rose-100 text-rose-800 border border-rose-200 shrink-0">
                          <AlertTriangle className="w-3 h-3" /> Lewat Batas
                        </span>
                      ) : tool.status === 'Menunggu Persetujuan' ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-purple-100 text-purple-800 border border-purple-200 shrink-0">
                          <Clock className="w-3 h-3" /> Menunggu
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-100 text-amber-800 border border-amber-200 shrink-0">
                          <CalendarDays className="w-3 h-3" /> Menunggu Dikasih
                        </span>
                      )}
                    </div>

                    {isAvailable ? (
                      <p className="text-xs text-emerald-600 font-medium flex items-center gap-1 mt-1">
                        Tersedia
                      </p>
                    ) : (
                      <div className="space-y-1 text-xs text-slate-600 mt-2 border-t pt-2 border-slate-100">
                        <div className="flex items-center gap-1 text-slate-700 font-medium">
                          <User className="w-3 h-3 text-slate-400 shrink-0" />
                          <span className="truncate">{tool.user}</span>
                        </div>
                        <div className="flex items-center gap-1 text-[11px] text-slate-500">
                          <Clock className="w-3 h-3 text-slate-400 shrink-0" />
                          <span className="truncate">{tool.waktu}</span>
                        </div>
                        {detailUrl && (
                          <div className="pt-1">
                            <Link
                              href={detailUrl}
                              className="text-[11px] text-blue-600 hover:underline flex items-center gap-1"
                            >
                              Tiket #{tool.formatted_id} <ExternalLink className="w-2.5 h-2.5" />
                            </Link>
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          </CardContent>
        </Card>
      )}

      {/* SECTION 2: TABEL DAFTAR TIKET PEMINJAMAN & PENGEMBALIAN */}
      <Card className="border border-slate-200 shadow-sm">
        <CardHeader className="border-b border-slate-100 bg-slate-50/50 pb-4">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div className="flex items-center gap-2.5">
              <div className="p-2 rounded-lg bg-teal-50 text-teal-700 border border-teal-200">
                <Wrench className="w-5 h-5" />
              </div>
              <div>
                <CardTitle className="text-lg font-bold text-slate-800">
                  Riwayat &amp; Pemantauan Peminjaman Alat
                </CardTitle>
                <p className="text-xs text-slate-500 mt-0.5">
                  Daftar tiket pengajuan peminjaman alat, serah-terima, dan tracking pengembalian
                </p>
              </div>
            </div>

            <div className="flex flex-wrap items-center gap-2">
              <Input
                type="text"
                placeholder="Cari peminjam, alat, atau ID..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-48 sm:w-64 h-9 text-xs"
              />
              <select
                value={filterStatus}
                onChange={(e) => setFilterStatus(e.target.value)}
                className="h-9 rounded-md border border-input bg-background px-3 py-1 text-xs shadow-xs focus:outline-none focus:ring-1 focus:ring-ring"
              >
                <option value="all">Semua Status</option>
                <option value="Menunggu Persetujuan">Menunggu Persetujuan</option>
                <option value="Menunggu Diserahkan">Menunggu Diserahkan</option>
                <option value="Sedang Dipinjam">Sedang Dipinjam</option>
                <option value="Belum Dikembalikan">Belum Dikembalikan</option>
                <option value="Dikembalikan">Sudah Dikembalikan</option>
              </select>
            </div>
          </div>
        </CardHeader>

        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader className="bg-slate-50/80">
                <TableRow>
                  <TableHead className="w-28 text-xs font-semibold">ID Tiket</TableHead>
                  <TableHead className="text-xs font-semibold">Peminjam</TableHead>
                  <TableHead className="text-xs font-semibold">Alat yang Dipinjam</TableHead>
                  <TableHead className="text-xs font-semibold">Waktu Pemakaian</TableHead>
                  <TableHead className="text-xs font-semibold">Status &amp; Keterangan</TableHead>
                  <TableHead className="text-xs font-semibold text-right">Aksi</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredTickets.length > 0 ? (
                  filteredTickets.map((item) => {
                    const detailUrl = item.ticket_url;

                    return (
                      <TableRow key={item.ticket_id} className="hover:bg-slate-50/50 align-top">
                        <TableCell className="font-mono text-xs font-semibold text-slate-700">
                          {detailUrl ? (
                            <Link href={detailUrl} className="text-blue-600 hover:underline">
                              #{item.formatted_id}
                            </Link>
                          ) : (
                            `#${item.formatted_id}`
                          )}
                        </TableCell>
                        <TableCell className="text-xs font-medium text-slate-800">
                          {item.peminjam}
                        </TableCell>
                        <TableCell className="text-xs text-slate-700 font-medium">
                          {item.alat}
                        </TableCell>
                        <TableCell className="text-xs text-slate-500">
                          <div>{item.waktu || '-'}</div>
                          {item.is_overdue_schedule && (
                            <span className="inline-block mt-1 text-[10px] font-semibold text-rose-700 bg-rose-50 border border-rose-200 px-1.5 py-0.5 rounded">
                              Jadwal lewat
                            </span>
                          )}
                        </TableCell>
                        <TableCell className="space-y-1.5">
                          <div>
                            {item.status_pengembalian === 'Dikembalikan' ? (
                              <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                <CheckCircle2 className="w-3 h-3" /> Dikembalikan
                              </span>
                            ) : item.status_pengembalian === 'Belum Dikembalikan' ? (
                              <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-100 text-rose-800 border border-rose-200">
                                <AlertTriangle className="w-3 h-3" /> Belum Dikembalikan
                              </span>
                            ) : item.status_pengembalian === 'Sedang Dipinjam' ? (
                              <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-sky-100 text-sky-800 border border-sky-200">
                                <Clock className="w-3 h-3" /> Sedang Dipinjam
                              </span>
                            ) : item.status_pengembalian === 'Menunggu Diserahkan' ? (
                              <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 border border-amber-200">
                                <CalendarDays className="w-3 h-3" /> Menunggu Diserahkan
                              </span>
                            ) : (
                              <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-purple-100 text-purple-800 border border-purple-200">
                                <Clock className="w-3 h-3" /> Menunggu Persetujuan
                              </span>
                            )}
                          </div>

                          {item.diserahkan_at && (
                            <div className="text-[11px] text-slate-600 space-y-0.5">
                              <div>
                                Dikasih: <span className="font-medium">{item.diserahkan_at}</span>
                                {item.diserahkan_oleh ? ` • ${item.diserahkan_oleh}` : ''}
                              </div>
                              {(item.kondisi_serah || (item.lampiran_serah_count ?? 0) > 0) && (
                                <div className="flex flex-wrap items-center gap-1.5">
                                  {item.kondisi_serah && (() => {
                                    const meta = resolveConditionMeta(item.kondisi_serah);

                                    return (
                                      <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ${meta.className}`}>
                                        {meta.label}
                                      </span>
                                    );
                                  })()}
                                  {(item.lampiran_serah_count ?? 0) > 0 && (
                                    <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 border border-slate-200 text-[10px] font-medium">
                                      <Paperclip className="w-2.5 h-2.5" />
                                      {item.lampiran_serah_count}
                                    </span>
                                  )}
                                </div>
                              )}
                              {item.penerima_alat && (
                                <div className="text-slate-500">Penerima: {item.penerima_alat}</div>
                              )}
                              {item.catatan_serah && (
                                <div className="text-slate-500 italic">&ldquo;{item.catatan_serah}&rdquo;</div>
                              )}
                            </div>
                          )}

                          {item.dikembalikan_at && (
                            <div className="text-[11px] text-slate-600 space-y-0.5 pt-0.5 border-t border-slate-100">
                              <div>
                                Kembali: <span className="font-medium">{item.dikembalikan_at}</span>
                                {item.diterima_kembali_oleh ? ` • ${item.diterima_kembali_oleh}` : ''}
                              </div>
                              {(item.kondisi_kembali || (item.lampiran_kembali_count ?? 0) > 0) && (
                                <div className="flex flex-wrap items-center gap-1.5">
                                  {item.kondisi_kembali && (() => {
                                    const meta = resolveConditionMeta(item.kondisi_kembali);

                                    return (
                                      <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ${meta.className}`}>
                                        {meta.label}
                                      </span>
                                    );
                                  })()}
                                  {(item.lampiran_kembali_count ?? 0) > 0 && (
                                    <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 border border-slate-200 text-[10px] font-medium">
                                      <Paperclip className="w-2.5 h-2.5" />
                                      {item.lampiran_kembali_count}
                                    </span>
                                  )}
                                </div>
                              )}
                              {item.catatan_kembali && (
                                <div className="text-slate-500 italic">&ldquo;{item.catatan_kembali}&rdquo;</div>
                              )}
                            </div>
                          )}
                        </TableCell>
                        <TableCell className="text-right">
                          <div className="flex flex-wrap items-center justify-end gap-1.5">
                            {canQuickAction && item.can_serahkan && (
                              <Button
                                type="button"
                                size="sm"
                                className="h-7 px-2.5 text-[11px] bg-sky-600 hover:bg-sky-700 text-white"
                                onClick={() => setHandoverTarget(toModalTarget(item))}
                              >
                                <PackageCheck className="w-3 h-3 mr-1" />
                                Serahkan
                              </Button>
                            )}
                            {canQuickAction && item.can_kembalikan && (
                              <Button
                                type="button"
                                size="sm"
                                variant={item.can_serahkan ? 'outline' : 'default'}
                                className={
                                  item.can_serahkan
                                    ? 'h-7 px-2.5 text-[11px] border-emerald-300 text-emerald-700 hover:bg-emerald-50'
                                    : 'h-7 px-2.5 text-[11px] bg-emerald-600 hover:bg-emerald-700 text-white'
                                }
                                onClick={() => setReturnTarget(toModalTarget(item))}
                              >
                                <CheckCircle2 className="w-3 h-3 mr-1" />
                                Kembalikan
                              </Button>
                            )}
                            {detailUrl && (
                              <Link
                                href={detailUrl}
                                className="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-700 hover:underline px-1.5 py-1"
                              >
                                Detail <ExternalLink className="w-3 h-3" />
                              </Link>
                            )}
                          </div>
                        </TableCell>
                      </TableRow>
                    );
                  })
                ) : (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center py-8 text-sm text-slate-500">
                      Tidak ada data peminjaman alat yang sesuai.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>

      {canQuickAction && (
        <>
          <HandoverEquipmentModal
            open={handoverTarget !== null}
            onOpenChange={(open) => {
              if (!open) setHandoverTarget(null);
            }}
            ticket={handoverTarget}
          />
          <ReturnEquipmentModal
            open={returnTarget !== null}
            onOpenChange={(open) => {
              if (!open) setReturnTarget(null);
            }}
            ticket={returnTarget}
          />
        </>
      )}
    </div>
  );
}
