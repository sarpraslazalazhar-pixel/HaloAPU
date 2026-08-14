import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../../core/theme/app_theme.dart';
import '../../../../domain/models/ticket_model.dart';

class TicketCard extends StatelessWidget {
  final TicketModel ticket;
  final VoidCallback onTap;

  const TicketCard({
    super.key,
    required this.ticket,
    required this.onTap,
  });

  (Color bg, Color text, IconData icon) _getStatusVisual(TicketStatus status) {
    switch (status) {
      case TicketStatus.open:
        return (
          const Color(0xFFE0F2FE),
          const Color(0xFF0284C7),
          Icons.schedule_rounded,
        );
      case TicketStatus.processing:
        return (
          const Color(0xFFFEF3C7),
          const Color(0xFFD97706),
          Icons.settings_suggest_rounded,
        );
      case TicketStatus.solved:
        return (
          const Color(0xFFDCFCE7),
          const Color(0xFF16A34A),
          Icons.check_circle_rounded,
        );
      case TicketStatus.rejected:
        return (
          const Color(0xFFFEE2E2),
          const Color(0xFFDC2626),
          Icons.cancel_rounded,
        );
      case TicketStatus.needRevision:
        return (
          const Color(0xFFF3E8FF),
          const Color(0xFF7C3AED),
          Icons.edit_note_rounded,
        );
      case TicketStatus.cancelled:
        return (
          const Color(0xFFF1F5F9),
          const Color(0xFF64748B),
          Icons.block_rounded,
        );
      case TicketStatus.pending:
        return (
          const Color(0xFFF1F5F9),
          const Color(0xFF64748B),
          Icons.pause_circle_outline_rounded,
        );
    }
  }

  (IconData icon, Color color) _getCategoryVisual(String category) {
    final lower = category.toLowerCase();
    if (lower.contains('kendaraan') || lower.contains('mobil') || lower.contains('motor')) {
      return (Icons.directions_car_rounded, const Color(0xFF0284C7));
    }
    if (lower.contains('it') || lower.contains('komputer') || lower.contains('jaringan') || lower.contains('laptop')) {
      return (Icons.devices_rounded, const Color(0xFF6366F1));
    }
    if (lower.contains('ruangan') || lower.contains('aula') || lower.contains('rapat')) {
      return (Icons.meeting_room_rounded, const Color(0xFF0D9488));
    }
    if (lower.contains('sarpras') || lower.contains('gedung') || lower.contains('ac') || lower.contains('listrik')) {
      return (Icons.build_rounded, const Color(0xFFD97706));
    }
    return (Icons.folder_open_rounded, const Color(0xFF00768C));
  }

  IconData _getFieldIcon(String key, String value) {
    final lowerKey = key.toLowerCase();
    final val = value.trim();

    // Deteksi tanggal (YYYY-MM-DD)
    if (RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(val) || lowerKey.contains('tgl') || lowerKey.contains('tanggal') || lowerKey.contains('date')) {
      return Icons.calendar_today_outlined;
    }
    // Deteksi jam / waktu (HH:MM)
    if (RegExp(r'^\d{1,2}:\d{2}').hasMatch(val) || lowerKey.contains('jam') || lowerKey.contains('waktu') || lowerKey.contains('time')) {
      return Icons.access_time_rounded;
    }
    // Deteksi lokasi
    if (lowerKey.contains('tujuan') || lowerKey.contains('lokasi') || lowerKey.contains('alamat') || lowerKey.contains('ruang') || lowerKey.contains('tempat')) {
      return Icons.location_on_outlined;
    }
    // Deteksi jumlah / penumpang / peserta
    if (lowerKey.contains('penumpang') || lowerKey.contains('orang') || lowerKey.contains('peserta') || lowerKey.contains('jumlah') || lowerKey.contains('unit')) {
      return Icons.people_outline_rounded;
    }
    // Deteksi keperluan / deskripsi / materi
    if (lowerKey.contains('keperluan') || lowerKey.contains('keterangan') || lowerKey.contains('alasan') || lowerKey.contains('materi') || lowerKey.contains('agenda')) {
      return Icons.assignment_outlined;
    }
    if (lowerKey.contains('driver') || lowerKey.contains('sopir')) {
      return Icons.person_outline_rounded;
    }
    return Icons.info_outline_rounded;
  }

