import '../../../../domain/models/ticket_model.dart';

const _categories = [
  'IT Support',
  'General Affair',
  'HR',
  'Keuangan',
  'Operasional',
];

const _requesters = [
  'Budi',
  'Siti',
  'Ahmad',
  'Rina',
  'Dewi',
  'Joko',
  'Maria',
];

const _titles = [
  'AC Ruang Meeting Rusak',
  'Request Akses VPN',
  'Pengajuan Laptop Baru',
  'Install Software Design',
  'Lampu Ruangan Mati',
  'Printer Paper Jam',
  'Reset Password Akun HRIS',
  'Perbaikan Meja Kerja',
  'Gangguan Jaringan WiFi',
  'Penggantian Keyboard Laptop',
  'Request Akses Folder Bersama',
  'Perbaikan Sound System',
  'Cek Kulkas Pantry',
  'Instalasi CCTV',
  'Bantuan Persiapan Zoom Meeting',
];

final _statuses = [
  TicketStatus.open,
  TicketStatus.processing,
  TicketStatus.processing,
  TicketStatus.solved,
  TicketStatus.rejected,
];

List<TicketModel> generateMockTickets({required int count, int startHourOffset = 0}) {
  final now = DateTime.now();
  final tickets = <TicketModel>[];

  for (var i = 0; i < count; i++) {
    final title = _titles[i % _titles.length];
    final created = now.subtract(
      Duration(days: startHourOffset + i ~/ 2, hours: (i * 3) % 24),
    );
    tickets.add(
      TicketModel(
        id: 'TKT-GEN-${(i + 1).toString().padLeft(4, '0')}',
        title: title,
        description:
            'Permintaan bantuan terkait ${title.toLowerCase()}. Mohon ditindaklanjuti, terima kasih.',
        category: _categories[i % _categories.length],
        status: _statuses[i % _statuses.length],
        createdAt: created,
        requesterName: _requesters[i % _requesters.length],
        assignedTo: i % 3 == 0 ? 'Admin IT' : 'Admin GA',
      ),
    );
  }

  return tickets;
}
