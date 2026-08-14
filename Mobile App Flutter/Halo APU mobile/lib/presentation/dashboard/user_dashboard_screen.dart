import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:halo_apu_mobile/presentation/widgets/custom_bottom_nav.dart';
import 'package:halo_apu_mobile/presentation/widgets/entrance_animation.dart';
import 'package:halo_apu_mobile/presentation/widgets/shimmer.dart';
import 'package:halo_apu_mobile/presentation/widgets/app_avatar.dart';
import 'package:halo_apu_mobile/domain/models/user_profile_model.dart';
import 'package:halo_apu_mobile/domain/models/ticket_model.dart';
import 'package:halo_apu_mobile/presentation/notifications/providers/notification_provider.dart';
import 'package:halo_apu_mobile/presentation/profile/providers/user_profile_provider.dart';
import 'package:halo_apu_mobile/data/repositories/dashboard_repository.dart';

class UserDashboardScreen extends ConsumerStatefulWidget {
  const UserDashboardScreen({super.key});

  @override
  ConsumerState<UserDashboardScreen> createState() => _UserDashboardScreenState();
}

class _UserDashboardScreenState extends ConsumerState<UserDashboardScreen> {
  DashboardRepository get _dashboardRepo => ref.read(dashboardRepositoryProvider);
  Map<String, dynamic>? _dashboardData;
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _fetchDashboardData();
  }

  Future<void> _fetchDashboardData() async {
    if (!mounted) return;
    setState(() {
      _isLoading = true;
    });

    ref.read(userProfileProvider.notifier).refresh();
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
    final profile = ref.watch(userProfileProvider);
    final isOffline = _dashboardData?['isOffline'] == true;

    return Scaffold(
      backgroundColor: const Color(0xFFF7FAFC),
      body: Stack(
        children: [
          // Background Gradient at top
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            height: 320,
            child: Container(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [
                    const Color(0xFFE0F4FE).withValues(alpha: 0.7),
                    const Color(0xFFF7FAFC).withValues(alpha: 0.0),
                  ],
                ),
              ),
            ),
          ),
          SafeArea(
            bottom: false,
            child: RefreshIndicator(
              onRefresh: _fetchDashboardData,
              color: const Color(0xFF00768C),
              displacement: 40,
              child: SingleChildScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.only(bottom: 120),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    EntranceAnimation(
                      child: _DashboardHeader(
                        profile: profile,
                        unreadCount: unreadCount,
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

                    // Statistics Row
                    EntranceAnimation(
                      delay: const Duration(milliseconds: 90),
                      child: _isLoading && _dashboardData == null
                          ? const StatRowSkeleton()
                          : _StatisticsRow(stats: _dashboardData?['stats']),
                    ),

                    // Hero Action (Ajukan Tiket)
                    EntranceAnimation(
                      delay: const Duration(milliseconds: 180),
                      child: _buildHeroAction(context),
                    ),

                    // Quick Actions
                    EntranceAnimation(
                      delay: const Duration(milliseconds: 270),
                      child: _buildQuickActions(context),
                    ),

                    // Recent Tickets
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
      bottomNavigationBar: const UserBottomNav(currentIndex: 0),
      floatingActionButton: CustomFloatingActionButton(
        icon: Icons.add,
        onPressed: () {
          context.push('/tickets/create');
        },
      ),
      floatingActionButtonLocation: FloatingActionButtonLocation.centerDocked,
    );
  }

  Widget _buildHeroAction(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 0),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: () {
            context.push('/tickets/create');
          },
          borderRadius: BorderRadius.circular(24),
          child: Container(
            padding: const EdgeInsets.all(22),
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                colors: [Color(0xFF00C6FF), Color(0xFF0072FF)],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              borderRadius: BorderRadius.circular(24),
              boxShadow: [
                BoxShadow(
                  color: const Color(0xFF0072FF).withValues(alpha: 0.35),
                  blurRadius: 18,
                  offset: const Offset(0, 8),
                )
              ],
            ),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.22),
                    shape: BoxShape.circle,
                  ),
                  child: Container(
                    padding: const EdgeInsets.all(6),
                    decoration: const BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                    ),
                    child: const Icon(Icons.add_rounded, color: Color(0xFF0072FF), size: 22),
                  ),
                ),
                const SizedBox(width: 18),
                const Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Ajukan Tiket',
                        style: TextStyle(
                          color: Colors.white,
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                          letterSpacing: -0.3,
                        ),
                      ),
                      SizedBox(height: 4),
                      Text(
                        'Buat permintaan bantuan baru',
                        style: TextStyle(
                          color: Colors.white70,
                          fontSize: 13,
                        ),
                      ),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.15),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(Icons.arrow_forward_ios_rounded, color: Colors.white, size: 14),
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
      padding: const EdgeInsets.fromLTRB(24, 20, 24, 4),
      child: Wrap(
        spacing: 14,
        runSpacing: 14,
        children: [
          _buildActionCard(
            context,
            icon: Icons.history_rounded,
            iconColor: const Color(0xFF3B82F6),
            iconBgColor: const Color(0xFFEFF6FF),
            title: 'Riwayat',
            width: (MediaQuery.of(context).size.width - 48 - 14) / 2,
            onTap: () {
              context.push('/tickets/user');
            },
          ),
          _buildActionCard(
            context,
            icon: Icons.monitor_heart_outlined,
            iconColor: const Color(0xFF10B981),
            iconBgColor: const Color(0xFFECFDF5),
            title: 'Pantauan Langsung',
            width: (MediaQuery.of(context).size.width - 48 - 14) / 2,
            onTap: () {
              context.push('/monitor');
            },
          ),
          _buildActionCard(
            context,
            icon: Icons.star_rounded,
            iconColor: const Color(0xFFF59E0B),
            iconBgColor: const Color(0xFFFFFBEB),
            title: 'Penilaian Layanan',
            width: (MediaQuery.of(context).size.width - 48 - 14) / 2,
            onTap: () {
              context.push('/ratings/history');
            },
          ),
          _buildActionCard(
            context,
            icon: Icons.help_outline_rounded,
            iconColor: const Color(0xFF8B5CF6),
            iconBgColor: const Color(0xFFF5F3FF),
            title: 'Pusat Bantuan',
            width: (MediaQuery.of(context).size.width - 48 - 14) / 2,
            onTap: () {
              context.push('/help');
            },
          ),
        ],
      ),
    );
  }

  Widget _buildActionCard(
    BuildContext context, {
    required IconData icon,
    required Color iconColor,
    required Color iconBgColor,
    required String title,
    required double width,
    required VoidCallback onTap,
  }) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Container(
          width: width,
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(20),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.03),
                blurRadius: 10,
                offset: const Offset(0, 4),
              )
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: iconBgColor,
                  shape: BoxShape.circle,
                ),
                child: Icon(icon, color: iconColor, size: 24),
              ),
              const SizedBox(height: 14),
              Text(
                title,
                style: const TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 14,
                  color: Colors.black87,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildRecentTickets(BuildContext context, List<TicketModel> tickets) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text(
                'Tiket Terbaru',
                style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.bold,
                  letterSpacing: -0.5,
                  color: Colors.black87,
                ),
              ),
              TextButton(
                onPressed: () {
                  context.push('/tickets/user');
                },
                style: TextButton.styleFrom(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                  backgroundColor: const Color(0xFF00768C).withValues(alpha: 0.08),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                ),
                child: const Text(
                  'Lihat Semua',
                  style: TextStyle(
                    color: Color(0xFF00768C),
                    fontWeight: FontWeight.bold,
                    fontSize: 12,
                  ),
                ),
              )
            ],
          ),
          const SizedBox(height: 16),
          if (tickets.isEmpty)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 32, horizontal: 20),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(20),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.02),
                    blurRadius: 10,
                    offset: const Offset(0, 4),
                  )
                ],
              ),
              child: Center(
                child: Column(
                  children: [
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: const BoxDecoration(
                        color: Color(0xFFF1F5F9),
                        shape: BoxShape.circle,
                      ),
                      child: Icon(Icons.inbox_outlined, size: 36, color: Colors.grey.shade400),
                    ),
                    const SizedBox(height: 12),
                    const Text(
                      'Belum ada tiket diajukan',
                      style: TextStyle(
                        color: Colors.black87,
                        fontWeight: FontWeight.w600,
                        fontSize: 14,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'Tiket yang Anda ajukan akan muncul di sini',
                      style: TextStyle(color: Colors.grey.shade500, fontSize: 12),
                    ),
                  ],
                ),
              ),
            )
          else
            ...tickets.take(4).map((t) {
              Color sColor;
              Color sBgColor;
              String sName;

              switch (t.status) {
                case TicketStatus.open:
                  sColor = const Color(0xFFEF4444);
                  sBgColor = const Color(0xFFFEF2F2);
                  sName = 'Menunggu';
                  break;
                case TicketStatus.processing:
                  sColor = const Color(0xFFF59E0B);
                  sBgColor = const Color(0xFFFFFBEB);
                  sName = 'Diproses';
                  break;
                case TicketStatus.solved:
                  sColor = const Color(0xFF10B981);
                  sBgColor = const Color(0xFFECFDF5);
                  sName = 'Selesai';
                  break;
                case TicketStatus.rejected:
                  sColor = const Color(0xFF6B7280);
                  sBgColor = const Color(0xFFF3F4F6);
                  sName = 'Ditolak';
                  break;
                case TicketStatus.cancelled:
                  sColor = const Color(0xFF6B7280);
                  sBgColor = const Color(0xFFF3F4F6);
                  sName = 'Dibatalkan';
                  break;
                case TicketStatus.needRevision:
                  sColor = const Color(0xFFF97316);
                  sBgColor = const Color(0xFFFFF7ED);
                  sName = 'Revisi';
                  break;
                case TicketStatus.pending:
                  sColor = const Color(0xFF8B5CF6);
                  sBgColor = const Color(0xFFF5F3FF);
                  sName = 'Tertunda';
                  break;
              }

              final dateStr = '${t.createdAt.day.toString().padLeft(2, '0')}/${t.createdAt.month.toString().padLeft(2, '0')}/${t.createdAt.year} ${t.createdAt.hour.toString().padLeft(2, '0')}:${t.createdAt.minute.toString().padLeft(2, '0')}';

              return Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: _buildTicketCard(
                  context: context,
                  ticket: t,
                  id: '#${t.id}',
                  time: dateStr,
                  title: t.title,
                  category: t.category,
                  status: sName,
                  statusColor: sColor,
                  statusBgColor: sBgColor,
                ),
              );
            }),
        ],
      ),
    );
  }

  Widget _buildTicketCard({
    required BuildContext context,
    required TicketModel ticket,
    required String id,
    required String time,
    required String title,
    required String category,
    required String status,
    required Color statusColor,
    required Color statusBgColor,
  }) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: () {
          context.push('/tickets/detail', extra: ticket);
        },
        borderRadius: BorderRadius.circular(20),
        child: Container(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: Colors.grey.shade100),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.02),
                blurRadius: 12,
                offset: const Offset(0, 4),
              )
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: const Color(0xFF00768C).withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(
                      id,
                      style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF00768C),
                        letterSpacing: 0.5,
                      ),
                    ),
                  ),
                  Row(
                    children: [
                      Icon(Icons.access_time_rounded, size: 12, color: Colors.grey.shade400),
                      const SizedBox(width: 4),
                      Text(
                        time,
                        style: TextStyle(
                          fontSize: 11,
                          color: Colors.grey.shade500,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Text(
                title,
                style: const TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.bold,
                  color: Colors.black87,
                  height: 1.35,
                ),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 14),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: Colors.grey.shade100,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Text(
                      category,
                      style: TextStyle(
                        color: Colors.grey.shade700,
                        fontSize: 11,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: statusBgColor,
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Container(
                          width: 6,
                          height: 6,
                          decoration: BoxDecoration(
                            color: statusColor,
                            shape: BoxShape.circle,
                          ),
                        ),
                        const SizedBox(width: 6),
                        Text(
                          status,
                          style: TextStyle(
                            color: statusColor,
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ],
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

class _DashboardHeader extends StatelessWidget {
  final UserProfile profile;
  final int unreadCount;

  const _DashboardHeader({required this.profile, required this.unreadCount});

  @override
  Widget build(BuildContext context) {
    final displayName = profile.name.isNotEmpty && profile.name != 'Loading...'
        ? profile.name
        : (profile.username.isNotEmpty ? profile.username : 'Pengguna');
    final firstName = displayName.split(' ').first;

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 16),
      child: Row(
        children: [
          AppAvatar(
            name: displayName,
            imageUrl: profile.avatarUrl,
            radius: 24,
            onTap: () => context.push('/profile'),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Halo, $firstName',
                  style: const TextStyle(
                    fontSize: 20,
                    fontWeight: FontWeight.bold,
                    color: Color(0xFF00768C),
                    letterSpacing: -0.5,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 2),
                const Text(
                  'Selamat pagi, ada yang bisa dibantu?',
                  style: TextStyle(
                    fontSize: 12,
                    color: Colors.black54,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
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
                      color: Colors.black.withValues(alpha: 0.04),
                      blurRadius: 8,
                      offset: const Offset(0, 2),
                    )
                  ],
                ),
                child: IconButton(
                  icon: const Icon(Icons.notifications_outlined, color: Colors.black87, size: 22),
                  onPressed: () => context.push('/notifications'),
                ),
              ),
              if (unreadCount > 0)
                Positioned(
                  right: 2,
                  top: 2,
                  child: Container(
                    constraints: const BoxConstraints(minWidth: 18, minHeight: 18),
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: const Color(0xFFEF4444),
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: Colors.white, width: 1.5),
                    ),
                    child: Text(
                      unreadCount > 99 ? '99+' : '$unreadCount',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 9,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                ),
            ],
          )
        ],
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

class _StatisticsRow extends StatelessWidget {
  final Map<String, dynamic>? stats;

  const _StatisticsRow({required this.stats});

  @override
  Widget build(BuildContext context) {
    final aktif = _parseInt(stats?['aktif']);
    final selesai = _parseInt(stats?['selesai']);
    final diproses = _parseInt(stats?['diproses']);
    final ditolak = _parseInt(stats?['ditolak']);

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 24.0, vertical: 4.0),
      child: Row(
        children: [
          Expanded(
            child: Column(
              children: [
                _buildStatCard(
                  title: 'Aktif',
                  count: aktif,
                  color: const Color(0xFF0066FF),
                  icon: Icons.data_usage_rounded,
                  onTap: () => context.push('/tickets/user'),
                ),
                const SizedBox(height: 12),
                _buildStatCard(
                  title: 'Selesai',
                  count: selesai,
                  color: const Color(0xFF10B981),
                  icon: Icons.check_circle_outline_rounded,
                  onTap: () => context.push('/tickets/user'),
                ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              children: [
                _buildStatCard(
                  title: 'Diproses',
                  count: diproses,
                  color: const Color(0xFFF59E0B),
                  icon: Icons.hourglass_empty_rounded,
                  onTap: () => context.push('/tickets/user'),
                ),
                const SizedBox(height: 12),
                _buildStatCard(
                  title: 'Ditolak',
                  count: ditolak,
                  color: const Color(0xFFEF4444),
                  icon: Icons.cancel_outlined,
                  onTap: () => context.push('/tickets/user'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildStatCard({
    required String title,
    required int count,
    required Color color,
    required IconData icon,
    required VoidCallback onTap,
  }) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(20),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.02),
                blurRadius: 8,
                offset: const Offset(0, 4),
              )
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: color.withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Icon(icon, color: color, size: 18),
                  ),
                  TweenAnimationBuilder<double>(
                    tween: Tween(begin: 0, end: count.toDouble()),
                    duration: const Duration(milliseconds: 700),
                    curve: Curves.easeOutCubic,
                    builder: (context, value, _) => Text(
                      value.round().toString(),
                      style: const TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.bold,
                        color: Colors.black87,
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Text(
                title,
                style: TextStyle(
                  fontSize: 13,
                  color: Colors.grey.shade600,
                  fontWeight: FontWeight.w600,
                  letterSpacing: 0.2,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