  @override
  Widget build(BuildContext context) {
    final statusVisual = _getStatusVisual(ticket.status);
    final categoryVisual = _getCategoryVisual(ticket.category);
    final summaryItems = ticket.summaryItems;

    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(
          color: const Color(0xFFE2E8F0),
          width: 1.2,
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.03),
            blurRadius: 12,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(20),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(20),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Header: Kategori & Status Badge
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    // Kategori Pill
                    Flexible(
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                        decoration: BoxDecoration(
                          color: const Color(0xFFF8FAFC),
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: const Color(0xFFE2E8F0)),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(
                              categoryVisual.$1,
                              size: 14,
                              color: categoryVisual.$2,
                            ),
                            const SizedBox(width: 6),
                            Flexible(
                              child: Text(
                                ticket.category,
                                style: TextStyle(
                                  fontSize: 11.5,
                                  fontWeight: FontWeight.w600,
                                  color: Colors.blueGrey.shade800,
                                ),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    // Status Badge
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                      decoration: BoxDecoration(
                        color: statusVisual.$1,
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(
                          color: statusVisual.$2.withValues(alpha: 0.3),
                          width: 1,
                        ),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            statusVisual.$3,
                            size: 13,
                            color: statusVisual.$2,
                          ),
                          const SizedBox(width: 4),
                          Text(
                            ticket.statusIndonesianLabel,
                            style: TextStyle(
                              color: statusVisual.$2,
                              fontWeight: FontWeight.bold,
                              fontSize: 11.5,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),

                const SizedBox(height: 12),

                // Judul Tiket
                Text(
                  ticket.title,
                  style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 16,
                    color: Color(0xFF0F172A),
                    letterSpacing: -0.3,
                  ),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),

                const SizedBox(height: 10),

                // Ringkasan Form / Detail
                if (summaryItems.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 4),
                    child: Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: summaryItems.take(4).map((item) {
                        return Container(
                          padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
                          decoration: BoxDecoration(
                            color: const Color(0xFFF8FAFC),
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: const Color(0xFFE2E8F0)),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(
                                _getFieldIcon(item.key, item.value),
                                size: 12.5,
                                color: const Color(0xFF64748B),
                              ),
                              const SizedBox(width: 5),
                              if (item.key.isNotEmpty)
                                Text(
                                  '${item.key}: ',
                                  style: const TextStyle(
                                    fontSize: 11.5,
                                    fontWeight: FontWeight.w600,
                                    color: Color(0xFF64748B),
                                  ),
                                ),
                              Flexible(
                                child: Text(
                                  item.value,
                                  style: const TextStyle(
                                    fontSize: 11.5,
                                    fontWeight: FontWeight.w600,
                                    color: Color(0xFF1E293B),
                                  ),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                            ],
                          ),
                        );
                      }).toList(),
                    ),
                  )
                else if (ticket.formattedDescription.isNotEmpty && ticket.formattedDescription != '-')
                  Text(
                    ticket.formattedDescription,
                    style: const TextStyle(
                      color: Color(0xFF64748B),
                      fontSize: 13,
                      height: 1.4,
                    ),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),

                const SizedBox(height: 12),

                const Divider(height: 1, color: Color(0xFFF1F5F9)),

                const SizedBox(height: 10),

                // Footer: Nomor ID & Tanggal Tiket & Requester
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Row(
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
                          decoration: BoxDecoration(
                            color: const Color(0xFFF1F5F9),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: Text(
                            '#${ticket.id}',
                            style: const TextStyle(
                              color: Color(0xFF475569),
                              fontSize: 11.5,
                              fontWeight: FontWeight.w700,
                              fontFamily: 'monospace',
                            ),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Text(
                          DateFormat('dd MMM yyyy, HH:mm').format(ticket.createdAt),
                          style: const TextStyle(
                            color: Color(0xFF94A3B8),
                            fontSize: 11.5,
                            fontWeight: FontWeight.w500,
                          ),
                        ),
                      ],
                    ),
                    if (ticket.requesterName.isNotEmpty)
                      Row(
                        children: [
                          const Icon(
                            Icons.person_outline_rounded,
                            size: 14,
                            color: Color(0xFF64748B),
                          ),
                          const SizedBox(width: 4),
                          Text(
                            ticket.requesterName,
                            style: const TextStyle(
                              color: Color(0xFF64748B),
                              fontSize: 11.5,
                              fontWeight: FontWeight.w600,
                            ),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ],
                      )
                    else
                      const Row(
                        children: [
                          Text(
                            'Detail',
                            style: TextStyle(
                              color: AppTheme.oceanWater,
                              fontSize: 12,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          SizedBox(width: 2),
                          Icon(
                            Icons.chevron_right_rounded,
                            size: 16,
                            color: AppTheme.oceanWater,
                          ),
                        ],
                      ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
