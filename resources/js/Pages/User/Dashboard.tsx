import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { FolderOpen, Clock, CheckCircle2, XCircle, PlusCircle, History, Star, Monitor, ArrowRight, Ticket } from 'lucide-react';
import { motion } from 'framer-motion';

const containerVariants = {
  hidden: { opacity: 0 },
  visible: {
    opacity: 1,
    transition: {
      staggerChildren: 0.08,
    },
  },
};

const itemVariants = {
  hidden: { opacity: 0, y: 15 },
  visible: {
    opacity: 1,
    y: 0,
    transition: { type: 'spring', stiffness: 350, damping: 25 },
  },
};

export default function Dashboard({ recentTickets = [], stats }: { recentTickets?: any[], stats?: any }) {
  const { auth } = usePage<any>().props;
  const user = auth?.user;

  const STATUS_CARDS = [
    { label: 'Tiket Aktif', icon: FolderOpen, count: stats?.aktif || 0, bg: 'from-blue-500 to-blue-600', anim: 'group-hover:-translate-y-2 group-hover:rotate-12 group-hover:opacity-100' },
    { label: 'Sedang Diproses', icon: Clock, count: stats?.diproses || 0, bg: 'from-orange-500 to-orange-600', anim: 'group-hover:-rotate-12 group-hover:scale-110 group-hover:opacity-100' },
    { label: 'Selesai', icon: CheckCircle2, count: stats?.selesai || 0, bg: 'from-green-500 to-green-600', anim: 'group-hover:scale-125 group-hover:opacity-100' },
    { label: 'Ditolak', icon: XCircle, count: stats?.ditolak || 0, bg: 'from-red-500 to-red-600', anim: 'group-hover:rotate-90 group-hover:scale-110 group-hover:opacity-100' },
  ];

  const QUICK_ACTIONS = [
    { label: 'Ajukan Tiket', desc: 'Buat permohonan layanan baru', icon: PlusCircle, href: '/tiket/buat', color: 'text-primary bg-primary/10' },
    { label: 'Riwayat Tiket', desc: 'Lihat semua pengajuan Kamu', icon: History, href: '/tiket/riwayat', color: 'text-emerald-600 bg-emerald-50' },
    { label: 'Riwayat Penilaian', desc: 'Riwayat penilaian kepuasan', icon: Star, href: '/csat/riwayat', color: 'text-amber-600 bg-amber-50' },
    { label: 'Monitor', desc: 'Pantau status ruangan & kendaraan', icon: Monitor, href: '/monitor', color: 'text-purple-600 bg-purple-50' },
  ];

  const timeGreeting = () => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Selamat pagi';
    if (hour < 15) return 'Selamat siang';
    if (hour < 18) return 'Selamat sore';
    return 'Selamat malam';
  };

  return (
    <UserLayout title="Dashboard">
      <motion.div
        variants={containerVariants}
        initial="hidden"
        animate="visible"
        className="space-y-8"
      >
        <motion.div variants={itemVariants} className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold tracking-tight">
            {timeGreeting()}, <span className="text-primary">{user?.username || 'User'}</span>
          </h1>
          <p className="text-sm text-muted-foreground">
            Selamat datang di sistem layanan terpadu Halo APU.
          </p>
        </motion.div>

        {/* Status Cards */}
        <motion.div variants={itemVariants} className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {STATUS_CARDS.map((card) => {
            const Icon = card.icon;
            return (
              <motion.div
                key={card.label}
                whileHover={{ y: -3, scale: 1.02 }}
                whileTap={{ scale: 0.98 }}
                transition={{ type: 'spring', stiffness: 400, damping: 25 }}
                className={`group relative overflow-hidden rounded-xl bg-gradient-to-br ${card.bg} shadow-md p-5 text-white cursor-pointer`}
              >
                <div className="absolute -top-4 -right-4 h-20 w-20 rounded-full bg-white/10 blur-xl pointer-events-none transition-transform duration-500 group-hover:scale-150" />
                <div className="absolute -bottom-4 -left-4 h-16 w-16 rounded-full bg-white/5 blur-lg pointer-events-none" />
                <div className="relative flex items-start justify-between">
                  <div className="space-y-1">
                    <p className="text-sm font-medium text-white/80">{card.label}</p>
                    <p className="text-3xl font-bold">{card.count}</p>
                  </div>
                  <Icon className={`h-8 w-8 text-white opacity-70 transition-all duration-300 ${card.anim}`} />
                </div>
              </motion.div>
            );
          })}
        </motion.div>

        {/* Quick Actions */}
        <motion.div variants={itemVariants} className="space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold">Aksi Cepat</h2>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {QUICK_ACTIONS.map((action) => {
              const Icon = action.icon;
              return (
                <Link key={action.label} href={action.href}>
                  <motion.div
                    whileHover={{ y: -3, scale: 1.02 }}
                    whileTap={{ scale: 0.97 }}
                    transition={{ type: 'spring', stiffness: 400, damping: 25 }}
                  >
                    <Card className="group cursor-pointer border-border/50 transition-shadow duration-200 hover:shadow-md">
                      <CardContent className="p-5">
                        <div className="flex items-start gap-4">
                          <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${action.color}`}>
                            <Icon className="h-5 w-5" />
                          </div>
                          <div className="flex-1 min-w-0">
                            <p className="text-sm font-medium text-foreground">{action.label}</p>
                            <p className="text-xs text-muted-foreground mt-0.5">{action.desc}</p>
                          </div>
                          <ArrowRight className="h-4 w-4 text-muted-foreground/40 transition-all duration-200 group-hover:text-primary group-hover:translate-x-0.5 mt-1" />
                        </div>
                      </CardContent>
                    </Card>
                  </motion.div>
                </Link>
              );
            })}
          </div>
        </motion.div>

        {/* Recent Tickets */}
        {recentTickets && recentTickets.length > 0 ? (
          <motion.div variants={itemVariants} className="space-y-4">
            <div className="flex items-center justify-between">
              <h2 className="text-lg font-semibold">Tiket Terbaru Kamu</h2>
              <Link href="/tiket/riwayat" className="text-sm font-medium text-primary hover:underline">Lihat Semua</Link>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              {recentTickets.map((tiket) => (
                <Link key={tiket.id} href={`/tiket/${tiket.id}`}>
                  <motion.div
                    whileHover={{ y: -2, scale: 1.01 }}
                    whileTap={{ scale: 0.98 }}
                    transition={{ type: 'spring', stiffness: 400, damping: 25 }}
                  >
                    <Card className="hover:shadow-md transition-shadow">
                      <CardContent className="p-4">
                        <div className="flex justify-between items-start mb-2">
                          <span className="text-xs font-semibold px-2 py-1 bg-primary/10 text-primary rounded-md">{tiket.ticket_number}</span>
                          <span className="text-xs text-muted-foreground">{new Date(tiket.created_at).toLocaleDateString('id-ID')}</span>
                        </div>
                        <h3 className="font-medium text-sm line-clamp-2">{tiket.sub_unit?.nama_layanan || 'Layanan Umum'}</h3>
                        <div className="mt-4 flex items-center justify-between">
                          <span className="text-xs font-medium text-muted-foreground">{tiket.status}</span>
                          <ArrowRight className="w-4 h-4 text-muted-foreground" />
                        </div>
                      </CardContent>
                    </Card>
                  </motion.div>
                </Link>
              ))}
            </div>
          </motion.div>
        ) : (
          <motion.div variants={itemVariants}>
            <Card className="border-dashed border-border/50">
              <CardContent className="flex flex-col items-center justify-center py-12 gap-4">
                <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-muted">
                  <Ticket className="h-8 w-8 text-muted-foreground/60" />
                </div>
                <div className="text-center max-w-sm">
                  <h3 className="text-base font-semibold text-foreground">Belum ada tiket</h3>
                  <p className="text-sm text-muted-foreground mt-1">
                    Ajukan tiket pertama Kamu untuk memulai layanan.
                  </p>
                </div>
                <Link href="/tiket/buat">
                  <motion.div whileTap={{ scale: 0.95 }}>
                    <Button className="gap-2">
                      <PlusCircle className="h-4 w-4" />
                      Buat Tiket Baru
                    </Button>
                  </motion.div>
                </Link>
              </CardContent>
            </Card>
          </motion.div>
        )}
      </motion.div>
    </UserLayout>
  );
}
