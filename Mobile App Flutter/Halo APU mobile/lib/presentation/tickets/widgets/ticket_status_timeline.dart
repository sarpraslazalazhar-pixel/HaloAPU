import 'package:flutter/material.dart';
import 'package:halo_apu_mobile/core/theme/app_theme.dart';
import '../../../../domain/models/ticket_model.dart';
import 'package:intl/intl.dart';

class TicketStatusTimeline extends StatelessWidget {
  final TicketModel ticket;

  const TicketStatusTimeline({super.key, required this.ticket});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: Colors.grey.withValues(alpha: 0.2)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.04),
            blurRadius: 18,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      padding: const EdgeInsets.all(16),
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          // Ambient decorative blob (simulating the blur-2xl)
          Positioned(
            top: -20,
            right: -20,
            child: Container(
              width: 100,
              height: 100,
              decoration: BoxDecoration(
                color: AppTheme.oceanWater.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
            ),
          ),
          
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'STATUS PERJALANAN',
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.bold,
                  letterSpacing: 1.2,
                  color: Colors.grey,
                ),
              ),
              const SizedBox(height: 16),
              
              // Timeline Steps
              Stack(
                children: [
                  // Vertical Line
                  Positioned(
                    left: 11,
                    top: 8,
                    bottom: 24, // adjust to stop before last item
                    child: Container(
                      width: 2,
                      color: Colors.grey.withValues(alpha: 0.3),
                    ),
                  ),
                  
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Step 1: Dibuat
                      _buildTimelineStep(
                        title: 'Dibuat',
                        subtitle: DateFormat('dd MMM yyyy, hh:mm a').format(ticket.createdAt),
                        isCompleted: true,
                        isActive: ticket.status == TicketStatus.open,
                      ),
                      
                      // Step 2: Diproses
                      if (ticket.status != TicketStatus.open)
                        _buildTimelineStep(
                          title: 'Diproses',
                          subtitle: ticket.status == TicketStatus.needRevision ? 'Tiket sedang direvisi.' : 'Sedang dalam penanganan teknisi.',
                          isCompleted: ticket.status == TicketStatus.solved || ticket.status == TicketStatus.rejected || ticket.status == TicketStatus.cancelled,
                          isActive: ticket.status == TicketStatus.processing || ticket.status == TicketStatus.needRevision,
                          showEstimasi: ticket.status == TicketStatus.processing,
                        ),
                        
                      // Step 3: Selesai / Ditolak / Dibatalkan
                      if (ticket.status == TicketStatus.solved || ticket.status == TicketStatus.rejected || ticket.status == TicketStatus.cancelled)
                        _buildTimelineStep(
                          title: ticket.status == TicketStatus.solved ? 'Selesai' : (ticket.status == TicketStatus.rejected ? 'Ditolak' : 'Dibatalkan'),
                          subtitle: ticket.status == TicketStatus.solved ? 'Tiket telah diselesaikan' : 'Tiket dibatalkan/ditolak',
                          isCompleted: true,
                          isActive: true,
                          isLast: true,
                          color: ticket.status == TicketStatus.solved ? AppTheme.success : (ticket.status == TicketStatus.rejected ? AppTheme.danger : Colors.grey),
                        ),
                    ],
                  ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildTimelineStep({
    required String title,
    required String subtitle,
    bool isCompleted = false,
    bool isActive = false,
    bool showEstimasi = false,
    bool isLast = false,
    Color? color,
  }) {
    final statusColor = color ?? (isCompleted ? AppTheme.success : (isActive ? AppTheme.oceanWater : Colors.grey));
    
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Indicator
          Stack(
            alignment: Alignment.center,
            children: [
              if (isActive && !isCompleted && !isLast)
                Container(
                  width: 32,
                  height: 32,
                  decoration: BoxDecoration(
                    color: statusColor.withValues(alpha: 0.2),
                    shape: BoxShape.circle,
                  ),
                ),
              Container(
                width: 24,
                height: 24,
                decoration: BoxDecoration(
                  color: statusColor,
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white, width: 2),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.1),
                      blurRadius: 4,
                    )
                  ],
                ),
                child: Center(
                  child: Icon(
                    isCompleted || isLast ? Icons.check : Icons.sync,
                    color: Colors.white,
                    size: 14,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(width: 16),
          // Content
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 14,
                    color: isActive ? statusColor : Colors.black87,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  subtitle,
                  style: TextStyle(
                    color: Colors.grey.shade600,
                    fontSize: 12,
                  ),
                ),
                if (showEstimasi)
                  Container(
                    margin: const EdgeInsets.only(top: 8),
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: statusColor.withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Container(
                          width: 8,
                          height: 8,
                          decoration: BoxDecoration(
                            color: statusColor,
                            shape: BoxShape.circle,
                          ),
                        ),
                        const SizedBox(width: 6),
                        Text(
                          'Estimasi selesai: 2 Jam',
                          style: TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.w500,
                            color: statusColor,
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
