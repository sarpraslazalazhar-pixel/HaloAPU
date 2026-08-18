import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:halo_apu_mobile/core/theme/app_theme.dart';
import 'package:halo_apu_mobile/domain/models/ticket_model.dart';
import 'package:halo_apu_mobile/domain/models/user_profile_model.dart';
import 'package:halo_apu_mobile/presentation/notifications/providers/notification_provider.dart';
import 'package:halo_apu_mobile/presentation/profile/providers/user_profile_provider.dart';
import 'package:halo_apu_mobile/presentation/widgets/custom_bottom_nav.dart';
import 'package:halo_apu_mobile/presentation/widgets/entrance_animation.dart';
import 'package:halo_apu_mobile/presentation/widgets/shimmer.dart';
import 'package:halo_apu_mobile/presentation/widgets/app_avatar.dart';
import 'package:halo_apu_mobile/presentation/widgets/app_permission_dialog.dart';
import 'package:halo_apu_mobile/core/services/push_notification_service.dart';
import 'package:halo_apu_mobile/data/repositories/dashboard_repository.dart';

class AdminDashboardScreen extends ConsumerStatefulWidget {
  const AdminDashboardScreen({super.key});

  @override
  ConsumerState<AdminDashboardScreen> createState() => _AdminDashboardScreenState();
}

