import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../core/theme/app_theme.dart';
import '../../../domain/models/notification_model.dart';
import '../tickets/providers/user_ticket_provider.dart';
import '../tickets/providers/admin_ticket_provider.dart';
import '../widgets/entrance_animation.dart';
import '../widgets/pagination_footer.dart';
import '../widgets/shimmer.dart';
import 'providers/notification_provider.dart';

class NotificationScreen extends ConsumerStatefulWidget {
  final bool isAdmin;

  const NotificationScreen({super.key, this.isAdmin = false});

  @override
  ConsumerState<NotificationScreen> createState() => _NotificationScreenState();
}

class _NotificationScreenState extends ConsumerState<NotificationScreen> {
  final ScrollController _scrollController = ScrollController();
  bool _isLoadingMore = false;

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_onScroll);
  }

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  Future<void> _onScroll() async {
    final notifier = ref.read(notificationProvider.notifier);
    if (_isLoadingMore || !notifier.hasMore) return;
    if (_scrollController.position.pixels >=
        _scrollController.position.maxScrollExtent - 200) {
      setState(() => _isLoadingMore = true);
      await notifier.loadMore();
      if (mounted) setState(() => _isLoadingMore = false);
    }
  }

  void _deleteNotification(NotificationModel notification) {
    final notifier = ref.read(notificationProvider.notifier);
    notifier.remove(notification.id);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        behavior: SnackBarBehavior.floating,
        content: const Text('Notifikasi dihapus'),
        action: SnackBarAction(
          label: 'Batal',
          onPressed: () => notifier.restore(notification),
        ),
      ),
    );
  }

  void _openNotification(NotificationModel notification) {
    final notifier = ref.read(notificationProvider.notifier);
    notifier.markAsRead(notification.id);

    final ticketId = notification.ticketId;
    if (ticketId == null) return;
    final tickets = ref
        .read(widget.isAdmin ? adminTicketProvider : userTicketProvider)
        .value;
    final ticket = tickets?.where((t) => t.id == ticketId).firstOrNull;
    if (ticket != null) {
      context.push(
        widget.isAdmin ? '/tickets/admin/detail' : '/tickets/user/detail',
        extra: ticket,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final notifState = ref.watch(notificationProvider);
    final notifications = notifState.value ?? const <NotificationModel>[];
    final unreadCount = notifications.where((n) => !n.isRead).length;

    return Scaffold(
      backgroundColor: AppTheme.lightBg,
      appBar: AppBar(
        title: const Text('Notifikasi', style: TextStyle(fontWeight: FontWeight.bold)),
        elevation: 0,
        backgroundColor: Colors.white,
        foregroundColor: Colors.black,
        actions: [
          if (unreadCount > 0)
            TextButton(
              onPressed: () =>
                  ref.read(notificationProvider.notifier).markAllAsRead(),
              child: const Text(
                'Semua dibaca',
                style: TextStyle(
                  color: AppTheme.oceanWater,
                  fontWeight: FontWeight.bold,
                  fontSize: 13,
                ),
              ),
            ),
          const SizedBox(width: 8),
        ],
      ),
      body: notifState.when(
        loading: () => const _NotificationShimmerList(),
        error: (error, stack) => Center(child: Text('Error: $error')),
        data: (list) => RefreshIndicator(
          onRefresh: () => ref.read(notificationProvider.notifier).refresh(),
          color: AppTheme.oceanWater,
          child: list.isEmpty
              ? const _EmptyNotificationState()
              : _NotificationListView(
                  controller: _scrollController,
                  groups: _groupByDate(list),
                  isLoadingMore: _isLoadingMore,
                  hasMore: ref.read(notificationProvider.notifier).hasMore,
                  onDelete: _deleteNotification,
                  onTap: _openNotification,
                ),
        ),
      ),
    );
  }
}

class _NotificationListView extends StatelessWidget {
  final ScrollController controller;
  final List<({String label, List<NotificationModel> items})> groups;
  final bool isLoadingMore;
  final bool hasMore;
  final void Function(NotificationModel) onDelete;
  final void Function(NotificationModel) onTap;

  const _NotificationListView({
    required this.controller,
    required this.groups,
    required this.isLoadingMore,
    required this.hasMore,
    required this.onDelete,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    var index = 0;
    return ListView(
      controller: controller,
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
      children: [
        for (final group in groups) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 16, 8, 10),
            child: Text(
              group.label,
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.bold,
                color: Colors.grey.shade500,
                letterSpacing: 0.3,
              ),
            ),
          ),
          for (final item in group.items)
            EntranceAnimation(
              delay: Duration(milliseconds: index++ * 60),
              child: Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: _NotificationItem(
                  notification: item,
                  onDelete: onDelete,
                  onTap: onTap,
                ),
              ),
            ),
        ],
        PaginationFooter(isLoading: isLoadingMore, hasMore: hasMore),
      ],
    );
  }
}

class _NotificationItem extends StatelessWidget {
  final NotificationModel notification;
  final void Function(NotificationModel) onDelete;
  final void Function(NotificationModel) onTap;

