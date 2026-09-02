import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:intl/intl.dart';
import '../../../../core/config/api_config.dart';
import '../../../../domain/models/ticket_model.dart';
import '../../../../core/services/ticket_service.dart';
import 'providers/user_ticket_provider.dart';
import 'package:halo_apu_mobile/core/theme/app_theme.dart';
import '../widgets/image_doodle_screen.dart';
import 'widgets/ticket_status_timeline.dart';
import 'widgets/chat_bubble.dart';

class UserTicketDetailScreen extends ConsumerStatefulWidget {
  final TicketModel ticket;

  const UserTicketDetailScreen({super.key, required this.ticket});

  @override
  ConsumerState<UserTicketDetailScreen> createState() => _UserTicketDetailScreenState();
}

class _UserTicketDetailScreenState extends ConsumerState<UserTicketDetailScreen> with SingleTickerProviderStateMixin {
  late TabController _tabController;
  final ScrollController _chatScrollController = ScrollController();
  final FocusNode _chatFocusNode = FocusNode();
  final TextEditingController _messageController = TextEditingController();

  bool _isSending = false;
  bool _isLoadingDetail = true;
  bool _isActionLoading = false;
  final TicketService _ticketService = TicketService();

  List<TicketReply> _logs = [];
  List<Map<String, dynamic>> _attachments = [];
  final List<XFile> _replyAttachments = [];
  List<Map<String, dynamic>> _formFields = [];
  Map<String, dynamic> _formData = {};
  Map<String, dynamic>? _csatData;
  int _maxRevisions = 5;
  int _revisionCount = 0;
  bool _isResultAccepted = false;
  bool _isRevisionEnabled = false;
  late TicketModel _currentTicket;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
    _currentTicket = widget.ticket;

    _chatFocusNode.addListener(() {
      if (_chatFocusNode.hasFocus) {
        _scrollToBottom();
      }
    });

