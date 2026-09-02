import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../../core/theme/app_theme.dart';
import '../../../domain/models/rating_model.dart';
import '../widgets/custom_bottom_nav.dart';
import '../widgets/entrance_animation.dart';
import '../widgets/shimmer.dart';
import '../widgets/animated_star_rating.dart';
import '../widgets/pagination_footer.dart';
import 'providers/rating_provider.dart';
import '../dashboard/providers/csat_provider.dart';
import '../../../domain/models/csat_model.dart';
import '../../../domain/models/ticket_model.dart';

class RatingHistoryScreen extends ConsumerStatefulWidget {
  final bool isAdmin;

  const RatingHistoryScreen({super.key, this.isAdmin = false});

  @override
  ConsumerState<RatingHistoryScreen> createState() => _RatingHistoryScreenState();
}

class _RatingHistoryScreenState extends ConsumerState<RatingHistoryScreen> {
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
    final notifier = ref
        .read((widget.isAdmin ? adminRatingProvider : ratingProvider).notifier);
    if (_isLoadingMore || !notifier.hasMore) return;
    if (_scrollController.position.pixels >=
        _scrollController.position.maxScrollExtent - 200) {
      setState(() => _isLoadingMore = true);
      await notifier.loadMore();
      if (mounted) setState(() => _isLoadingMore = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ratingsState = ref.watch(
      widget.isAdmin ? adminRatingProvider : ratingProvider,
    );
    final csatState = widget.isAdmin ? null : ref.watch(csatProvider);

    return Scaffold(
      backgroundColor: AppTheme.lightBg,
      appBar: AppBar(
        title: Text(
          widget.isAdmin ? 'Rating Tiket Ditangani' : 'Riwayat Rating',
          style: const TextStyle(fontWeight: FontWeight.bold),
        ),
        elevation: 0,
        backgroundColor: Colors.white,
        foregroundColor: Colors.black,
      ),
      body: ratingsState.when(
        loading: () => const _RatingShimmerList(),
        error: (error, stack) => Center(child: Text('Error: $error')),
        data: (ratings) => RefreshIndicator(
          onRefresh: () async {
            if (!widget.isAdmin) {
              ref.read(csatProvider.notifier).fetchPendingCsat();
            }
            return ref
              .read((widget.isAdmin ? adminRatingProvider : ratingProvider).notifier)
              .refresh();
          },
          color: AppTheme.oceanWater,
          child: ratings.isEmpty
              ? const _EmptyRatingState()
              : CustomScrollView(
                  controller: _scrollController,
                  physics: const AlwaysScrollableScrollPhysics(),
                  slivers: [
                    SliverToBoxAdapter(
                      child: EntranceAnimation(
                        child: _RatingSummaryCard(
                          ratings: ratings,
                          label: widget.isAdmin
                              ? 'rating tiket yang Anda tangani'
                              : 'penilaian layanan',
                        ),
                      ),
                    ),
                    if (!widget.isAdmin && csatState != null)
                      csatState.pendingCsats.when(
                        data: (pending) {
                          if (pending.isEmpty) return const SliverToBoxAdapter(child: SizedBox());
                          return SliverToBoxAdapter(
                            child: Padding(
                              padding: const EdgeInsets.only(top: 24, left: 24, right: 24),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Text(
                                    'Perlu Dinilai',
                                    style: TextStyle(
                                      fontSize: 18,
                                      fontWeight: FontWeight.bold,
                                      letterSpacing: -0.5,
                                    ),
                                  ),
                                  const SizedBox(height: 12),
                                  ...pending.map((p) => EntranceAnimation(
                                    child: _buildPendingCard(context, p, ref),
                                  )),
                                ],
                              ),
                            ),
                          );
                        },
                        loading: () => const SliverToBoxAdapter(child: SizedBox()),
                        error: (e, s) => const SliverToBoxAdapter(child: SizedBox()),
                      ),
                    SliverToBoxAdapter(
                      child: EntranceAnimation(
                        delay: const Duration(milliseconds: 120),
                        child: Padding(
                          padding: const EdgeInsets.fromLTRB(24, 24, 24, 12),
                          child: Row(
                            children: [
                              Text(
                                widget.isAdmin ? 'Rating dari Karyawan' : 'Penilaian Anda',
                                style: const TextStyle(
                                  fontSize: 18,
                                  fontWeight: FontWeight.bold,
                                  letterSpacing: -0.5,
                                ),
                              ),
                              const Spacer(),
                              Text(
                                '${ratings.length} rating',
                                style: TextStyle(
                                  fontSize: 12,
                                  color: Colors.grey.shade500,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                    SliverPadding(
                      padding: const EdgeInsets.fromLTRB(24, 0, 24, 120),
                      sliver: SliverList(
                        delegate: SliverChildBuilderDelegate(
                          (context, index) {
                            final rating = ratings[index];
                            return EntranceAnimation(
                              delay: Duration(milliseconds: 160 + (index * 70)),
                              child: Padding(
                                padding: const EdgeInsets.only(bottom: 16),
                                child: _RatingCard(rating: rating),
                              ),
                            );
                          },
                          childCount: ratings.length,
                        ),
                      ),
                    ),
                    SliverToBoxAdapter(
                      child: PaginationFooter(
                        isLoading: _isLoadingMore,
                        hasMore: ref
                            .read((widget.isAdmin ? adminRatingProvider : ratingProvider).notifier)
                            .hasMore,
                      ),
                    ),
                  ],
                ),
        ),
      ),
      extendBody: true,
      bottomNavigationBar: widget.isAdmin
          ? const AdminBottomNav(currentIndex: 2)
          : const UserBottomNav(currentIndex: 2),
      floatingActionButton: widget.isAdmin
          ? CustomFloatingActionButton(
              icon: Icons.language,
              onPressed: () async {
                final Uri url = Uri.parse('https://dev.haloapu.id');
                await launchUrl(url, mode: LaunchMode.externalApplication);
              },
            )
          : CustomFloatingActionButton(
              icon: Icons.add,
              onPressed: () => context.push('/tickets/create'),
            ),
      floatingActionButtonLocation: FloatingActionButtonLocation.centerDocked,
    );
  }

  Widget _buildPendingCard(BuildContext context, PendingCsatModel pending, WidgetRef ref) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: const Color(0xFFFFFBEB),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFFDE68A)),
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: () {
            final dummyTicket = TicketModel(
              id: pending.id,
              title: pending.ticketTitle,
              description: '',
              category: pending.category,
              status: TicketStatus.solved,
              createdAt: DateTime.tryParse(pending.completedAt) ?? DateTime.now(),
              requesterName: '',
            );
            context.push('/tickets/user/detail', extra: dummyTicket).then((_) {
              ref.read(csatProvider.notifier).fetchPendingCsat();
              ref.read(ratingProvider.notifier).refresh();
            });
          },
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: const Color(0xFFFEF3C7),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: const Icon(Icons.star_rounded, color: Color(0xFFF59E0B), size: 24),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        pending.ticketTitle,
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Color(0xFF1E293B)),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 4),
                      const Text(
                        'Menunggu Penilaian Anda',
                        style: TextStyle(fontSize: 12, color: Color(0xFF64748B)),
                      ),
                    ],
                  ),
                ),
                const Icon(Icons.chevron_right, color: Color(0xFF94A3B8)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _RatingSummaryCard extends StatelessWidget {
  final List<RatingModel> ratings;
  final String label;

  const _RatingSummaryCard({required this.ratings, this.label = 'penilaian layanan'});

  @override
  Widget build(BuildContext context) {
    final total = ratings.length;
    final average = ratings.fold<int>(0, (sum, r) => sum + r.score) / total;

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 0),
      child: Container(
        padding: const EdgeInsets.all(24),
        decoration: BoxDecoration(
          gradient: AppTheme.primaryGradient,
          borderRadius: BorderRadius.circular(20),
          boxShadow: [
            BoxShadow(
              color: AppTheme.brilliantBlue.withValues(alpha: 0.25),
              blurRadius: 20,
              offset: const Offset(0, 8),
            ),
          ],
        ),
        child: Row(
          children: [
            TweenAnimationBuilder<double>(
              tween: Tween(begin: 0, end: average),
              duration: const Duration(milliseconds: 900),
              curve: Curves.easeOutCubic,
              builder: (context, value, _) {
                return Text(
                  value.toStringAsFixed(1),
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 44,
                    fontWeight: FontWeight.bold,
                    letterSpacing: -1,
                  ),
                );
              },
            ),
            const SizedBox(width: 20),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  AnimatedStarRating(rating: average.round()),
                  const SizedBox(height: 8),
                  Text(
                    'dari $total $label',
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: 0.9),
                      fontSize: 13,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _RatingCard extends StatelessWidget {
  final RatingModel rating;

  const _RatingCard({required this.rating});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.03),
            blurRadius: 10,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(20),
          onTap: () {
            final dummyTicket = TicketModel(
              id: rating.ticketId,
              title: rating.ticketTitle,
              description: '',
              category: rating.category,
              status: TicketStatus.solved,
              createdAt: rating.createdAt,
              requesterName: '',
            );
            context.push('/tickets/user/detail', extra: dummyTicket);
          },
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: AppTheme.oceanWater.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  rating.category,
                  style: const TextStyle(
                    color: AppTheme.oceanWater,
                    fontSize: 11,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
              const Spacer(),
              Text(
                _formatRelativeTime(rating.createdAt),
                style: TextStyle(fontSize: 12, color: Colors.grey.shade500),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Text(
            rating.ticketTitle,
            style: const TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.bold,
              color: Colors.black87,
              height: 1.3,
            ),
          ),
          const SizedBox(height: 12),
          AnimatedStarRating(rating: rating.score, size: 18),
          if (rating.comment != null) ...[
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AppTheme.lightBg,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    Icons.format_quote_rounded,
                    size: 16,
                    color: AppTheme.oceanWater.withValues(alpha: 0.6),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      rating.comment!,
                      style: TextStyle(
                        fontSize: 13,
                        color: Colors.grey.shade700,
                        height: 1.4,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
      ),
      ),
      ),
    );
  }
}

class _RatingShimmerList extends StatelessWidget {
  const _RatingShimmerList();

  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 120),
      children: const [
        ShimmerBox(height: 120, radius: 20),
        SizedBox(height: 24),
        ShimmerBox(width: 140, height: 18, radius: 8),
        SizedBox(height: 16),
        ShimmerBox(height: 140, radius: 20),
        SizedBox(height: 16),
        ShimmerBox(height: 140, radius: 20),
        SizedBox(height: 16),
        ShimmerBox(height: 140, radius: 20),
      ],
    );
  }
}

class _EmptyRatingState extends StatelessWidget {
  const _EmptyRatingState();

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
                      Icons.star_outline_rounded,
                      size: 72,
                      color: AppTheme.warning,
                    ),
                    SizedBox(height: 16),
                    Text(
                      'Belum ada penilaian',
                      style: TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.bold,
                        color: Colors.black87,
                      ),
                    ),
                    SizedBox(height: 6),
                    Text(
                      'Rating layanan yang pernah Anda berikan\nakan muncul di sini.',
                      textAlign: TextAlign.center,
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

String _formatRelativeTime(DateTime dateTime) {
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
