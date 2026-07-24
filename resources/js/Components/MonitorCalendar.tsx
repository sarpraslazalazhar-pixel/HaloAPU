import React, { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { CalendarDays, Car, DoorOpen } from 'lucide-react';

interface BookingItem {
 nama_aset: string;
 tipe: string;
 jam_mulai: string;
 jam_selesai: string;
 user: string;
 status: string;
}

interface CalendarDay {
 date: string;
 tanggal: string;
 bookings: BookingItem[];
}

interface MonitorCalendarProps {
 calendarData: CalendarDay[];
}

export default function MonitorCalendar({ calendarData = [] }: MonitorCalendarProps) {
 const [selectedDate, setSelectedDate] = useState(
 calendarData.length > 0 ? calendarData[0].date : ''
 );

 const activeDay = calendarData.find(d => d.date === selectedDate);

 return (
 <div className="space-y-4">
 <div className="flex items-center gap-3">
 <CalendarDays className="h-5 w-5 text-muted-foreground" />
 <Input
 type="date"
 value={selectedDate}
 onChange={e => setSelectedDate(e.target.value)}
 className="w-auto"
 />
 {activeDay && (
 <span className="text-sm text-muted-foreground">
 {activeDay.bookings.length} booking
 </span>
 )}
 </div>

 {calendarData.length > 0 && (
 <div className="flex flex-wrap gap-2">
 {calendarData.map(day => (
 <button
 key={day.date}
 onClick={() => setSelectedDate(day.date)}
 className={`px-3 py-1.5 rounded-lg text-sm border transition-colors ${
 day.date === selectedDate
 ? 'bg-primary text-primary-foreground border-primary'
 : 'bg-card hover:bg-accent border-border'
 }`}
 >
 <div className="font-medium">{day.tanggal}</div>
 <div className="text-xs opacity-70">{day.bookings.length} booking</div>
 </button>
 ))}
 </div>
 )}

 {activeDay && activeDay.bookings.length > 0 ? (
 <Card>
 <CardHeader>
 <CardTitle className="text-base">
 Booking — {activeDay.tanggal}
 </CardTitle>
 </CardHeader>
 <CardContent>
 <div className="overflow-x-auto">
 <table className="w-full text-left">
 <thead>
 <tr className="border-b text-sm text-muted-foreground">
 <th className="py-2 pr-4">Aset</th>
 <th className="py-2 pr-4">Jam</th>
 <th className="py-2 pr-4">Peminjam</th>
 <th className="py-2">Status</th>
 </tr>
 </thead>
 <tbody>
 {activeDay.bookings.map((b, i) => (
 <tr key={i} className="border-b last:border-0">
 <td className="py-2 pr-4">
 <div className="flex items-center gap-2">
 {b.tipe === 'kendaraan'
 ? <Car className="h-4 w-4 text-muted-foreground shrink-0" />
 : <DoorOpen className="h-4 w-4 text-muted-foreground shrink-0" />
 }
 <span className="font-medium">{b.nama_aset}</span>
 </div>
 </td>
 <td className="py-2 pr-4 text-sm">
 {b.jam_mulai} — {b.jam_selesai}
 </td>
 <td className="py-2 pr-4 text-sm">{b.user}</td>
 <td className="py-2">
 <Badge variant={b.status === 'open' || b.status === 'Disetujui' ? 'default' : 'secondary'}>
 {b.status}
 </Badge>
 </td>
 </tr>
 ))}
 </tbody>
 </table>
 </div>
 </CardContent>
 </Card>
 ) : selectedDate ? (
 <div className="text-center py-8 text-muted-foreground border rounded-lg">
 Tidak ada booking di tanggal ini.
 </div>
 ) : (
 <div className="text-center py-8 text-muted-foreground border rounded-lg">
 Pilih tanggal untuk melihat booking.
 </div>
 )}
 </div>
 );
}