  const _NotificationItem({
    required this.notification,
    required this.onDelete,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final isUnread = !notification.isRead;
    final visual = _typeVisual(notification.type);

    return Dismissible(
      key: ValueKey(notification.id),
      direction: DismissDirection.endToStart,
      onDismissed: (_) => onDelete(notification),
      background: Container(
        alignment: Alignment.centerRight,
        padding: const EdgeInsets.only(right: 24),
        decoration: BoxDecoration(
          color: AppTheme.danger,
          borderRadius: BorderRadius.circular(20),
        ),
        child: const Icon(Icons.delete_outline, color: Colors.white, size: 22),
      ),
      child: InkWell(
        onTap: () => onTap(notification),
        borderRadius: BorderRadius.circular(20),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 250),
          curve: Curves.easeOut,
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: isUnread
                ? AppTheme.oceanWater.withValues(alpha: 0.05)
                : Colors.white,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(
              color: isUnread
                  ? AppTheme.oceanWater.withValues(alpha: 0.25)
                  : Colors.grey.shade100,
            ),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: visual.$2.withValues(alpha: 0.1),
                  shape: BoxShape.circle,
                ),
                child: Icon(visual.$1, color: visual.$2, size: 22),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            notification.title,
                            style: TextStyle(
                              fontSize: 14,
                              fontWeight:
                                  isUnread ? FontWeight.bold : FontWeight.w600,
                              color: Colors.black87,
                            ),
                          ),
                        ),
                        if (isUnread)
                          Container(
                            width: 8,
                            height: 8,
                            decoration: const BoxDecoration(
                              color: AppTheme.oceanWater,
                              shape: BoxShape.circle,
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 5),
                    Text(
                      notification.body,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontSize: 12.5,
                        color: Colors.grey.shade600,
                        height: 1.4,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        Icon(
                          Icons.access_time,
                          size: 11,
                          color: Colors.grey.shade400,
                        ),
                        const SizedBox(width: 4),
                        Text(
                          _formatTime(notification.createdAt),
                          style: TextStyle(
                            fontSize: 11,
                            color: Colors.grey.shade400,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _NotificationShimmerList extends StatelessWidget {
  const _NotificationShimmerList();

  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
      children: [
        const ShimmerBox(width: 80, height: 14, radius: 6),
        const SizedBox(height: 12),
        for (var i = 0; i < 5; i++) ...[
          const ShimmerBox(height: 84, radius: 20),
          const SizedBox(height: 10),
        ],
      ],
    );
  }
}

class _EmptyNotificationState extends StatelessWidget {
  const _EmptyNotificationState();

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        return SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          child: SizedBox(
            height: constraints.maxHeight,
            child: const EntranceAnimation(
              child: Center(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(
                      Icons.notifications_none_rounded,
                      size: 72,
                      color: AppTheme.oceanWater,
                    ),
                    SizedBox(height: 16),
                    Text(
                      'Tidak ada notifikasi',
                      style: TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.bold,
                        color: Colors.black87,
                      ),
                    ),
                    SizedBox(height: 6),
                    Text(
                      'Notifikasi terbaru akan muncul di sini.',
                      style: TextStyle(fontSize: 13, color: Colors.grey),
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

List<({String label, List<NotificationModel> items})> _groupByDate(
  List<NotificationModel> list,
) {
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  final yesterday = today.subtract(const Duration(days: 1));
  final weekStart = today.subtract(const Duration(days: 6));

  final sorted = [...list]..sort((a, b) => b.createdAt.compareTo(a.createdAt));

  final groups = <({String label, List<NotificationModel> items})>[
    (label: 'Hari Ini', items: []),
    (label: 'Kemarin', items: []),
    (label: 'Minggu Ini', items: []),
    (label: 'Lainnya', items: []),
  ];

  for (final item in sorted) {
    final day = DateTime(item.createdAt.year, item.createdAt.month, item.createdAt.day);
    if (day == today) {
      groups[0].items.add(item);
    } else if (day == yesterday) {
      groups[1].items.add(item);
    } else if (day.isAfter(weekStart)) {
      groups[2].items.add(item);
    } else {
      groups[3].items.add(item);
    }
  }

  return groups.where((g) => g.items.isNotEmpty).toList();
}

(IconData, Color) _typeVisual(NotificationType type) {
  return switch (type) {
    NotificationType.reply => (Icons.chat_bubble_outline_rounded, AppTheme.oceanWater),
    NotificationType.solved => (Icons.check_circle_outline_rounded, AppTheme.success),
    NotificationType.rejected => (Icons.cancel_outlined, AppTheme.danger),
    NotificationType.csat => (Icons.star_outline_rounded, AppTheme.warning),
    NotificationType.sla => (Icons.timer_outlined, AppTheme.warning),
    NotificationType.revision => (Icons.refresh_rounded, AppTheme.brilliantBlue),
    NotificationType.ticket => (Icons.confirmation_number_outlined, AppTheme.brilliantBlue),
  };
}

String _formatTime(DateTime dateTime) {
  final now = DateTime.now();
  final diff = now.difference(dateTime);
  if (diff.inMinutes < 1) return 'Baru saja';
  if (diff.inMinutes < 60) return '${diff.inMinutes} mnt lalu';
  if (diff.inHours < 24) return '${diff.inHours} jam lalu';
  if (diff.inDays < 7) return '${diff.inDays} hari lalu';
  const months = [
    'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
    'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
  ];
  return '${dateTime.day} ${months[dateTime.month - 1]} ${dateTime.year}';
}