class _AdminDashboardScreenState extends ConsumerState<AdminDashboardScreen> {
  DashboardRepository get _dashboardRepo => ref.read(dashboardRepositoryProvider);
  Map<String, dynamic>? _dashboardData;
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _fetchDashboardData();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      AppPermissionDialog.showIfRequired(context);
      PushNotificationService.syncFcmTokenWithBackend();
    });
  }

  Future<void> _fetchDashboardData() async {
    if (!mounted) return;
    setState(() {
      _isLoading = true;
    });

    ref.read(adminProfileProvider.notifier).refresh();
    ref.read(notificationProvider.notifier).refresh();
    final result = await _dashboardRepo.getDashboardData();

    if (mounted) {
      setState(() {
        _isLoading = false;
        if (result['success'] == true) {
          _dashboardData = result;
        }
      });

      if (result['statusCode'] == 401) {
        context.go('/login');
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final unreadCount = ref
            .watch(notificationProvider)
            .value
            ?.where((n) => !n.isRead)
            .length ??
        0;
    final adminProfile = ref.watch(adminProfileProvider);
    final isOffline = _dashboardData?['isOffline'] == true;

    return Scaffold(
      backgroundColor: AppTheme.lightBg,
      body: Stack(
        children: [
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            height: 300,
            child: Container(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [
                    AppTheme.oceanWater.withValues(alpha: 0.08),
                    AppTheme.lightBg.withValues(alpha: 0.0),
                  ],
                ),
              ),
            ),
          ),
          SafeArea(
            bottom: false,
            child: RefreshIndicator(
              onRefresh: _fetchDashboardData,
              color: AppTheme.oceanWater,
              displacement: 40,
              child: SingleChildScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.only(bottom: 110),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    EntranceAnimation(
                      child: _AdminHeader(
                        unreadCount: unreadCount,
                        profile: adminProfile,
                      ),
                    ),

                    if (isOffline)
                      Padding(
                        padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                          decoration: BoxDecoration(
                            color: const Color(0xFFFFFBEB),
                            borderRadius: BorderRadius.circular(12),
                            border: Border.all(color: const Color(0xFFFDE68A)),
                          ),
                          child: Row(
                            children: [
                              const Icon(Icons.wifi_off_rounded, size: 16, color: Color(0xFFD97706)),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  _dashboardData?['message'] ?? 'Mode Offline: Menampilkan data tersimpan',
                                  style: const TextStyle(
                                    fontSize: 11,
                                    color: Color(0xFFB45309),
                                    fontWeight: FontWeight.w500,
                                  ),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),

                    EntranceAnimation(
                      delay: const Duration(milliseconds: 90),
                      child: _isLoading && _dashboardData == null
                          ? const _AdminStatsSkeleton()
                          : _AdminStatsRow(stats: _dashboardData?['stats']),
                    ),
                    EntranceAnimation(
                      delay: const Duration(milliseconds: 180),
                      child: _buildHeroAction(
                        context,
                        waitingCount: _parseInt(_dashboardData?['stats']?['menunggu']),
                      ),
                    ),
                    EntranceAnimation(
                      delay: const Duration(milliseconds: 270),
                      child: _buildQuickActions(context),
                    ),
                    EntranceAnimation(
                      delay: const Duration(milliseconds: 360),
                      child: _isLoading && _dashboardData == null
                          ? const TicketListSkeleton(count: 3)
                          : _buildRecentTickets(
                              context,
                              _dashboardData?['recentTickets'] ?? <TicketModel>[],
                            ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
      extendBody: true,
      bottomNavigationBar: const AdminBottomNav(currentIndex: 0),
      floatingActionButton: CustomFloatingActionButton(
        icon: Icons.language,
        onPressed: () async {
          final Uri url = Uri.parse('https://dev.haloapu.id/admin/login');
          await launchUrl(url, mode: LaunchMode.externalApplication);
        },
      ),
      floatingActionButtonLocation: FloatingActionButtonLocation.centerDocked,
    );
  }

  Widget _buildHeroAction(BuildContext context, {required int waitingCount}) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 20, 24, 0),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: () => context.push('/tickets/admin'),
          borderRadius: BorderRadius.circular(24),
          child: Container(
            padding: const EdgeInsets.all(22),
            decoration: BoxDecoration(
              gradient: AppTheme.primaryGradient,
              borderRadius: BorderRadius.circular(24),
              boxShadow: [
                BoxShadow(
                  color: AppTheme.brilliantBlue.withValues(alpha: 0.3),
                  blurRadius: 20,
                  offset: const Offset(0, 8),
                ),
              ],
            ),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.2),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(
                    Icons.assignment_outlined,
                    color: Colors.white,
                    size: 26,
                  ),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '$waitingCount tiket menunggu diproses',
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 16,
                          fontWeight: FontWeight.bold,
                          letterSpacing: -0.3,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Ketuk untuk membuka antrean',
                        style: TextStyle(
                          color: Colors.white.withValues(alpha: 0.85),
                          fontSize: 12.5,
                        ),
                      ),
                    ],
                  ),
                ),
                Icon(
                  Icons.arrow_forward_ios_rounded,
                  color: Colors.white.withValues(alpha: 0.9),
                  size: 16,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildQuickActions(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Aksi Cepat',
            style: TextStyle(
              fontSize: 18,
              fontWeight: FontWeight.bold,
              letterSpacing: -0.5,
            ),
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              _buildActionCard(
                context,
                icon: Icons.assignment_outlined,
                color: AppTheme.oceanWater,
                label: 'Antrean Tiket',
                onTap: () => context.push('/tickets/admin'),
              ),
              const SizedBox(width: 12),
              _buildActionCard(
                context,
                icon: Icons.notifications_none_rounded,
                color: AppTheme.warning,
                label: 'Notifikasi',
                onTap: () => context.push('/notifications/admin'),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              _buildActionCard(
                context,
                icon: Icons.star_border_rounded,
                color: const Color(0xFFF59E0B),
                label: 'Hasil CSAT',
                onTap: () => context.push('/ratings/admin'),
              ),
              const SizedBox(width: 12),
              _buildActionCard(
                context,
                icon: Icons.monitor_heart_outlined,
                color: AppTheme.success,
                label: 'Pantauan Aset',
                onTap: () => context.push('/monitor'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildActionCard(
    BuildContext context, {
    required IconData icon,
    required Color color,
    required String label,
    required VoidCallback onTap,
  }) {
    return Expanded(
      child: Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(20),
          child: Container(
            padding: const EdgeInsets.symmetric(vertical: 18),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(20),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.04),
                  blurRadius: 10,
                  offset: const Offset(0, 4),
                ),
              ],
            ),
            child: Column(
              children: [
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: color.withValues(alpha: 0.1),
                    shape: BoxShape.circle,
                  ),
                  child: Icon(icon, color: color, size: 24),
                ),
                const SizedBox(height: 10),
                Text(
                  label,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.bold,
                    color: Colors.black87,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildRecentTickets(BuildContext context, List<TicketModel> tickets) {
    final recent = tickets.take(3).toList();

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Text(
                'Tiket Terbaru',
                style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.bold,
                  letterSpacing: -0.5,
                ),
              ),
              const Spacer(),
              TextButton(
                onPressed: () => context.push('/tickets/admin'),
                child: const Text(
                  'Lihat Semua',
                  style: TextStyle(
                    color: AppTheme.oceanWater,
                    fontWeight: FontWeight.bold,
                    fontSize: 13,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          if (recent.isEmpty)
            Container(
              padding: const EdgeInsets.all(24),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(20),
              ),
              child: Center(
                child: Column(
                  children: [
                    Icon(Icons.inbox_outlined,
                        size: 40, color: Colors.grey.shade400),
                    const SizedBox(height: 8),
                    Text(
                      'Belum ada tiket',
                      style: TextStyle(color: Colors.grey.shade600),
                    ),
                  ],
                ),
              ),
            )
          else
            for (final ticket in recent)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: _AdminTicketCard(
                  ticket: ticket,
                  onTap: () =>
                      context.push('/tickets/admin/detail', extra: ticket),
                ),
              ),
        ],
      ),
    );
  }
}

class _AdminHeader extends StatelessWidget {
  final int unreadCount;
  final UserProfile profile;

  const _AdminHeader({
    required this.unreadCount,
    required this.profile,
  });

  @override
  Widget build(BuildContext context) {
    final displayName = profile.name.isNotEmpty && profile.name != 'Loading...'
        ? profile.name
        : 'Admin';

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 20),
      child: Row(
        children: [
          AppAvatar(
            name: displayName,
            imageUrl: profile.avatarUrl,
            radius: 24,
            onTap: () => context.push('/profile/admin'),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Halo, ${displayName.split(' ').first}',
                  style: const TextStyle(
                    fontSize: 20,
                    fontWeight: FontWeight.bold,
                    color: Color(0xFF00768C),
                    letterSpacing: -0.5,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const Text(
                  'Ada yang bisa dibantu hari ini?',
                  style: TextStyle(fontSize: 12, color: Colors.grey),
                ),
              ],
            ),
          ),
          Stack(
            clipBehavior: Clip.none,
            children: [
              Container(
                decoration: BoxDecoration(
                  color: Colors.white,
                  shape: BoxShape.circle,
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.03),
                      blurRadius: 8,
                      offset: const Offset(0, 2),
                    ),
                  ],
                ),
                child: IconButton(
                  icon: const Icon(
                    Icons.notifications_outlined,
                    color: Colors.black87,
                    size: 24,
                  ),
                  onPressed: () => context.push('/notifications/admin'),
                ),
              ),
              if (unreadCount > 0)
                Positioned(
                  right: 2,
                  top: 2,
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 6,
                      vertical: 2,
                    ),
                    decoration: BoxDecoration(
                      color: AppTheme.danger,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: Colors.white, width: 1.5),
                    ),
                    constraints: const BoxConstraints(minWidth: 18),
                    child: TweenAnimationBuilder<double>(
                      tween: Tween(begin: 0, end: unreadCount.toDouble()),
                      duration: const Duration(milliseconds: 400),
                      curve: Curves.easeOutBack,
                      builder: (context, value, _) => Text(
                        value.round().toString(),
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 10,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _AdminStatsSkeleton extends StatelessWidget {
  const _AdminStatsSkeleton();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 8),
      child: Row(
        children: List.generate(
          4,
          (index) => Expanded(
            child: Container(
              margin: EdgeInsets.only(right: index < 3 ? 10 : 0),
              padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 10),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.04),
                    blurRadius: 8,
                    offset: const Offset(0, 3),
                  ),
                ],
              ),
              child: const Column(
                children: [
                  ShimmerBox(width: 32, height: 32, radius: 16),
                  SizedBox(height: 8),
                  ShimmerBox(width: 24, height: 20, radius: 4),
                  SizedBox(height: 6),
                  ShimmerBox(width: 44, height: 10, radius: 3),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

int _parseInt(dynamic val) {
  if (val == null) return 0;
  if (val is int) return val;
  if (val is num) return val.toInt();
  if (val is String) return int.tryParse(val) ?? 0;
  return 0;
}

class _AdminStatsRow extends StatelessWidget {
  final Map<String, dynamic>? stats;

  const _AdminStatsRow({required this.stats});

  @override
  Widget build(BuildContext context) {
    final open = _parseInt(stats?['menunggu']);
    final processing = _parseInt(stats?['diproses']);
    final solved = _parseInt(stats?['selesai']);
    final rejected = _parseInt(stats?['ditolak']);

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 8),
      child: Row(
        children: [
          _buildStatCard('Menunggu', open, AppTheme.danger, Icons.inbox_rounded),
          const SizedBox(width: 10),
          _buildStatCard('Diproses', processing, AppTheme.warning, Icons.handyman_outlined),
          const SizedBox(width: 10),
          _buildStatCard('Selesai', solved, AppTheme.success, Icons.check_circle_outline),
          const SizedBox(width: 10),
          _buildStatCard('Ditolak', rejected, AppTheme.brilliantBlue, Icons.cancel_outlined),
        ],
      ),
    );
  }

  Widget _buildStatCard(
    String title,
    int count,
    Color color,
    IconData icon,
  ) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 10),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.04),
              blurRadius: 8,
              offset: const Offset(0, 3),
            ),
          ],
        ),
        child: Column(
          children: [
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, color: color, size: 18),
            ),
            const SizedBox(height: 8),
            TweenAnimationBuilder<double>(
              tween: Tween(begin: 0, end: count.toDouble()),
              duration: const Duration(milliseconds: 900),
              curve: Curves.easeOutCubic,
              builder: (context, value, _) => Text(
                value.round().toString(),
                style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.bold,
                  color: color,
                  letterSpacing: -0.5,
                ),
              ),
            ),
            const SizedBox(height: 2),
            Text(
              title,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 10.5,
                fontWeight: FontWeight.w600,
                color: Colors.grey.shade600,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _AdminTicketCard extends StatelessWidget {
  final TicketModel ticket;
  final VoidCallback onTap;

  const _AdminTicketCard({required this.ticket, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final visual = _statusVisual(ticket.status);

    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(20),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(20),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.04),
                blurRadius: 10,
                offset: const Offset(0, 4),
              ),
            ],
          ),
          child: Row(
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  color: visual.$2.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(visual.$1, color: visual.$2, size: 22),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      ticket.title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.bold,
                        color: Colors.black87,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      '${ticket.id} • ${ticket.requesterName}',
                      style: TextStyle(
                        fontSize: 11.5,
                        color: Colors.grey.shade500,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: visual.$2.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  ticket.statusIndonesianLabel,
                  style: TextStyle(
                    color: visual.$2,
                    fontSize: 10.5,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  (IconData, Color) _statusVisual(TicketStatus status) {
    return switch (status) {
      TicketStatus.open => (Icons.inbox_rounded, AppTheme.danger),
      TicketStatus.processing =>
        (Icons.handyman_outlined, AppTheme.warning),
      TicketStatus.solved =>
        (Icons.check_circle_outline, AppTheme.success),
      TicketStatus.rejected =>
        (Icons.cancel_outlined, AppTheme.brilliantBlue),
      TicketStatus.cancelled =>
        (Icons.cancel, Colors.grey),
      TicketStatus.needRevision =>
        (Icons.edit_note, AppTheme.warning),
      TicketStatus.pending =>
        (Icons.hourglass_empty, Colors.grey),
    };
  }
}
