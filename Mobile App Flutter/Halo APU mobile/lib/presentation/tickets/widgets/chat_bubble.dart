import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../../../core/config/api_config.dart';
import '../../../../domain/models/ticket_model.dart';
import 'package:intl/intl.dart';

class ChatBubble extends StatelessWidget {
  final TicketReply reply;
  final bool isCurrentUserAdmin;
  final String requesterName;
  final String? currentAdminName;

  const ChatBubble({
    super.key, 
    required this.reply,
    required this.isCurrentUserAdmin,
    required this.requesterName,
    this.currentAdminName,
  });

  @override
  Widget build(BuildContext context) {
    bool isMe = false;
    if (isCurrentUserAdmin) {
      if (reply.isFromAdmin) {
        if (currentAdminName != null && currentAdminName!.isNotEmpty) {
          final myName = currentAdminName!.toLowerCase().trim();
          final senderName = (reply.adminName ?? '').toLowerCase().trim();
          isMe = senderName.isNotEmpty && (
            senderName == myName || 
            myName.contains(senderName) || 
            senderName.contains(myName)
          );
        } else {
          isMe = false;
        }
      } else {
        isMe = false;
      }
    } else {
      isMe = !reply.isFromAdmin;
    }

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: isMe ? _buildMyBubble(context) : _buildOtherBubble(context),
    );
  }

  String _getAttachmentUrl(Map<String, dynamic> attachment) {
    String path = attachment['path'] ?? attachment['file_path'] ?? '';
    if (path.isNotEmpty) {
      if (path.startsWith('storage/')) {
        path = path.substring(8);
      }
      String baseUrl = ApiConfig.baseUrl;
      return '$baseUrl/attachments/serve?path=$path';
    }
    String url = attachment['url'] ?? '';
    if (url.isNotEmpty) {
      if (url.contains('/storage/')) {
        final pathAfterStorage = url.split('/storage/').last;
        String baseUrl = ApiConfig.baseUrl;
        return '$baseUrl/attachments/serve?path=$pathAfterStorage';
      }
      return url;
    }
    return '';
  }

  bool _isImage(String fileName) {
    final ext = fileName.split('.').last.toLowerCase();
    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'].contains(ext);
  }

  void _showImagePreview(BuildContext context, String url, String title) {
    showDialog<void>(
      context: context,
      builder: (ctx) => Dialog(
        backgroundColor: Colors.transparent,
        insetPadding: const EdgeInsets.all(16),
        child: Stack(
          alignment: Alignment.topRight,
          children: [
            Container(
              decoration: BoxDecoration(
                color: Colors.black.withValues(alpha: 0.9),
                borderRadius: BorderRadius.circular(16),
              ),
              padding: const EdgeInsets.all(8),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: InteractiveViewer(
                  panEnabled: true,
                  minScale: 0.5,
                  maxScale: 4.0,
                  child: Image.network(
                    url,
                    fit: BoxFit.contain,
                    errorBuilder: (_, __, ___) => const Center(
                      child: Text('Gagal memuat gambar', style: TextStyle(color: Colors.white)),
                    ),
                  ),
                ),
              ),
            ),
            IconButton(
              icon: const Icon(Icons.close_rounded, color: Colors.white, size: 28),
              onPressed: () => Navigator.of(ctx).pop(),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildAttachments(BuildContext context, List<Map<String, dynamic>> attachments, bool isMe) {
    if (attachments.isEmpty) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: isMe ? CrossAxisAlignment.end : CrossAxisAlignment.start,
      children: attachments.map((att) {
        final fileName = att['fileName'] ?? att['original_name'] ?? 'Lampiran';
        final isImg = _isImage(fileName.toString()) || (att['mimeType']?.toString().startsWith('image') ?? false);
        final url = _getAttachmentUrl(att);

        if (isImg && url.isNotEmpty) {
          return Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: InkWell(
              onTap: () => _showImagePreview(context, url, fileName.toString()),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Image.network(
                  url,
                  height: 160,
                  width: double.infinity,
                  fit: BoxFit.cover,
                  errorBuilder: (context, error, stackTrace) => Container(
                    height: 160,
                    color: isMe ? Colors.black12 : Colors.grey.shade200,
                    child: Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.broken_image, color: isMe ? Colors.white54 : Colors.grey),
                          const SizedBox(height: 4),
                          Text('Gagal memuat', style: TextStyle(fontSize: 10, color: isMe ? Colors.white54 : Colors.grey)),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          );
        } else {
          return InkWell(
            onTap: () async {
              if (url.isNotEmpty) {
                final uri = Uri.parse(url);
                if (await canLaunchUrl(uri)) await launchUrl(uri, mode: LaunchMode.externalApplication);
              }
            },
            child: Container(
              margin: const EdgeInsets.only(bottom: 8),
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: isMe ? Colors.black.withValues(alpha: 0.15) : Colors.grey.shade100,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: isMe ? Colors.transparent : Colors.grey.shade300),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.insert_drive_file, size: 20, color: isMe ? Colors.white : Colors.grey.shade700),
                  const SizedBox(width: 8),
                  Flexible(
                    child: Text(
                      fileName,
                      style: TextStyle(
                        fontSize: 12,
                        color: isMe ? Colors.white : Colors.black87,
                        decoration: TextDecoration.underline,
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              ),
            ),
          );
        }
      }).toList(),
    );
  }

  String _getIndonesianDay(int weekday) {
    switch (weekday) {
      case 1: return 'Senin';
      case 2: return 'Selasa';
      case 3: return 'Rabu';
      case 4: return 'Kamis';
      case 5: return 'Jumat';
      case 6: return 'Sabtu';
      case 7: return 'Minggu';
      default: return '';
    }
  }

  String _formatDateTime(DateTime dt) {
    final dayName = _getIndonesianDay(dt.weekday);
    final dateStr = DateFormat('dd MMM yyyy').format(dt);
    final timeStr = DateFormat('HH:mm').format(dt);
    return '$dayName, $dateStr • $timeStr';
  }

  Widget _buildOtherBubble(BuildContext context) {
    final bool isAdminSender = reply.isFromAdmin;
    final String senderLabel = isAdminSender
        ? (reply.adminName != null && reply.adminName!.isNotEmpty
            ? '${reply.adminName} (Operator)'
            : 'Admin (IT Support)')
        : '$requesterName (Pengguna)';

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // Avatar
        Container(
          width: 34,
          height: 34,
          decoration: BoxDecoration(
            color: isAdminSender ? const Color(0xFFEFF6FF) : Colors.grey.shade100,
            shape: BoxShape.circle,
            border: Border.all(
              color: isAdminSender ? const Color(0xFF93C5FD) : Colors.grey.shade300,
            ),
          ),
          child: Center(
            child: Icon(
              isAdminSender ? Icons.support_agent_rounded : Icons.person_outline_rounded,
              size: 18,
              color: isAdminSender ? const Color(0xFF2563EB) : Colors.grey.shade600,
            ),
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.only(left: 4, bottom: 4),
                child: Text(
                  senderLabel,
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: isAdminSender ? const Color(0xFF1E40AF) : Colors.grey.shade700,
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: const BorderRadius.only(
                    topLeft: Radius.circular(4),
                    topRight: Radius.circular(16),
                    bottomLeft: Radius.circular(16),
                    bottomRight: Radius.circular(16),
                  ),
                  border: Border.all(color: Colors.grey.shade200),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.04),
                      blurRadius: 4,
                      offset: const Offset(0, 2),
                    ),
                  ],
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (reply.attachments != null && reply.attachments!.isNotEmpty)
                      _buildAttachments(context, reply.attachments!, false),
                    Text(
                      reply.note,
                      style: const TextStyle(fontSize: 14, color: Colors.black87),
                    ),
                    const SizedBox(height: 4),
                    Align(
                      alignment: Alignment.bottomRight,
                      child: Text(
                        _formatDateTime(reply.createdAt.toLocal()),
                        style: TextStyle(fontSize: 10, color: Colors.grey.shade500),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
        const SizedBox(width: 48), // Padding from right edge
      ],
    );
  }

  Widget _buildMyBubble(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisAlignment: MainAxisAlignment.end,
      children: [
        const SizedBox(width: 48), // Padding from left edge
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Padding(
                padding: const EdgeInsets.only(right: 4, bottom: 4),
                child: Text(
                  'Anda',
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: Colors.grey.shade600,
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFF00B8D9), // primary-container
                  borderRadius: const BorderRadius.only(
                    topLeft: Radius.circular(16),
                    topRight: Radius.circular(4),
                    bottomLeft: Radius.circular(16),
                    bottomRight: Radius.circular(16),
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.04),
                      blurRadius: 4,
                      offset: const Offset(0, 2),
                    ),
                  ],
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    if (reply.attachments != null && reply.attachments!.isNotEmpty)
                      _buildAttachments(context, reply.attachments!, true),
                    Text(
                      reply.note,
                      style: const TextStyle(fontSize: 14, color: Colors.white),
                    ),
                    const SizedBox(height: 4),
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          _formatDateTime(reply.createdAt.toLocal()),
                          style: TextStyle(fontSize: 10, color: Colors.white.withValues(alpha: 0.8)),
                        ),
                        const SizedBox(width: 4),
                        Icon(Icons.done_all, size: 14, color: Colors.white.withValues(alpha: 0.8)),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
