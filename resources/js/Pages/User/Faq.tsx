import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import { Input } from '@/Components/ui/input';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import {
  Search,
  HelpCircle,
  ChevronDown,
  MessageSquare,
  PlusCircle,
} from 'lucide-react';

interface FaqItem {
  id: number;
  category: string;
  question: string;
  answer: string;
}

const CATEGORIES = [
  'Semua',
  'Akun & Umum',
  'Pengajuan Tiket',
  'SLA & Jam Kerja',
  'Peminjaman Aset',
  'Penilaian CSAT',
] as const;

const FAQ_ITEMS: FaqItem[] = [
  {
    id: 1,
    category: 'SLA & Jam Kerja',
    question: 'Berapa jam operasional layanan HaloAPU dan bagaimana perhitungan SLA?',
    answer:
      'Layanan HaloAPU beroperasi setiap hari kerja Senin hingga Jumat pukul 08:00 - 16:00 WIB. Perhitungan Service Level Agreement (SLA) hanya berjalan selama jam kerja tersebut. Timer SLA akan dijeda (paused) otomatis apabila status tiket beralih ke "Pending" (misalnya saat menunggu konfirmasi atau kelengkapan data dari pemohon).',
  },
  {
    id: 2,
    category: 'Pengajuan Tiket',
    question: 'Bagaimana alur penyelesaian tiket layanan dari awal hingga selesai?',
    answer:
      'Alur tiket mencakup 4 tahapan utama: 1) Open (tiket baru diajukan dan masuk antrean), 2) On Process / Sedang Diproses (petugas atau teknisi sedang mengerjakan permohonan), 3) Solve / Selesai (pekerjaan rampung dan hasil pengerjaan diunggah), serta 4) CSAT Rating & Closed (pemohon menilai kepuasan layanan dan tiket ditutup secara permanen).',
  },
  {
    id: 3,
    category: 'Pengajuan Tiket',
    question: 'Bagaimana cara mengajukan permintaan revisi jika hasil pekerjaan belum sesuai?',
    answer:
      'Jika status tiket telah ditandai Selesai (Solve) namun hasil pengerjaan fisik atau perbaikan sistem belum memuaskan, buka detail tiket pada menu "Riwayat Tiket" lalu klik tombol "Minta Revisi". Berikan rincian kendala yang masih terjadi agar tim teknisi segera menindaklanjuti kembali.',
  },
  {
    id: 4,
    category: 'Peminjaman Aset',
    question: 'Bagaimana prosedur peminjaman ruangan atau kendaraan dinas?',
    answer:
      'Pengajuan peminjaman dilakukan melalui menu "Ajukan Tiket" dengan memilih kategori Peminjaman Ruangan atau Peminjaman Kendaraan. Kamu wajib mengisi estimasi waktu mulai, waktu selesai, agenda kegiatan, serta kapasitas atau fasilitas pendukung yang dibutuhkan untuk diverifikasi pengelola sarana.',
  },
  {
    id: 5,
    category: 'Peminjaman Aset',
    question: 'Di mana saya bisa memantau jadwal ketersediaan ruangan dan kendaraan secara langsung?',
    answer:
      'Kamu dapat memantau ketersediaan aset secara real-time melalui halaman Live Monitor (/monitor). Menu ini menyajikan kalender jadwal penggunaan ruangan, ketersediaan unit kendaraan operasional, serta ketersediaan alat untuk mencegah bentrok jadwal penggunaan.',
  },
  {
    id: 6,
    category: 'Akun & Umum',
    question: 'Bagaimana cara melakukan reset password jika saya lupa kata sandi?',
    answer:
      'Pada halaman login HaloAPU, klik tautan "Lupa Password". Masukkan alamat email kedinasan terdaftar kamu untuk menerima tautan pembaruan kata sandi. Buka tautan di kotak masuk email kamu dan masukkan password baru yang aman.',
  },
  {
    id: 7,
    category: 'Akun & Umum',
    question: 'Mengapa akun saya terkena lock device / kunci perangkat dan bagaimana membukanya?',
    answer:
      'Sistem keamanan HaloAPU menerapkan penguncian sesi perangkat apabila terdeteksi login multi-device mencurigakan atau terjadi kegagalan input password secara berulang. Tunggu beberapa saat sesuai durasi penguncian atau hubungi operator via Live Chat untuk verifikasi identitas dan pelepasan kunci sesi.',
  },
  {
    id: 8,
    category: 'Penilaian CSAT',
    question: 'Kapan penilaian kepuasan layanan (CSAT) harus diisi dan apa kegunaannya?',
    answer:
      'Formulir penilaian CSAT (Customer Satisfaction) akan otomatis tersedia segera setelah tiket kamu berstatus Selesai (Solve). Penilaian bintang 1-5 dan saran kamu menjadi tolok ukur utama peningkatan kualitas dan evaluasi performa pelayanan HaloAPU.',
  },
  {
    id: 9,
    category: 'Pengajuan Tiket',
    question: 'Apakah tiket yang telah diajukan dapat dibatalkan oleh pengguna?',
    answer:
      'Pengguna dapat membatalkan tiket secara mandiri selama tiket masih berstatus "Open" (belum diambil atau diproses oleh teknisi). Buka rincian tiket di menu "Riwayat Tiket", lalu klik opsi "Batalkan Tiket" disertai alasan pembatalan.',
  },
];

