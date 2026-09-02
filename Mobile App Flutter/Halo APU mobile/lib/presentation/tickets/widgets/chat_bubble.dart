import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
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

  bool get _isSystemAction {
    final action = reply.action.toLowerCase().trim();
    final note = reply.note.toLowerCase().trim();

    // Regular user/admin chat message actions
    if (action == 'balasan' || action == 'pesan' || action == 'chat' || action == 'reply' || action == 'diskusi' || action == 'catatan') {
      return false;
    }

    // System event actions
    if (action == 'dibuat' ||
        action.contains('assign') ||
        action.contains('status') ||
        action.contains('priority') ||
        action.contains('revisi') ||
        action == 'solve' ||
        action == 'reject' ||
        action == 'cancel') {
      return true;
    }

    // Fallback: Check note keywords for auto-generated logs
    if (note.startsWith('tiket dibuat') ||
        note.startsWith('tiket ditugaskan') ||
        note.startsWith('penugasan operator') ||
        note.startsWith('status diubah') ||
        note.startsWith('prioritas diubah') ||
        note.startsWith('revisi diajukan') ||
        note.startsWith('solusi diajukan')) {
      return true;
    }

    return false;
  }

  @override
  Widget build(BuildContext context) {
    if (_isSystemAction) {
      return _buildSystemEventPill(context);
    }

    bool isMe = false;
    if (isCurrentUserAdmin) {
      if (reply.isFromAdmin) {
        if (currentAdminName != null && currentAdminName!.isNotEmpty) {
          final myName = currentAdminName!.toLowerCase().trim();
          final senderName = (reply.adminName ?? '').toLowerCase().trim();
          isMe = senderName.isNotEmpty &&
              (senderName == myName ||
                  myName.contains(senderName) ||
                  senderName.contains(myName));
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
      padding: const EdgeInsets.only(bottom: 12),
      child: isMe ? _buildMyBubble(context) : _buildOtherBubble(context),
    );
  }

  Widget _buildSystemEventPill(BuildContext context) {
    IconData icon = Icons.info_outline_rounded;
    Color iconColor = const Color(0xFF64748B);
    Color bgColor = const Color(0xFFF1F5F9);
    Color borderColor = const Color(0xFFE2E8F0);

    final noteLower = reply.note.toLowerCase();
    final actionLower = reply.action.toLowerCase();

    if (actionLower.contains('assign') || noteLower.contains('ditugaskan') || noteLower.contains('penugasan')) {
      icon = Icons.assignment_ind_rounded;
      iconColor = const Color(0xFF4F46E5);
      bgColor = const Color(0xFFEEF2FF);
      borderColor = const Color(0xFFC7D2FE);
    } else if (actionLower.contains('dibuat') || noteLower.contains('dibuat')) {
      icon = Icons.add_task_rounded;
      iconColor = const Color(0xFF0284C7);
      bgColor = const Color(0xFFE0F2FE);
      borderColor = const Color(0xFFBAE6FD);
    } else if (noteLower.contains('selesai') || noteLower.contains('solve') || noteLower.contains('diterima')) {
      icon = Icons.check_circle_rounded;
      iconColor = const Color(0xFF16A34A);
      bgColor = const Color(0xFFDCFCE7);
      borderColor = const Color(0xFFBBF7D0);
    } else if (noteLower.contains('tolak') || noteLower.contains('batal') || noteLower.contains('reject')) {
      icon = Icons.cancel_rounded;
      iconColor = const Color(0xFFDC2626);
      bgColor = const Color(0xFFFEE2E2);
      borderColor = const Color(0xFFFECACA);
    } else if (noteLower.contains('proses') || noteLower.contains('status')) {
      icon = Icons.sync_alt_rounded;
      iconColor = const Color(0xFFD97706);
      bgColor = const Color(0xFFFEF3C7);
      borderColor = const Color(0xFFFDE68A);
    } else if (noteLower.contains('revisi')) {
      icon = Icons.edit_note_rounded;
      iconColor = const Color(0xFF9333EA);
      bgColor = const Color(0xFFF3E8FF);
      borderColor = const Color(0xFFE9D5FF);
    }

    return Center(
      child: Container(
        margin: const EdgeInsets.symmetric(vertical: 8, horizontal: 16),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
        decoration: BoxDecoration(
          color: bgColor,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: borderColor, width: 1),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.02),
              blurRadius: 4,
              offset: const Offset(0, 1),
            ),
          ],
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 13, color: iconColor),
            const SizedBox(width: 6),
            Flexible(
              child: Text(
                reply.note,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  color: Color(0xFF334155),
                  letterSpacing: 0.1,
                ),
              ),
            ),
            const SizedBox(width: 6),
            Text(
              DateFormat('HH:mm').format(reply.createdAt.toLocal()),
              style: TextStyle(fontSize: 9.5, color: Colors.grey.shade500),
            ),
          ],
        ),
      ),
    );
  }

  void _copyToClipboard(BuildContext context) {
    Clipboard.setData(ClipboardData(text: reply.note));
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('Pesan disalin ke papan klip'),
        duration: Duration(seconds: 1),
        behavior: SnackBarBehavior.floating,
      ),
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
                color: Colors.black.withValues(alpha: 0.92),
                borderRadius: BorderRadius.circular(16),
              ),
              padding: const EdgeInsets.all(12),
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
                      child: Text('Gagal memuat gambar',
                          style: TextStyle(color: Colors.white)),
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
        final isImg = _isImage(fileName.toString()) ||
            (att['mimeType']?.toString().startsWith('image') ?? false);
        final url = _getAttachmentUrl(att);

        if (isImg && url.isNotEmpty) {
          return Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: InkWell(
              borderRadius: BorderRadius.circular(12),
              onTap: () => _showImagePreview(context, url, fileName.toString()),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Stack(
                  children: [
                    Image.network(
                      url,
                      height: 160,
                      width: double.infinity,
                      fit: BoxFit.cover,
                      errorBuilder: (context, error, stackTrace) => Container(
                        height: 140,
                        color: isMe ? Colors.black12 : Colors.grey.shade200,
                        child: Center(
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.broken_image,
                                  color: isMe ? Colors.white54 : Colors.grey),
                              const SizedBox(height: 4),
                              Text('Gagal memuat gambar',
                                  style: TextStyle(
                                      fontSize: 10,
                                      color: isMe ? Colors.white54 : Colors.grey)),
                            ],
                          ),
                        ),
                      ),
                    ),
                    Positioned(
                      right: 8,
                      bottom: 8,
                      child: Container(
                        padding: const EdgeInsets.all(4),
                        decoration: BoxDecoration(
                          color: Colors.black.withValues(alpha: 0.5),
                          shape: BoxShape.circle,
                        ),
                        child: const Icon(Icons.zoom_in_rounded, size: 16, color: Colors.white),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        } else {
          return InkWell(
            borderRadius: BorderRadius.circular(10),
            onTap: () async {
              if (url.isNotEmpty) {
                final uri = Uri.parse(url);
                if (await canLaunchUrl(uri)) {
                  await launchUrl(uri, mode: LaunchMode.externalApplication);
                }
              }
            },
            child: Container(
              margin: const EdgeInsets.only(bottom: 8),
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: isMe ? Colors.white.withValues(alpha: 0.15) : const Color(0xFFF1F5F9),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(
                  color: isMe ? Colors.white.withValues(alpha: 0.25) : const Color(0xFFCBD5E1),
                ),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(
                    Icons.insert_drive_file_rounded,
                    size: 18,
                    color: isMe ? Colors.white : const Color(0xFF475569),
                  ),
                  const SizedBox(width: 8),
                  Flexible(
                    child: Text(
                      fileName,
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: isMe ? Colors.white : const Color(0xFF1E293B),
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

  Widget _buildOtherBubble(BuildContext context) {
    final bool isAdminSender = reply.isFromAdmin;
    final String senderName = isAdminSender
        ? (reply.adminName != null && reply.adminName!.isNotEmpty
            ? reply.adminName!
            : 'Operator')
        : (requesterName.isNotEmpty ? requesterName : 'Pemohon');

    final String roleTag = isAdminSender ? 'Operator' : 'Pemohon';
    final Color roleTagBg = isAdminSender ? const Color(0xFFEEF2FF) : const Color(0xFFECFDF5);
    final Color roleTagColor = isAdminSender ? const Color(0xFF4F46E5) : const Color(0xFF059669);
    final Color avatarBg = isAdminSender ? const Color(0xFFEEF2FF) : const Color(0xFFF0FDF4);
    final Color avatarColor = isAdminSender ? const Color(0xFF4F46E5) : const Color(0xFF10B981);

    final String initial = senderName.isNotEmpty ? senderName[0].toUpperCase() : (isAdminSender ? 'O' : 'U');

    return GestureDetector(
      onLongPress: () => _copyToClipboard(context),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Avatar
          CircleAvatar(
            radius: 17,
            backgroundColor: avatarBg,
            child: Text(
              initial,
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 13,
                color: avatarColor,
              ),
            ),
          ),
          const SizedBox(width: 10),
          // Bubble Content
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Header Sender Name + Role Badge
                Padding(
                  padding: const EdgeInsets.only(left: 2, bottom: 4),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Flexible(
                        child: Text(
                          senderName,
                          style: const TextStyle(
                            fontSize: 12.5,
                            fontWeight: FontWeight.bold,
                            color: Color(0xFF1E293B),
                          ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1.5),
                        decoration: BoxDecoration(
                          color: roleTagBg,
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Text(
                          roleTag,
                          style: TextStyle(
                            fontSize: 9.5,
                            fontWeight: FontWeight.bold,
                            color: roleTagColor,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: const BorderRadius.only(
                      topLeft: Radius.circular(4),
                      topRight: Radius.circular(18),
                      bottomLeft: Radius.circular(18),
                      bottomRight: Radius.circular(18),
                    ),
                    border: Border.all(color: const Color(0xFFE2E8F0), width: 1),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withValues(alpha: 0.03),
                        blurRadius: 8,
                        offset: const Offset(0, 2),
                      ),
                    ],
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (reply.attachments != null && reply.attachments!.isNotEmpty)
                        _buildAttachments(context, reply.attachments!, false),
                      if (reply.note.isNotEmpty && reply.note != '[Lampiran]')
                        Text(
                          reply.note,
                          style: const TextStyle(
                            fontSize: 13.5,
                            color: Color(0xFF1E293B),
                            height: 1.35,
                          ),
                        ),
                      const SizedBox(height: 4),
                      Align(
                        alignment: Alignment.bottomRight,
                        child: Text(
                          DateFormat('HH:mm').format(reply.createdAt.toLocal()),
                          style: TextStyle(fontSize: 10, color: Colors.grey.shade400),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 40), // Right spacing
        ],
      ),
    );
  }

  Widget _buildMyBubble(BuildContext context) {
    return GestureDetector(
      onLongPress: () => _copyToClipboard(context),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.end,
        children: [
          const SizedBox(width: 44), // Left spacing
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                const Padding(
                  padding: EdgeInsets.only(right: 2, bottom: 4),
                  child: Text(
                    'Anda',
                    style: TextStyle(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w600,
                      color: Color(0xFF64748B),
                    ),
                  ),
                ),
                Container(
                  padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
                  decoration: BoxDecoration(
                    gradient: const LinearGradient(
                      colors: [Color(0xFF00768C), Color(0xFF008EA8)],
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ),
                    borderRadius: const BorderRadius.only(
                      topLeft: Radius.circular(18),
                      topRight: Radius.circular(4),
                      bottomLeft: Radius.circular(18),
                      bottomRight: Radius.circular(18),
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: const Color(0xFF00768C).withValues(alpha: 0.25),
                        blurRadius: 8,
                        offset: const Offset(0, 3),
                      ),
                    ],
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      if (reply.attachments != null && reply.attachments!.isNotEmpty)
                        _buildAttachments(context, reply.attachments!, true),
                      if (reply.note.isNotEmpty && reply.note != '[Lampiran]')
                        Text(
                          reply.note,
                          style: const TextStyle(
                            fontSize: 13.5,
                            color: Colors.white,
                            height: 1.35,
                          ),
                        ),
                      const SizedBox(height: 4),
                      Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            DateFormat('HH:mm').format(reply.createdAt.toLocal()),
                            style: TextStyle(
                              fontSize: 10,
                              color: Colors.white.withValues(alpha: 0.8),
                            ),
                          ),
                          const SizedBox(width: 4),
                          Icon(
                            Icons.done_all_rounded,
                            size: 14,
                            color: Colors.white.withValues(alpha: 0.85),
                          ),
                        ],
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
