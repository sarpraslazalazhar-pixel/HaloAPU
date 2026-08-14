import 'package:flutter/material.dart';
import '../../../../domain/models/ticket_model.dart';
import 'package:intl/intl.dart';

class TicketCard extends StatelessWidget {
  final TicketModel ticket;
  final VoidCallback onTap;

  const TicketCard({
    super.key,
    required this.ticket,
    required this.onTap,
  });

  Color _getStatusColor(TicketStatus status) {
    switch (status) {
      case TicketStatus.open:
        return const Color(0xFF00B8D9); // Ocean Water
      case TicketStatus.processing:
        return const Color(0xFFF59E0B); // Amber / Warning
      case TicketStatus.solved:
        return const Color(0xFF22C55E); // Green / Success
      case TicketStatus.rejected:
        return const Color(0xFFEF4444); // Red / Danger
      case TicketStatus.cancelled:
        return Colors.grey;
      case TicketStatus.needRevision:
        return const Color(0xFFF59E0B);
      case TicketStatus.pending:
        return Colors.grey;
    }
  }

  String _getStatusText(TicketStatus status) {
    switch (status) {
      case TicketStatus.open:
        return 'Open';
      case TicketStatus.processing:
        return 'Processing';
      case TicketStatus.solved:
        return 'Solved';
      case TicketStatus.rejected:
        return 'Rejected';
      case TicketStatus.cancelled:
        return 'Cancelled';
      case TicketStatus.needRevision:
        return 'Revision';
      case TicketStatus.pending:
        return 'Pending';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 16),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: BorderSide(
          color: Colors.grey.withValues(alpha: 0.2),
          width: 1,
        ),
      ),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                    decoration: BoxDecoration(
                      color: _getStatusColor(ticket.status).withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Text(
                      _getStatusText(ticket.status),
                      style: TextStyle(
                        color: _getStatusColor(ticket.status),
                        fontWeight: FontWeight.bold,
                        fontSize: 12,
                      ),
                    ),
                  ),
                  Text(
                    DateFormat('dd MMM yyyy, HH:mm').format(ticket.createdAt),
                    style: TextStyle(
                      color: Colors.grey.shade600,
                      fontSize: 12,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Text(
                ticket.title,
                style: const TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 16,
                ),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 8),
              Text(
                ticket.formattedDescription,
                style: TextStyle(
                  color: Colors.grey.shade700,
                  fontSize: 14,
                ),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 16),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Row(
                    children: [
                      Icon(Icons.folder_outlined, size: 16, color: Colors.grey.shade600),
                      const SizedBox(width: 4),
                      Text(
                        ticket.category,
                        style: TextStyle(
                          color: Colors.grey.shade600,
                          fontSize: 12,
                        ),
                      ),
                    ],
                  ),
                  Text(
                    ticket.id,
                    style: TextStyle(
                      color: Colors.grey.shade500,
                      fontSize: 12,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