export default function Faq() {
  const [activeCategory, setActiveCategory] = useState<string>('Semua');
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [openItems, setOpenItems] = useState<number[]>([]);

  const toggleAccordion = (id: number) => {
    setOpenItems((prev) =>
      prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]
    );
  };

  const filteredFaqs = FAQ_ITEMS.filter((item) => {
    const matchesCategory =
      activeCategory === 'Semua' || item.category === activeCategory;
    const term = searchTerm.trim().toLowerCase();
    const matchesSearch =
      !term ||
      item.question.toLowerCase().includes(term) ||
      item.answer.toLowerCase().includes(term);
    return matchesCategory && matchesSearch;
  });

  return (
    <UserLayout title="Pusat Bantuan & FAQ">
      <div className="mx-auto max-w-4xl space-y-8">
        {/* Header Section */}
        <div className="space-y-4 text-center sm:text-left">
          <div className="flex flex-col sm:flex-row sm:items-center gap-3">
            <div className="mx-auto sm:mx-0 flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary">
              <HelpCircle className="h-6 w-6" />
            </div>
            <div>
              <h1 className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
                Pusat Bantuan & FAQ
              </h1>
              <p className="text-sm text-muted-foreground sm:text-base">
                Temukan jawaban seputar layanan HaloAPU, SLA, alur tiket, dan peminjaman aset.
              </p>
            </div>
          </div>

          {/* Search Bar */}
          <div className="relative pt-2">
            <Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              type="text"
              placeholder="Cari pertanyaan atau kata kunci bantuan (contoh: SLA, revisi, peminjaman)..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="pl-10 h-11 bg-white"
            />
          </div>
        </div>

        {/* Category Chips */}
        <div className="flex flex-wrap gap-2">
          {CATEGORIES.map((category) => {
            const isActive = activeCategory === category;
            return (
              <button
                key={category}
                type="button"
                onClick={() => setActiveCategory(category)}
                className={`rounded-full px-4 py-1.5 text-xs sm:text-sm font-medium transition-colors ${
                  isActive
                    ? 'bg-primary text-primary-foreground shadow-xs'
                    : 'bg-white text-muted-foreground hover:bg-muted hover:text-foreground border border-border/80'
                }`}
              >
                {category}
              </button>
            );
          })}
        </div>

        {/* FAQ Accordion List */}
        <div className="space-y-3">
          {filteredFaqs.length > 0 ? (
            filteredFaqs.map((faq) => {
              const isOpen = openItems.includes(faq.id);
              return (
                <div
                  key={faq.id}
                  className="rounded-xl border bg-white shadow-xs transition-colors"
                >
                  <button
                    type="button"
                    onClick={() => toggleAccordion(faq.id)}
                    className="flex w-full items-start justify-between gap-4 p-4 sm:p-5 text-left font-medium text-foreground hover:bg-muted/30 rounded-xl transition-colors"
                  >
                    <div className="space-y-1 pr-2">
                      <span className="inline-block text-[11px] font-semibold text-primary uppercase tracking-wider">
                        {faq.category}
                      </span>
                      <h2 className="text-sm sm:text-base font-semibold text-foreground">
                        {faq.question}
                      </h2>
                    </div>
                    <ChevronDown
                      className={`h-5 w-5 shrink-0 text-muted-foreground transition-transform duration-200 mt-1 ${
                        isOpen ? 'rotate-180' : ''
                      }`}
                    />
                  </button>
                  {isOpen && (
                    <div className="border-t px-4 py-4 sm:px-5 sm:py-4 text-sm leading-relaxed text-muted-foreground bg-zinc-50/50 rounded-b-xl">
                      {faq.answer}
                    </div>
                  )}
                </div>
              );
            })
          ) : (
            <div className="rounded-xl border border-dashed p-8 text-center text-muted-foreground bg-white">
              <p className="text-sm">
                Tidak ada pertanyaan yang cocok dengan pencarian atau filter yang dipilih.
              </p>
              <Button
                variant="outline"
                size="sm"
                className="mt-3 text-xs"
                onClick={() => {
                  setSearchTerm('');
                  setActiveCategory('Semua');
                }}
              >
                Reset Filter
              </Button>
            </div>
          )}
        </div>

        {/* Bottom CTA Card */}
        <Card className="border-primary/20 bg-gradient-to-br from-primary/5 via-primary/[0.02] to-transparent shadow-xs">
          <CardContent className="flex flex-col sm:flex-row items-center justify-between gap-4 p-6 sm:p-8">
            <div className="space-y-1 text-center sm:text-left">
              <h2 className="text-lg font-bold text-foreground">
                Masih butuh bantuan?
              </h2>
              <p className="text-sm text-muted-foreground">
                Ajukan Tiket Baru atau Chat Operator Langsung untuk bantuan teknis segera.
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-2.5">
              <Link href="/tiket/buat">
                <Button className="gap-2">
                  <PlusCircle className="h-4 w-4" />
                  Ajukan Tiket
                </Button>
              </Link>
              <Link href="/chat">
                <Button variant="outline" className="gap-2 bg-white">
                  <MessageSquare className="h-4 w-4" />
                  Chat Operator
                </Button>
              </Link>
            </div>
          </CardContent>
        </Card>
      </div>
    </UserLayout>
  );
}