    _loadTicketDetail();
  }

  Future<void> _loadTicketDetail() async {
    setState(() => _isLoadingDetail = true);

    final result = await _ticketService.getTicketDetail(widget.ticket.id);

    if (mounted && result['success']) {
      final data = result['data'];
      setState(() {
        // Parse logs
        if (data['logs'] is List) {
          _logs = (data['logs'] as List).map<TicketReply>((log) {
            return TicketReply(
              id: log['id'] is int ? log['id'] : int.tryParse(log['id'].toString()) ?? 0,
              action: log['action'] ?? log['aksi'] ?? '',
              note: log['note'] ?? log['catatan'] ?? '',
              createdAt: DateTime.tryParse(log['createdAt'] ?? '') ?? DateTime.now(),
              adminName: log['adminName'],
              isFromAdmin: log['isFromAdmin'] ?? false,
              attachments: log['attachments'] is List
                  ? (log['attachments'] as List).map<Map<String, dynamic>>((a) => Map<String, dynamic>.from(a)).toList()
                  : null,
            );
          }).toList();
        }

        // Parse attachments
        if (data['attachments'] is List) {
          _attachments = (data['attachments'] as List)
              .map<Map<String, dynamic>>((a) => Map<String, dynamic>.from(a))
              .toList();
        }

        // Parse form fields
        if (data['formFields'] is List) {
          _formFields = (data['formFields'] as List)
              .map<Map<String, dynamic>>((f) => Map<String, dynamic>.from(f))
              .toList();
        }

        // Parse form data
        if (data['formData'] is Map) {
          _formData = Map<String, dynamic>.from(data['formData']);
        }

        // CSAT
        _csatData = data['csat'] is Map ? Map<String, dynamic>.from(data['csat']) : null;

        // Revision info
        _maxRevisions = data['maxRevisions'] ?? 5;
        _revisionCount = data['revisionCount'] ?? 0;
        _isResultAccepted = data['isResultAccepted'] ?? false;
        _isRevisionEnabled = data['isRevisionEnabled'] ?? false;

        // Update the current ticket with latest status
        final newStatus = _parseStatus(data['status'] ?? _currentTicket.status.name);
        _currentTicket = _currentTicket.copyWith(
          status: newStatus,
          logs: _logs,
          assignedTo: data['assignedTo'],
        );

        _isLoadingDetail = false;
      });
    } else {
      if (mounted) {
        setState(() => _isLoadingDetail = false);
      }
    }
  }

  TicketStatus _parseStatus(String status) {
    switch (status.toLowerCase()) {
      case 'open': return TicketStatus.open;
      case 'on_proses': return TicketStatus.processing;
      case 'solve': return TicketStatus.solved;
      case 'reject': return TicketStatus.rejected;
      case 'dibatalkan': return TicketStatus.cancelled;
      case 'need_revision': return TicketStatus.needRevision;
      default: return TicketStatus.open;
    }
  }

  void _scrollToBottom() {
    Future.delayed(const Duration(milliseconds: 300), () {
      if (_chatScrollController.hasClients) {
        _chatScrollController.animateTo(
          _chatScrollController.position.maxScrollExtent,
          duration: const Duration(milliseconds: 300),
          curve: Curves.easeOut,
        );
      }
    });
  }

  Future<void> _sendMessage(String text) async {
    final message = text.trim().isEmpty && _replyAttachments.isNotEmpty ? '[Lampiran]' : text.trim();
    if (message.isEmpty && _replyAttachments.isEmpty) return;
    if (_isSending) return;

    setState(() => _isSending = true);
    final result = await _ticketService.replyTicket(
      widget.ticket.id, 
      message,
      attachments: _replyAttachments.isNotEmpty ? _replyAttachments : null,
    );
    if (result['success']) {
      _messageController.clear();
      _replyAttachments.clear();
      _chatFocusNode.unfocus();
      // Reload the full detail to get the latest logs
      await _loadTicketDetail();
      _scrollToBottom();
    } else {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(result['message'])));
      }
    }
    if (mounted) setState(() => _isSending = false);
  }

  Future<void> _cancelTicket() async {
    final confirmed = await _showConfirmDialog(
      'Batalkan Tiket',
      'Apakah Anda yakin ingin membatalkan tiket ini? Tindakan ini tidak dapat dibatalkan.',
      confirmText: 'Ya, Batalkan',
      confirmColor: Colors.red,
    );
    if (confirmed != true) return;

    setState(() => _isActionLoading = true);
    final result = await _ticketService.cancelTicket(widget.ticket.id);
    if (mounted) {
      setState(() => _isActionLoading = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(result['message'] ?? (result['success'] ? 'Tiket dibatalkan' : 'Gagal membatalkan')),
          backgroundColor: result['success'] ? AppTheme.success : Colors.red,
        ),
      );
      if (result['success']) {
        ref.read(userTicketProvider.notifier).refresh();
        await _loadTicketDetail();
      }
    }
  }

  Future<void> _acceptResult() async {
    final confirmed = await _showConfirmDialog(
      'Terima Solusi',
      'Apakah Anda sudah puas dengan solusi yang diberikan? Setelah menerima, Anda akan diminta memberi rating.',
      confirmText: 'Ya, Terima',
      confirmColor: AppTheme.success,
    );
    if (confirmed != true) return;

    setState(() => _isActionLoading = true);
    final result = await _ticketService.acceptResult(widget.ticket.id);
    if (mounted) {
      setState(() => _isActionLoading = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(result['message'] ?? (result['success'] ? 'Hasil diterima' : 'Gagal menerima')),
          backgroundColor: result['success'] ? AppTheme.success : Colors.red,
        ),
      );
      if (result['success']) {
        // Show rating dialog
        _showCsatBottomSheet();
        ref.read(userTicketProvider.notifier).refresh();
        await _loadTicketDetail();
      }
    }
  }

  Future<void> _requestRevision() async {
    final controller = TextEditingController();
    final note = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Minta Revisi', style: TextStyle(fontWeight: FontWeight.bold)),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text('Revisi ke-${_revisionCount + 1} dari $_maxRevisions', style: TextStyle(color: Colors.grey.shade600, fontSize: 13)),
            const SizedBox(height: 16),
            TextField(
              controller: controller,
              maxLines: 3,
              decoration: InputDecoration(
                hintText: 'Jelaskan alasan revisi...',
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
          ElevatedButton(
            onPressed: () {
              if (controller.text.trim().isEmpty) {
                ScaffoldMessenger.of(ctx).showSnackBar(
                  const SnackBar(content: Text('Alasan revisi wajib diisi')),
                );
                return;
              }
              Navigator.pop(ctx, controller.text.trim());
            },
            style: ElevatedButton.styleFrom(backgroundColor: AppTheme.brilliantBlue),
            child: const Text('Kirim Revisi', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );

    if (note == null || note.isEmpty) return;

    setState(() => _isActionLoading = true);
    final result = await _ticketService.requestRevision(widget.ticket.id, note);
    if (mounted) {
      setState(() => _isActionLoading = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(result['message'] ?? (result['success'] ? 'Permintaan revisi terkirim' : 'Gagal')),
          backgroundColor: result['success'] ? AppTheme.success : Colors.red,
        ),
      );
      if (result['success']) {
        ref.read(userTicketProvider.notifier).refresh();
        await _loadTicketDetail();
      }
    }
  }

  void _showCsatBottomSheet() {
    int selectedRating = 5;
    final commentController = TextEditingController();
    bool isSubmitting = false;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheetState) => Container(
          decoration: const BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.only(topLeft: Radius.circular(24), topRight: Radius.circular(24)),
          ),
          padding: EdgeInsets.only(
            bottom: MediaQuery.of(ctx).viewInsets.bottom + 24,
            top: 16,
            left: 24,
            right: 24,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 40,
                  height: 4,
                  decoration: BoxDecoration(color: Colors.grey.shade300, borderRadius: BorderRadius.circular(4)),
                ),
              ),
              const SizedBox(height: 24),
              const Text('Penilaian Layanan', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: Color(0xFF1E293B)), textAlign: TextAlign.center),
              const SizedBox(height: 8),
              Text('Seberapa puas Anda dengan pelayanan tiket ini?', style: TextStyle(fontSize: 14, color: Colors.grey.shade600), textAlign: TextAlign.center),
              const SizedBox(height: 24),
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: List.generate(5, (i) {
                  return GestureDetector(
                    onTap: () => setSheetState(() => selectedRating = i + 1),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 6),
                      child: AnimatedScale(
                        scale: i < selectedRating ? 1.1 : 1.0,
                        duration: const Duration(milliseconds: 200),
                        child: Icon(
                          i < selectedRating ? Icons.star_rounded : Icons.star_border_rounded,
                          color: i < selectedRating ? const Color(0xFFF59E0B) : Colors.grey.shade300,
                          size: 44,
                        ),
                      ),
                    ),
                  );
                }),
              ),
              const SizedBox(height: 24),
              TextField(
                controller: commentController,
                maxLines: 3,
                decoration: InputDecoration(
                  hintText: 'Tambahkan komentar opsional (kritik/saran)...',
                  hintStyle: TextStyle(color: Colors.grey.shade400, fontSize: 14),
                  filled: true,
                  fillColor: const Color(0xFFF8FAFC),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide.none),
                  contentPadding: const EdgeInsets.all(16),
                ),
              ),
              const SizedBox(height: 24),
              ElevatedButton(
                onPressed: isSubmitting ? null : () async {
                  setSheetState(() => isSubmitting = true);
                  final result = await _ticketService.rateTicket(
                    widget.ticket.id,
                    selectedRating,
                    comment: commentController.text.trim(),
                  );
                  setSheetState(() => isSubmitting = false);
                  if (ctx.mounted) Navigator.pop(ctx);
                  
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text(result['message'] ?? 'Penilaian berhasil dikirim!'),
                        backgroundColor: result['success'] ? const Color(0xFF10B981) : Colors.red,
                        behavior: SnackBarBehavior.floating,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      ),
                    );
                    if (result['success']) {
                      ref.read(userTicketProvider.notifier).refresh();
                      await _loadTicketDetail();
                    }
                  }
                },
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF0066FF),
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  elevation: 0,
                ),
                child: isSubmitting
                    ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                    : const Text('Kirim Penilaian', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<bool?> _showConfirmDialog(String title, String message, {String confirmText = 'Ya', Color confirmColor = AppTheme.oceanWater}) {
    return showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.bold)),
        content: Text(message),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(backgroundColor: confirmColor),
            child: Text(confirmText, style: const TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
  }

  @override
  void dispose() {
    _tabController.dispose();
    _chatScrollController.dispose();
    _chatFocusNode.dispose();
    _messageController.dispose();
    super.dispose();
  }

  Future<void> _pickReplyAttachment(String type) async {
    Navigator.pop(context); // Close bottom sheet
    if (_replyAttachments.length >= 3) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Maksimal 3 lampiran!')));
      return;
    }

    Future<void> process(XFile f) async {
      final len = await f.length();
      if (len > 3 * 1024 * 1024) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Ukuran file maksimal 3MB!')));
        }
        return;
      }
      setState(() => _replyAttachments.add(f));
    }

    if (type == 'kamera' || type == 'galeri') {
      final picker = ImagePicker();
      final source = type == 'kamera' ? ImageSource.camera : ImageSource.gallery;
      final image = await picker.pickImage(source: source, maxWidth: 1920, imageQuality: 85);
      if (image != null) {
        if (mounted) {
          final shouldDoodle = await showDialog<bool>(
            context: context,
            builder: (ctx) => AlertDialog(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
              title: const Row(
                children: [
                  Icon(Icons.draw_rounded, color: AppTheme.oceanWater),
                  SizedBox(width: 8),
                  Text('Tandai / Coret Foto?', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                ],
              ),
              content: const Text(
                'Apakah Anda ingin mencoret atau menandai foto sebelum dikirim ke balasan tiket?',
                style: TextStyle(fontSize: 13),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(ctx, false),
                  child: const Text('Langsung Lampirkan', style: TextStyle(color: Colors.grey)),
                ),
                ElevatedButton.icon(
                  onPressed: () => Navigator.pop(ctx, true),
                  icon: const Icon(Icons.draw_rounded, size: 16),
                  label: const Text('Coret & Tandai'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppTheme.oceanWater,
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                  ),
                ),
              ],
            ),
          );

          if (shouldDoodle == true && mounted) {
            final annotated = await ImageDoodleScreen.annotate(context, image);
            if (annotated != null) {
              await process(annotated);
              return;
            }
          }
        }
        await process(image);
      }
    } else {
      final result = await FilePicker.pickFiles(
        type: FileType.custom,
        allowedExtensions: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'],
        allowMultiple: false,
        withData: true,
      );
      if (result != null) {
        final platformFile = result.files.single;
        if (platformFile.bytes != null) {
          await process(XFile.fromData(platformFile.bytes!, name: platformFile.name));
        } else if (platformFile.path != null) {
          await process(XFile(platformFile.path!));
        }
      }
    }
  }

  void _showAttachmentOptions(BuildContext context) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (BuildContext ctx) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 24, horizontal: 16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 40, height: 4,
                  margin: const EdgeInsets.only(bottom: 24),
                  decoration: BoxDecoration(color: Colors.grey.shade300, borderRadius: BorderRadius.circular(2)),
                ),
                ListTile(
                  leading: Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(color: const Color(0xFFE0F2FE), borderRadius: BorderRadius.circular(12)),
                    child: const Icon(Icons.camera_alt, color: Color(0xFF0284C7)),
                  ),
                  title: const Text('Kamera', style: TextStyle(fontWeight: FontWeight.bold)),
                  subtitle: const Text('Ambil foto langsung', style: TextStyle(fontSize: 12)),
                  onTap: () => _pickReplyAttachment('kamera'),
                ),
                ListTile(
                  leading: Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(color: const Color(0xFFFCE7F3), borderRadius: BorderRadius.circular(12)),
                    child: const Icon(Icons.photo_library, color: Color(0xFFDB2777)),
                  ),
                  title: const Text('Galeri Foto', style: TextStyle(fontWeight: FontWeight.bold)),
                  subtitle: const Text('Pilih foto dari perangkat', style: TextStyle(fontSize: 12)),
                  onTap: () => _pickReplyAttachment('galeri'),
                ),
                ListTile(
                  leading: Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(color: const Color(0xFFFEF3C7), borderRadius: BorderRadius.circular(12)),
                    child: const Icon(Icons.insert_drive_file, color: Color(0xFFD97706)),
                  ),
                  title: const Text('Dokumen', style: TextStyle(fontWeight: FontWeight.bold)),
                  subtitle: const Text('Kirim file PDF atau dokumen', style: TextStyle(fontSize: 12)),
                  onTap: () => _pickReplyAttachment('dokumen'),
                ),
              ],
            ),
          ),
        );
      }
    );
  }

  @override
  Widget build(BuildContext context) {
    final isKeyboardOpen = MediaQuery.of(context).viewInsets.bottom > 0;
    final canReply = !['solve', 'selesai', 'reject', 'dibatalkan'].contains(_currentTicket.status.name.toLowerCase()) &&
        _currentTicket.status != TicketStatus.solved &&
        _currentTicket.status != TicketStatus.rejected &&
        _currentTicket.status != TicketStatus.cancelled;

    return Scaffold(
      backgroundColor: const Color(0xFFF7FAFC),
      extendBodyBehindAppBar: true,
      appBar: PreferredSize(
        preferredSize: const Size.fromHeight(64),
        child: ClipRRect(
          child: BackdropFilter(
            filter: ImageFilter.blur(sigmaX: 12, sigmaY: 12),
            child: AppBar(
              backgroundColor: const Color(0xFFF7FAFC).withValues(alpha: 0.8),
              elevation: 0,
              centerTitle: true,
              leading: IconButton(
                icon: const Icon(Icons.arrow_back, color: AppTheme.oceanWater),
                onPressed: () => Navigator.of(context).pop(),
              ),
              title: Text(
                'Detail Tiket #${_currentTicket.id}',
                style: const TextStyle(
                  color: AppTheme.oceanWater,
                  fontWeight: FontWeight.bold,
                  fontSize: 18,
                ),
              ),
              actions: [
                if (_currentTicket.status == TicketStatus.open)
                  PopupMenuButton<String>(
                    icon: const Icon(Icons.more_vert, color: AppTheme.oceanWater),
                    onSelected: (value) {
                      if (value == 'cancel') _cancelTicket();
                    },
                    itemBuilder: (ctx) => [
                      const PopupMenuItem(value: 'cancel', child: Row(
                        children: [
                          Icon(Icons.cancel_outlined, color: Colors.red, size: 20),
                          SizedBox(width: 8),
                          Text('Batalkan Tiket', style: TextStyle(color: Colors.red)),
                        ],
                      )),
                    ],
                  )
                else
                  IconButton(
                    icon: const Icon(Icons.refresh, color: AppTheme.oceanWater),
                    onPressed: _loadTicketDetail,
                  ),
              ],
            ),
          ),
        ),
      ),
      body: _isLoadingDetail
          ? const Center(child: CircularProgressIndicator(color: AppTheme.oceanWater))
          : NestedScrollView(
              headerSliverBuilder: (context, innerBoxIsScrolled) {
                return [
                  SliverToBoxAdapter(
                    child: Column(
                      children: [
                        const SizedBox(height: 80),
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
                          child: TicketStatusTimeline(ticket: _currentTicket),
                        ),
                      ],
                    ),
                  ),
                  SliverPersistentHeader(
                    pinned: true,
                    delegate: _StickyTabBarDelegate(
                      TabBar(
                        controller: _tabController,
                        indicatorColor: AppTheme.oceanWater,
                        labelColor: AppTheme.oceanWater,
                        unselectedLabelColor: Colors.grey.shade600,
                        labelStyle: const TextStyle(fontWeight: FontWeight.bold),
                        unselectedLabelStyle: const TextStyle(fontWeight: FontWeight.w500),
                        tabs: [
                          const Tab(text: 'Chat'),
                          Tab(text: 'Lampiran (${_attachments.length})'),
                          const Tab(text: 'Detail'),
                        ],
                      ),
                    ),
                  ),
                ];
              },
              body: TabBarView(
                controller: _tabController,
                children: [
                  _buildChatTab(),
                  _buildAttachmentTab(),
                  _buildDetailTab(),
                ],
              ),
            ),
      bottomNavigationBar: _isLoadingDetail ? null : _buildBottomActionArea(context, isKeyboardOpen, canReply),
    );
  }

  Widget _buildDetailTab() {
    final activeFormFields = _formFields.where((field) {
      final val = _formData[field['id'].toString()];
      if (val == null || val.toString().trim().isEmpty) return false;
      return true;
    }).toList();

    return ListView(
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
      children: [
        // Card 1: Informasi Utama
        Container(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: const Color(0xFFE2E8F0)),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.02),
                blurRadius: 10,
                offset: const Offset(0, 3),
              ),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Row(
                    children: [
                      Icon(Icons.info_outline_rounded, size: 16, color: Color(0xFF00768C)),
                      SizedBox(width: 6),
                      Text(
                        'INFORMASI UTAMA',
                        style: TextStyle(
                          fontSize: 11.5,
                          fontWeight: FontWeight.w700,
                          letterSpacing: 0.8,
                          color: Color(0xFF475569),
                        ),
                      ),
                    ],
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF1F5F9),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(
                      '#${_currentTicket.id}',
                      style: const TextStyle(
                        fontFamily: 'monospace',
                        fontWeight: FontWeight.bold,
                        fontSize: 11,
                        color: Color(0xFF475569),
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              Text(
                _currentTicket.title,
                style: const TextStyle(
                  fontSize: 17,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF0F172A),
                  letterSpacing: -0.3,
                ),
              ),
              const SizedBox(height: 14),
              const Divider(height: 1, color: Color(0xFFF1F5F9)),
              const SizedBox(height: 14),
              _buildDetailRow(Icons.category_outlined, 'Kategori Layanan', _currentTicket.category),
              const SizedBox(height: 12),
              _buildDetailRow(Icons.person_outline_rounded, 'Nama Pemohon', _currentTicket.requesterName.isNotEmpty ? _currentTicket.requesterName : '-'),
              const SizedBox(height: 12),
              _buildDetailRow(Icons.engineering_outlined, 'Ditugaskan Ke', _currentTicket.assignedTo ?? 'Belum ada teknisi'),
              const SizedBox(height: 12),
              _buildDetailRow(Icons.calendar_today_outlined, 'Waktu Diajukan', DateFormat('dd MMMM yyyy, HH:mm').format(_currentTicket.createdAt)),
            ],
          ),
        ),

        const SizedBox(height: 14),

        // Card 2: Rincian Formulir
        if (activeFormFields.isNotEmpty)
          Container(
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: const Color(0xFFE2E8F0)),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.02),
                  blurRadius: 10,
                  offset: const Offset(0, 3),
                ),
              ],
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Row(
                  children: [
                    Icon(Icons.assignment_outlined, size: 16, color: Color(0xFF00768C)),
                    SizedBox(width: 6),
                    Text(
                      'RINCIAN FORMULIR',
                      style: TextStyle(
                        fontSize: 11.5,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 0.8,
                        color: Color(0xFF475569),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 14),
                ...activeFormFields.asMap().entries.map((entry) {
                  final idx = entry.key;
                  final field = entry.value;
                  final val = _formData[field['id'].toString()]?.toString() ?? '-';
                  final label = field['label']?.toString() ?? 'Field';
                  final isLast = idx == activeFormFields.length - 1;

                  return Padding(
                    padding: EdgeInsets.only(bottom: isLast ? 0 : 12),
                    child: Container(
                      width: double.infinity,
                      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                      decoration: BoxDecoration(
                        color: const Color(0xFFF8FAFC),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: const Color(0xFFE2E8F0)),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            label,
                            style: const TextStyle(
                              fontSize: 11.5,
                              fontWeight: FontWeight.w600,
                              color: Color(0xFF64748B),
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            val,
                            style: const TextStyle(
                              fontSize: 13.5,
                              fontWeight: FontWeight.w700,
                              color: Color(0xFF1E293B),
                            ),
                          ),
                        ],
                      ),
                    ),
                  );
                }),
              ],
            ),
          )
        else if (_currentTicket.summaryItems.isNotEmpty)
          Container(
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: const Color(0xFFE2E8F0)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Row(
                  children: [
                    Icon(Icons.assignment_outlined, size: 16, color: Color(0xFF00768C)),
                    SizedBox(width: 6),
                    Text(
                      'RINCIAN FORMULIR',
                      style: TextStyle(
                        fontSize: 11.5,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 0.8,
                        color: Color(0xFF475569),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 14),
                ..._currentTicket.summaryItems.map((item) {
                  return Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: Container(
                      width: double.infinity,
                      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                      decoration: BoxDecoration(
                        color: const Color(0xFFF8FAFC),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: const Color(0xFFE2E8F0)),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          if (item.key.isNotEmpty)
                            Text(
                              item.key,
                              style: const TextStyle(
                                fontSize: 11.5,
                                fontWeight: FontWeight.w600,
                                color: Color(0xFF64748B),
                              ),
                            ),
                          const SizedBox(height: 4),
                          Text(
                            item.value,
                            style: const TextStyle(
                              fontSize: 13.5,
                              fontWeight: FontWeight.w700,
                              color: Color(0xFF1E293B),
                            ),
                          ),
                        ],
                      ),
                    ),
                  );
                }),
              ],
            ),
          ),

        // Card 3: Penilaian CSAT
        if (_csatData != null) ...[
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              color: const Color(0xFFFFFBEB),
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: const Color(0xFFFDE68A)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Row(
                  children: [
                    Icon(Icons.star_rounded, size: 18, color: Color(0xFFD97706)),
                    SizedBox(width: 6),
                    Text(
                      'PENILAIAN KEPUASAN (CSAT)',
                      style: TextStyle(
                        fontSize: 11.5,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 0.8,
                        color: Color(0xFF92400E),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    ...List.generate(5, (index) {
                      final ratingVal = (_csatData!['rating'] is int ? _csatData!['rating'] : int.tryParse(_csatData!['rating'].toString())) ?? 0;
                      return Icon(
                        index < ratingVal ? Icons.star_rounded : Icons.star_border_rounded,
                        color: const Color(0xFFF59E0B),
                        size: 24,
                      );
                    }),
                    const SizedBox(width: 8),
                    Text(
                      '${_csatData!['rating']}/5',
                      style: const TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 14,
                        color: Color(0xFF92400E),
                      ),
                    ),
                  ],
                ),
                if (_csatData!['comment'] != null && _csatData!['comment'].toString().isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Text(
                    '"${_csatData!['comment']}"',
                    style: const TextStyle(
                      fontStyle: FontStyle.italic,
                      fontSize: 12.5,
                      color: Color(0xFF78350F),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
        const SizedBox(height: 20),
      ],
    );
  }

  Widget _buildDetailRow(IconData icon, String label, String value) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 16, color: const Color(0xFF64748B)),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: const TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w500,
                  color: Color(0xFF64748B),
                ),
              ),
              const SizedBox(height: 2),
              Text(
                value,
                style: const TextStyle(
                  fontSize: 13.5,
                  fontWeight: FontWeight.w600,
                  color: Color(0xFF1E293B),
                ),
              ),
            ],
          ),
        ),
      ],
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

  void _showImagePreview(String url, String title) {
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

  Widget _buildAttachmentTab() {
    if (_attachments.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.attach_file, size: 48, color: Colors.grey.shade400),
            const SizedBox(height: 12),
            Text('Belum ada lampiran pada tiket ini', style: TextStyle(color: Colors.grey.shade500)),
          ],
        ),
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.all(20),
      itemCount: _attachments.length,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (context, index) {
        final att = _attachments[index];
        String fileName = (att['fileName'] ?? att['original_name'] ?? 'Lampiran').toString();
        final mimeType = att['mimeType'] ?? att['mime_type'] ?? '';
        if (!fileName.contains('.')) {
          final ext = mimeType.toString().contains('png') ? 'png' : (mimeType.toString().contains('pdf') ? 'pdf' : 'jpg');
          fileName = '$fileName.$ext';
        }
        final isImage = mimeType.toString().startsWith('image') ||
            ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].contains(fileName.split('.').last.toLowerCase());
        final url = _getAttachmentUrl(att);

        return InkWell(
          onTap: () async {
            if (url.isNotEmpty) {
              if (isImage) {
                _showImagePreview(url, fileName.toString());
              } else {
                final uri = Uri.parse(url);
                if (await canLaunchUrl(uri)) await launchUrl(uri, mode: LaunchMode.externalApplication);
              }
            }
          },
          borderRadius: BorderRadius.circular(16),
          child: Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: const Color(0xFFE2E8F0)),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.02),
                  blurRadius: 8,
                  offset: const Offset(0, 2),
                ),
              ],
            ),
            child: Row(
              children: [
                if (isImage && url.isNotEmpty)
                  ClipRRect(
                    borderRadius: BorderRadius.circular(10),
                    child: Image.network(
                      url,
                      width: 48,
                      height: 48,
                      fit: BoxFit.cover,
                      errorBuilder: (_, __, ___) => Container(
                        width: 48,
                        height: 48,
                        color: Colors.blue.shade50,
                        child: const Icon(Icons.image, color: Colors.blue, size: 24),
                      ),
                    ),
                  )
                else
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: isImage ? Colors.blue.shade50 : Colors.amber.shade50,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Icon(
                      isImage ? Icons.image_rounded : Icons.insert_drive_file_rounded,
                      color: isImage ? Colors.blue : Colors.amber.shade800,
                      size: 24,
                    ),
                  ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        fileName.toString(),
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5, color: Color(0xFF1E293B)),
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 2),
                      Text(
                        att['fileSize'] != null ? '${(int.tryParse(att['fileSize'].toString()) ?? 0) ~/ 1024} KB' : (mimeType.toString().isNotEmpty ? mimeType.toString() : 'Dokumen'),
                        style: TextStyle(color: Colors.grey.shade500, fontSize: 11.5),
                      ),
                    ],
                  ),
                ),
                const Icon(Icons.open_in_new_rounded, size: 18, color: Color(0xFF64748B)),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildChatTab() {
    return ListView(
      controller: _chatScrollController,
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
      children: [
        Center(
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
            decoration: BoxDecoration(color: Colors.grey.shade200, borderRadius: BorderRadius.circular(16)),
            child: const Text('Riwayat Chat', style: TextStyle(fontSize: 10, color: Colors.black54, fontWeight: FontWeight.w500)),
          ),
        ),
        const SizedBox(height: 16),
        if (_logs.isNotEmpty)
          ..._logs.reversed.map((reply) => ChatBubble(
            reply: reply,
            isCurrentUserAdmin: false,
            requesterName: widget.ticket.requesterName,
          ))
        else
          Container(
            padding: const EdgeInsets.all(32.0),
            alignment: Alignment.center,
            child: const Text('Belum ada pesan', style: TextStyle(color: Colors.grey)),
          ),
      ],
    );
  }

  Widget _buildBottomActionArea(BuildContext context, bool isKeyboardOpen, bool canReply) {
    final isSolved = _currentTicket.status == TicketStatus.solved;
    final isClosed = _currentTicket.status == TicketStatus.cancelled ||
        _currentTicket.status == TicketStatus.rejected;

    return AnimatedContainer(
      duration: const Duration(milliseconds: 250),
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        top: 16,
        bottom: MediaQuery.of(context).viewInsets.bottom > 0 ? 16 : (MediaQuery.of(context).padding.bottom + 16),
      ),
      decoration: BoxDecoration(
        color: const Color(0xFFF7FAFC).withValues(alpha: 0.95),
        border: Border(top: BorderSide(color: Colors.grey.shade200)),
        boxShadow: [
          BoxShadow(color: Colors.black.withValues(alpha: 0.05), blurRadius: 18, offset: const Offset(0, -4)),
        ],
      ),
      child: Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (_replyAttachments.isNotEmpty)
              Container(
                margin: const EdgeInsets.only(bottom: 8),
                height: 60,
                child: ListView.builder(
                  scrollDirection: Axis.horizontal,
                  itemCount: _replyAttachments.length,
                  itemBuilder: (ctx, i) {
                    final file = _replyAttachments[i];
                    return Container(
                      margin: const EdgeInsets.only(right: 8),
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8), border: Border.all(color: Colors.grey.shade300)),
                      child: Row(
                        children: [
                          const Icon(Icons.insert_drive_file, size: 20, color: AppTheme.oceanWater),
                          const SizedBox(width: 4),
                          if (file.name.toLowerCase().endsWith('.jpg') ||
                              file.name.toLowerCase().endsWith('.jpeg') ||
                              file.name.toLowerCase().endsWith('.png')) ...[
                            IconButton(
                              icon: const Icon(Icons.draw_rounded, size: 16, color: AppTheme.oceanWater),
                              padding: EdgeInsets.zero,
                              constraints: const BoxConstraints(),
                              tooltip: 'Coret & Tandai Foto',
                              onPressed: () async {
                                final annotated = await ImageDoodleScreen.annotate(context, file);
                                if (annotated != null && mounted) {
                                  setState(() {
                                    _replyAttachments[i] = annotated;
                                  });
                                }
                              },
                            ),
                            const SizedBox(width: 4),
                          ],
                          IconButton(
                            icon: const Icon(Icons.close, size: 16),
                            padding: EdgeInsets.zero,
                            constraints: const BoxConstraints(),
                            onPressed: () => setState(() => _replyAttachments.removeAt(i)),
                          )
                        ],
                      ),
                    );
                  }
                )
              ),
            // Chat input (only if ticket is not closed)
            if (canReply && !isSolved)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(30),
                  border: Border.all(color: Colors.grey.shade300),
                ),
                child: Row(
                  children: [
                    IconButton(
                      icon: Icon(Icons.attach_file, color: Colors.grey.shade600),
                      onPressed: () => _showAttachmentOptions(context),
                    ),
                    Expanded(
                      child: TextField(
                        controller: _messageController,
                        focusNode: _chatFocusNode,
                        onTap: _scrollToBottom,
                        decoration: const InputDecoration(
                          hintText: 'Ketik pesan...',
                          border: InputBorder.none,
                          isDense: true,
                        ),
                      ),
                    ),
                    Container(
                      margin: const EdgeInsets.only(right: 4),
                      decoration: const BoxDecoration(color: AppTheme.oceanWater, shape: BoxShape.circle),
                      child: IconButton(
                        icon: _isSending
                            ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                            : const Icon(Icons.send, color: Colors.white, size: 20),
                        onPressed: _isSending ? null : () {
                          if (_messageController.text.isNotEmpty || _replyAttachments.isNotEmpty) {
                            _sendMessage(_messageController.text);
                          } else {
                            _chatFocusNode.unfocus();
                          }
                        },
                      ),
                    ),
                  ],
                ),
              ),

            // Closed ticket message
            if (isClosed)
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.grey.shade100,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(Icons.info_outline, size: 16, color: Colors.grey.shade600),
                    const SizedBox(width: 8),
                    Text('Tiket sudah ditutup', style: TextStyle(color: Colors.grey.shade600, fontSize: 13)),
                  ],
                ),
              ),

            // Action Buttons for Solved tickets
            if (isSolved && !_isResultAccepted)
              AnimatedCrossFade(
                duration: const Duration(milliseconds: 200),
                crossFadeState: isKeyboardOpen ? CrossFadeState.showFirst : CrossFadeState.showSecond,
                firstChild: const SizedBox(height: 0, width: double.infinity),
                secondChild: Column(
                  children: [
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        if (_isRevisionEnabled && _revisionCount < _maxRevisions)
                          Expanded(
                            child: OutlinedButton(
                              onPressed: _isActionLoading ? null : _requestRevision,
                              style: OutlinedButton.styleFrom(
                                padding: const EdgeInsets.symmetric(vertical: 14),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                side: const BorderSide(color: AppTheme.brilliantBlue, width: 1.5),
                                foregroundColor: AppTheme.brilliantBlue,
                              ),
                              child: const Text('Minta Revisi', style: TextStyle(fontWeight: FontWeight.bold)),
                            ),
                          ),
                        if (_isRevisionEnabled && _revisionCount < _maxRevisions)
                          const SizedBox(width: 12),
                        Expanded(
                          child: ElevatedButton.icon(
                            onPressed: _isActionLoading ? null : _acceptResult,
                            icon: _isActionLoading
                                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                                : const Icon(Icons.check_circle, size: 18),
                            label: const Text('Terima Solusi', style: TextStyle(fontWeight: FontWeight.bold)),
                            style: ElevatedButton.styleFrom(
                              padding: const EdgeInsets.symmetric(vertical: 14),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                              backgroundColor: AppTheme.success,
                              foregroundColor: Colors.white,
                              elevation: 0,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),

            // Already accepted message or Solved / Closed ticket CSAT Button
            if ((isSolved || isClosed) && _csatData == null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: SizedBox(
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    onPressed: _showCsatBottomSheet,
                    icon: const Icon(Icons.star_rounded, size: 20),
                    label: const Text('Beri Penilaian (CSAT)', style: TextStyle(fontWeight: FontWeight.bold)),
                    style: ElevatedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      backgroundColor: const Color(0xFFF59E0B),
                      foregroundColor: Colors.white,
                      elevation: 0,
                    ),
                  ),
                ),
              ),

            // CSAT Result Card (if already rated)
            if (_csatData != null)
              Container(
                margin: const EdgeInsets.only(top: 16),
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [Color(0xFFFFFBEB), Colors.white],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: const Color(0xFFFDE68A)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text('Penilaian Anda', style: TextStyle(fontWeight: FontWeight.bold, color: Color(0xFFD97706))),
                        Row(
                          children: List.generate(5, (index) {
                            final score = _csatData!['rating'] is int ? _csatData!['rating'] : int.tryParse(_csatData!['rating']?.toString() ?? '0') ?? 0;
                            return Icon(
                              index < score ? Icons.star_rounded : Icons.star_border_rounded,
                              color: index < score ? const Color(0xFFF59E0B) : Colors.grey.shade300,
                              size: 18,
                            );
                          }),
                        ),
                      ],
                    ),
                    if (_csatData!['comment'] != null && _csatData!['comment'].toString().trim().isNotEmpty) ...[
                      const SizedBox(height: 8),
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: Colors.white.withValues(alpha: 0.5),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          '"${_csatData!['comment']}"',
                          style: const TextStyle(fontStyle: FontStyle.italic, color: Color(0xFF475569), fontSize: 13),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _StickyTabBarDelegate extends SliverPersistentHeaderDelegate {
  final TabBar tabBar;

  _StickyTabBarDelegate(this.tabBar);

  @override
  double get minExtent => tabBar.preferredSize.height;
  @override
  double get maxExtent => tabBar.preferredSize.height;

  @override
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) {
    return Container(
      color: const Color(0xFFF7FAFC).withValues(alpha: 0.95),
      child: tabBar,
    );
  }

  @override
  bool shouldRebuild(_StickyTabBarDelegate oldDelegate) {
    return tabBar != oldDelegate.tabBar;
  }
}
