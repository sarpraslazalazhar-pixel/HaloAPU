import 'package:flutter/material.dart';
import 'package:halo_apu_mobile/core/theme/app_theme.dart';
import '../../../../domain/models/ticket_model.dart';
import 'package:intl/intl.dart';

class TicketStatusTimeline extends StatelessWidget {
  final TicketModel ticket;
  final VoidCallback? onAssignTap;
  final bool isCollapsed;
  final VoidCallback? onToggleExpand;

  const TicketStatusTimeline({
    super.key,
    required this.ticket,
    this.onAssignTap,
    this.isCollapsed = false,
    this.onToggleExpand,
  });

  @override
  Widget build(BuildContext context) {
    final status = ticket.status;

    if (isCollapsed) {
      return _buildCollapsedView(context, status);
    }

    return _buildExpandedView(context, status);
  }

  Widget _buildCollapsedView(BuildContext context, TicketStatus status) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFE2E8F0), width: 1.0),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.03),
            blurRadius: 10,
            offset: const Offset(0, 3),
          ),
        ],
      ),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      child: Row(
        children: [
          // Status Pill
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
            decoration: BoxDecoration(
              color: _getStatusBg(status),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: _getStatusColor(status).withValues(alpha: 0.25)),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 6,
                  height: 6,
                  decoration: BoxDecoration(
                    color: _getStatusColor(status),
                    shape: BoxShape.circle,
                  ),
                ),
                const SizedBox(width: 5),
                Text(
                  ticket.statusIndonesianLabel,
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.bold,
                    color: _getStatusColor(status),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),

          // Operator Pill
          Expanded(
            child: InkWell(
              borderRadius: BorderRadius.circular(10),
              onTap: onAssignTap,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: const Color(0xFFF8FAFC),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: const Color(0xFFE2E8F0)),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(
                      Icons.assignment_ind_rounded,
                      size: 13,
                      color: ticket.assignedTo != null ? const Color(0xFF4F46E5) : Colors.grey.shade500,
                    ),
                    const SizedBox(width: 4),
                    Flexible(
                      child: Text(
                        ticket.assignedTo != null ? ticket.assignedTo! : 'Belum Ditugaskan',
                        style: TextStyle(
                          fontSize: 10.5,
                          fontWeight: ticket.assignedTo != null ? FontWeight.bold : FontWeight.w500,
                          color: ticket.assignedTo != null ? const Color(0xFF312E81) : Colors.grey.shade600,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),

          // Expand toggle button
          if (onToggleExpand != null)
            InkWell(
              borderRadius: BorderRadius.circular(8),
              onTap: onToggleExpand,
              child: const Padding(
                padding: EdgeInsets.only(left: 6, right: 2, top: 4, bottom: 4),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      'Alur',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF00768C),
                      ),
                    ),
                    Icon(
                      Icons.keyboard_arrow_down_rounded,
                      size: 18,
                      color: Color(0xFF00768C),
                    ),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _buildExpandedView(BuildContext context, TicketStatus status) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: const Color(0xFFE2E8F0), width: 1.2),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.03),
            blurRadius: 14,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Row(
                children: [
                  Icon(Icons.timeline_rounded, size: 16, color: Color(0xFF00768C)),
                  SizedBox(width: 6),
                  Text(
                    'PROGRES STATUS TIKET',
                    style: TextStyle(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 0.8,
                      color: Color(0xFF475569),
                    ),
                  ),
                ],
              ),
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                    decoration: BoxDecoration(
                      color: _getStatusBg(status),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: _getStatusColor(status).withValues(alpha: 0.3)),
                    ),
                    child: Text(
                      ticket.statusIndonesianLabel,
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        color: _getStatusColor(status),
                      ),
                    ),
                  ),
                  if (onToggleExpand != null) ...[
                    const SizedBox(width: 6),
                    InkWell(
                      borderRadius: BorderRadius.circular(8),
                      onTap: onToggleExpand,
                      child: Container(
                        padding: const EdgeInsets.all(3),
                        decoration: BoxDecoration(
                          color: Colors.grey.shade100,
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: const Icon(
                          Icons.keyboard_arrow_up_rounded,
                          size: 18,
                          color: Color(0xFF475569),
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ],
          ),
          const SizedBox(height: 16),
          
          // Timeline Steps
          Stack(
            children: [
              // Vertical connecting line
              Positioned(
                left: 11,
                top: 12,
                bottom: 24,
                child: Container(
                  width: 2,
                  color: const Color(0xFFE2E8F0),
                ),
              ),
              
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Step 1: Dibuat
                  _buildTimelineStep(
                    title: 'Tiket Dibuat',
                    subtitle: DateFormat('dd MMM yyyy, HH:mm').format(ticket.createdAt),
                    isCompleted: true,
                    isActive: status == TicketStatus.open,
                  ),
                  
                  // Step 2: Penugasan & Penanganan
                  _buildTimelineStep(
                    title: status == TicketStatus.needRevision
                        ? 'Menunggu Revisi'
                        : (status == TicketStatus.open
                            ? (ticket.assignedTo != null ? 'Operator Ditugaskan' : 'Menunggu Penugasan')
                            : 'Sedang Diproses'),
                    subtitle: status == TicketStatus.needRevision
                        ? 'Perlu perbaikan data dari pemohon.'
                        : (ticket.assignedTo != null
                            ? 'Ditangani oleh ${ticket.assignedTo}'
                            : (status == TicketStatus.open
                                ? 'Belum ditugaskan ke operator / teknisi.'
                                : 'Sedang dalam penanganan teknisi/admin.')),
                    isCompleted: status == TicketStatus.solved ||
                        status == TicketStatus.rejected ||
                        status == TicketStatus.cancelled,
                    isActive: status == TicketStatus.processing ||
                        status == TicketStatus.needRevision ||
                        (status == TicketStatus.open && ticket.assignedTo != null),
                    actionWidget: onAssignTap != null
                        ? InkWell(
                            onTap: onAssignTap,
                            borderRadius: BorderRadius.circular(6),
                            child: Container(
                              margin: const EdgeInsets.only(top: 4),
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                              decoration: BoxDecoration(
                                color: const Color(0xFFEEF2FF),
                                borderRadius: BorderRadius.circular(6),
                                border: Border.all(color: const Color(0xFFC7D2FE)),
                              ),
                              child: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  const Icon(Icons.assignment_ind_rounded, size: 12, color: Color(0xFF4F46E5)),
                                  const SizedBox(width: 4),
                                  Text(
                                    ticket.assignedTo != null ? 'Ganti Operator' : 'Pilih Operator',
                                    style: const TextStyle(fontSize: 10.5, fontWeight: FontWeight.bold, color: Color(0xFF4F46E5)),
                                  ),
                                ],
                              ),
                            ),
                          )
                        : null,
                  ),
                    
                  // Step 3: Selesai / Ditolak / Dibatalkan
                  if (status == TicketStatus.solved || status == TicketStatus.rejected || status == TicketStatus.cancelled)
                    _buildTimelineStep(
                      title: status == TicketStatus.solved ? 'Selesai' : (status == TicketStatus.rejected ? 'Ditolak' : 'Dibatalkan'),
                      subtitle: status == TicketStatus.solved ? 'Solusi telah diserahkan & tiket ditutup.' : 'Permintaan tiket ditolak/dibatalkan.',
                      isCompleted: true,
                      isActive: true,
                      isLast: true,
                      color: status == TicketStatus.solved ? AppTheme.success : (status == TicketStatus.rejected ? AppTheme.danger : const Color(0xFF64748B)),
                    ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }

  Color _getStatusColor(TicketStatus status) {
    switch (status) {
      case TicketStatus.open:
        return const Color(0xFF0284C7);
      case TicketStatus.processing:
      case TicketStatus.needRevision:
        return const Color(0xFFD97706);
      case TicketStatus.solved:
        return const Color(0xFF16A34A);
      case TicketStatus.rejected:
        return const Color(0xFFDC2626);
      case TicketStatus.cancelled:
      case TicketStatus.pending:
        return const Color(0xFF64748B);
    }
  }

  Color _getStatusBg(TicketStatus status) {
    switch (status) {
      case TicketStatus.open:
        return const Color(0xFFE0F2FE);
      case TicketStatus.processing:
      case TicketStatus.needRevision:
        return const Color(0xFFFEF3C7);
      case TicketStatus.solved:
        return const Color(0xFFDCFCE7);
      case TicketStatus.rejected:
        return const Color(0xFFFEE2E2);
      case TicketStatus.cancelled:
      case TicketStatus.pending:
        return const Color(0xFFF1F5F9);
    }
  }

  Widget _buildTimelineStep({
    required String title,
    required String subtitle,
    bool isCompleted = false,
    bool isActive = false,
    bool isLast = false,
    Color? color,
    Widget? actionWidget,
  }) {
    final statusColor = color ?? (isCompleted ? AppTheme.success : (isActive ? const Color(0xFF00768C) : const Color(0xFFCBD5E1)));
    
    return Padding(
      padding: EdgeInsets.only(bottom: isLast ? 0 : 16),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Indicator Dot
          Container(
            width: 24,
            height: 24,
            decoration: BoxDecoration(
              color: isCompleted ? statusColor : Colors.white,
              shape: BoxShape.circle,
              border: Border.all(
                color: statusColor,
                width: isCompleted ? 0 : 2.5,
              ),
              boxShadow: [
                BoxShadow(
                  color: statusColor.withValues(alpha: 0.25),
                  blurRadius: 6,
                  offset: const Offset(0, 2),
                ),
              ],
            ),
            child: isCompleted
                ? const Icon(Icons.check_rounded, color: Colors.white, size: 14)
                : (isActive
                    ? Center(
                        child: Container(
                          width: 8,
                          height: 8,
                          decoration: BoxDecoration(
                            color: statusColor,
                            shape: BoxShape.circle,
                          ),
                        ),
                      )
                    : null),
          ),
          const SizedBox(width: 14),
          
          // Content
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: TextStyle(
                    fontSize: 13.5,
                    fontWeight: isCompleted || isActive ? FontWeight.bold : FontWeight.w500,
                    color: isCompleted || isActive ? const Color(0xFF1E293B) : const Color(0xFF94A3B8),
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  subtitle,
                  style: const TextStyle(
                    fontSize: 11.5,
                    color: Color(0xFF64748B),
                    height: 1.35,
                  ),
                ),
                if (actionWidget != null) actionWidget,
              ],
            ),
          ),
        ],
      ),
    );
  }
}
