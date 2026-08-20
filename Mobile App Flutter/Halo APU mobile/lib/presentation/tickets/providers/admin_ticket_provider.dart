import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../domain/models/ticket_model.dart';
import '../../../../core/services/ticket_service.dart';

class AdminTicketNotifier extends StateNotifier<AsyncValue<List<TicketModel>>> {
  final TicketService _ticketService = TicketService();
  int _currentPage = 1;
  bool _isLoadingMore = false;
  bool _hasMore = true;
  List<TicketModel> _allTickets = [];
  List<String>? _currentStatuses;
  String? _searchQuery;

  AdminTicketNotifier() : super(const AsyncValue.loading()) {
    _loadInitial();
  }

  bool get hasMore => _hasMore;
  bool get isLoadingMore => _isLoadingMore;

  void setFilter(String filter) {
    switch (filter) {
      case 'Semua':
        _currentStatuses = null;
        break;
      case 'Aktif':
        _currentStatuses = ['open', 'on_proses', 'pending', 'need_revision'];
        break;
      case 'Terbuka':
      case 'Menunggu':
        _currentStatuses = ['open'];
        break;
      case 'Diproses':
        _currentStatuses = ['on_proses'];
        break;
      case 'Tertunda':
        _currentStatuses = ['pending'];
        break;
      case 'Selesai':
        _currentStatuses = ['solve'];
        break;
      case 'Ditolak':
        _currentStatuses = ['reject', 'dibatalkan'];
        break;
      default:
        _currentStatuses = null;
    }
    _loadInitial();
  }

  void setSearchQuery(String query) {
    if (_searchQuery == query) return;
    _searchQuery = query;
    _loadInitial();
  }

  Future<void> _loadInitial() async {
    state = const AsyncValue.loading();
    _currentPage = 1;
    _allTickets.clear();

    final result = await _ticketService.getTickets(
      page: _currentPage, 
      statuses: _currentStatuses,
      search: _searchQuery,
    );
    if (result['success']) {
      final List<dynamic> data = result['data'];
      _allTickets = data.map((json) => TicketModel.safeFromJson(json)).toList();
      
      final meta = result['meta'];
      _hasMore = _currentPage < (meta['last_page'] ?? 1);
      
      state = AsyncValue.data(_allTickets);
    } else {
      state = AsyncValue.error(result['message'], StackTrace.current);
    }
  }

  Future<void> loadMore() async {
    if (_isLoadingMore || !_hasMore || state is! AsyncData) return;
    _isLoadingMore = true;
    _currentPage++;

    final result = await _ticketService.getTickets(
      page: _currentPage, 
      statuses: _currentStatuses,
      search: _searchQuery,
    );
    if (result['success']) {
      final List<dynamic> data = result['data'];
      final newTickets = data.map((json) => TicketModel.safeFromJson(json)).toList();
      _allTickets.addAll(newTickets);
      
      final meta = result['meta'];
      _hasMore = _currentPage < (meta['last_page'] ?? 1);
      
      state = AsyncValue.data(_allTickets);
    }
    
    _isLoadingMore = false;
  }

  Future<void> refresh() => _loadInitial();

  void updateTicket(TicketModel updatedTicket) {
    if (state is AsyncData) {
      final currentList = state.value!;
      final index = currentList.indexWhere((t) => t.id == updatedTicket.id);
      if (index != -1) {
        final newList = List<TicketModel>.from(currentList);
        newList[index] = updatedTicket;
        _allTickets = newList;
        state = AsyncValue.data(newList);
      }
    }
  }
}

final adminTicketProvider = StateNotifierProvider<AdminTicketNotifier, AsyncValue<List<TicketModel>>>((ref) {
  return AdminTicketNotifier();
});
