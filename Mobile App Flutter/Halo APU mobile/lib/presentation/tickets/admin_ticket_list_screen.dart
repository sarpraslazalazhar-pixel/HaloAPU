import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:halo_apu_mobile/presentation/widgets/shimmer.dart';
import '../../../../domain/models/ticket_model.dart';
import '../widgets/custom_bottom_nav.dart';
import 'providers/admin_ticket_provider.dart';
import 'widgets/ticket_card.dart';
import '../widgets/pagination_footer.dart';
import 'dart:async';

class AdminTicketListScreen extends ConsumerStatefulWidget {
  const AdminTicketListScreen({super.key});

  @override
  ConsumerState<AdminTicketListScreen> createState() => _AdminTicketListScreenState();
}

class _AdminTicketListScreenState extends ConsumerState<AdminTicketListScreen> {
  final ScrollController _scrollController = ScrollController();
  String _selectedFilter = 'Semua';
  final List<String> _filters = ['Semua', 'Aktif', 'Terbuka', 'Diproses', 'Tertunda', 'Selesai', 'Ditolak'];
  bool _isLoadingMore = false;
  final TextEditingController _searchController = TextEditingController();
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_onScroll);
  }

  @override
  void dispose() {
    _scrollController.dispose();
    _searchController.dispose();
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> _onScroll() async {
    final notifier = ref.read(adminTicketProvider.notifier);
    if (_isLoadingMore || !notifier.hasMore) return;
    if (_scrollController.position.pixels >=
        _scrollController.position.maxScrollExtent - 200) {
      setState(() => _isLoadingMore = true);
      await notifier.loadMore();
      if (mounted) setState(() => _isLoadingMore = false);
    }
  }

  void _onSearchChanged(String query) {
    if (_debounce?.isActive ?? false) _debounce!.cancel();
    _debounce = Timer(const Duration(milliseconds: 500), () {
      ref.read(adminTicketProvider.notifier).setSearchQuery(query);
    });
  }

  List<TicketModel> _getFilteredTickets(List<TicketModel> tickets) {
    return tickets;
  }

  @override
  Widget build(BuildContext context) {
    final ticketState = ref.watch(adminTicketProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Manajemen Tiket', style: TextStyle(fontWeight: FontWeight.bold)),
        elevation: 0,
        backgroundColor: Colors.white,
        foregroundColor: Colors.black,
      ),
      body: Column(
        children: [
          // Search Bar
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
            child: TextField(
              controller: _searchController,
              onChanged: (val) {
                setState(() {});
                _onSearchChanged(val);
              },
              decoration: InputDecoration(
                hintText: 'Cari ID, Layanan, Kanal, atau Detail...',
                prefixIcon: const Icon(Icons.search, color: Colors.grey),
                suffixIcon: _searchController.text.isNotEmpty
                    ? IconButton(
                        icon: const Icon(Icons.clear, size: 18, color: Colors.grey),
                        onPressed: () {
                          _searchController.clear();
                          setState(() {});
                          _onSearchChanged('');
                        },
                      )
                    : null,
                filled: true,
                fillColor: Colors.grey.shade100,
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(16),
                  borderSide: BorderSide.none,
                ),
                contentPadding: const EdgeInsets.symmetric(vertical: 0),
              ),
            ),
          ),
          // Filter Chips
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Row(
              children: _filters.map((filter) {
                final isSelected = _selectedFilter == filter;
                return Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: FilterChip(
                    label: Text(filter),
                    selected: isSelected,
                    onSelected: (selected) {
                      setState(() {
                        _selectedFilter = filter;
                      });
                      ref.read(adminTicketProvider.notifier).setFilter(filter);
                    },
                    backgroundColor: Colors.grey.shade100,
                    selectedColor: const Color(0xFF00B8D9).withValues(alpha: 0.2),
                    checkmarkColor: const Color(0xFF00B8D9),
                    labelStyle: TextStyle(
                      color: isSelected ? const Color(0xFF0066FF) : Colors.grey.shade700,
                      fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                    ),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(20),
                      side: BorderSide(
                        color: isSelected ? const Color(0xFF00B8D9) : Colors.transparent,
                      ),
                    ),
                  ),
                );
              }).toList(),
            ),
          ),
          
          // Ticket List
          Expanded(
            child: ticketState.when(
              loading: () => const TicketListSkeleton(count: 4, showHeader: false),
              error: (error, stack) => Center(child: Text('Error: $error')),
              data: (tickets) {
                final filteredTickets = _getFilteredTickets(tickets);
                final hasMore = ref.read(adminTicketProvider.notifier).hasMore;

                if (filteredTickets.isEmpty) {
                  return RefreshIndicator(
                    onRefresh: () => ref.read(adminTicketProvider.notifier).refresh(),
                    child: ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      children: [
                        SizedBox(
                          height: MediaQuery.of(context).size.height * 0.5,
                          child: Center(
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                Icon(Icons.inbox_outlined, size: 64, color: Colors.grey.shade400),
                                const SizedBox(height: 16),
                                Text(
                                  'Belum ada tiket',
                                  style: TextStyle(color: Colors.grey.shade600, fontSize: 16),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
                  );
                }

                return RefreshIndicator(
                  onRefresh: () => ref.read(adminTicketProvider.notifier).refresh(),
                  color: const Color(0xFF00B8D9),
                  child: ListView.builder(
                    controller: _scrollController,
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 120),
                    itemCount: filteredTickets.length + 1,
                    itemBuilder: (context, index) {
                      if (index == filteredTickets.length) {
                        return PaginationFooter(
                          isLoading: _isLoadingMore,
                          hasMore: hasMore,
                        );
                      }
                      final ticket = filteredTickets[index];
                      return TicketCard(
                        ticket: ticket,
                        onTap: () {
                          context.push('/tickets/admin/detail', extra: ticket);
                        },
                      );
                    },
                  ),
                );
              },
            ),
          ),
        ],
      ),
      extendBody: true,
      bottomNavigationBar: const AdminBottomNav(currentIndex: 1), // Index 1 is Antrean
      floatingActionButton: CustomFloatingActionButton(
        icon: Icons.language,
        onPressed: () async {
          final Uri url = Uri.parse('https://dev.haloapu.id');
          await launchUrl(url, mode: LaunchMode.externalApplication);
        },
      ),
      floatingActionButtonLocation: FloatingActionButtonLocation.centerDocked,
    );
  }
}
