import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../domain/models/rating_model.dart';
import '../../../data/repositories/csat_repository.dart';

class RatingNotifier extends StateNotifier<AsyncValue<List<RatingModel>>> {
  static const int _pageSize = 15;

  final CsatRepository _repository;
  List<RatingModel> _allRatings = [];
  int _currentPage = 1;
  int _lastPage = 1;
  bool _isLoadingMore = false;

  final List<RatingModel>? initialRatings;

  RatingNotifier(this._repository, {this.initialRatings}) : super(const AsyncValue.loading()) {
    if (initialRatings != null) {
      _allRatings = initialRatings!;
      state = AsyncValue.data(_allRatings);
    } else {
      _loadInitial();
    }
  }

  bool get hasMore => _currentPage < _lastPage;
  bool get isLoadingMore => _isLoadingMore;

  Future<void> _loadInitial() async {
    try {
      _currentPage = 1;
      final history = await _repository.getCsatHistory();

      _allRatings = history.map((csat) => RatingModel(
        id: csat.id,
        ticketId: csat.ticketId,
        ticketTitle: csat.ticketTitle,
        category: csat.category,
        score: csat.score,
        comment: csat.comment,
        createdAt: DateTime.parse(csat.createdAt),
      )).toList();
      
      // Assuming we get total pages from API or handle it. For now, we fetch next page to check if more.
      _lastPage = history.length < _pageSize ? 1 : 2; 

      state = AsyncValue.data(_allRatings);
    } catch (e, stack) {
      state = AsyncValue.error(e, stack);
    }
  }

  Future<void> loadMore() async {
    if (_isLoadingMore || !hasMore) return;
    _isLoadingMore = true;
    try {
      _currentPage++;
      final moreHistory = await _repository.getCsatHistory();
      
      if (moreHistory.isEmpty) {
        _lastPage = _currentPage;
      } else {
        final newRatings = moreHistory.map((csat) => RatingModel(
          id: csat.id,
          ticketId: csat.ticketId,
          ticketTitle: csat.ticketTitle,
          category: csat.category,
          score: csat.score,
          comment: csat.comment,
          createdAt: DateTime.parse(csat.createdAt),
        )).toList();
        
        _allRatings = [..._allRatings, ...newRatings];
        _lastPage = moreHistory.length < _pageSize ? _currentPage : _currentPage + 1;
        state = AsyncValue.data(_allRatings);
      }
    } catch (e) {
      // Ignore error on loadMore, just reset page
      _currentPage--;
    } finally {
      _isLoadingMore = false;
    }
  }

  Future<void> refresh() async {
    state = const AsyncValue.loading();
    await _loadInitial();
  }
}

List<RatingModel> _buildAdminMockRatings() {
  final now = DateTime.now();
  final handCrafted = [
    RatingModel(
      id: 'RA-001',
      ticketId: 'TKT-20260720-004',
      ticketTitle: 'Install Software Design',
      category: 'IT Support',
      score: 5,
      comment: 'Pengerjaan cepat dan admin sangat membantu.',
      createdAt: now.subtract(const Duration(days: 8)),
    ),
    RatingModel(
      id: 'RA-002',
      ticketId: 'TKT-20260728-005',
      ticketTitle: 'Lampu Toilet Mati',
      category: 'General Affair',
      score: 4,
      comment: 'Tim cepat datang memperbaiki.',
      createdAt: now.subtract(const Duration(hours: 20)),
    ),
    RatingModel(
      id: 'RA-003',
      ticketId: 'TKT-20260727-006',
      ticketTitle: 'Printer Error',
      category: 'IT Support',
      score: 5,
      createdAt: now.subtract(const Duration(days: 1, hours: 5)),
    ),
  ];

  const titles = [
    'Perbaikan Jaringan Kantor',
    'Pengadaan Meja Baru',
    'Akses Aplikasi Keuangan',
    'Perbaikan Pintu Ruangan',
    'Bantuan Instalasi Aplikasi',
    'Penggantian Monitor',
    'Permintaan Kartu Akses',
    'Perawatan Kendaraan Dinas',
  ];
  const categories = ['IT Support', 'General Affair', 'HR', 'Keuangan', 'Operasional'];
  const comments = [
    'Admin merespons dengan cepat.',
    'Solusi diberikan dengan jelas.',
    'Sangat membantu, terima kasih.',
    'Prosesnya lumayan cepat.',
    'Pelayanan memuaskan.',
    'Kurang cepat, tapi hasil akhir baik.',
  ];
  const scores = [5, 4, 5, 3, 4, 5];

  // ID tiket yang ditangani admin (status selesai pada daftar admin).
  const handledTicketIds = [4, 9, 14, 19, 24, 29, 34];

  final generated = List.generate(24, (i) {
    final ticketIndex = handledTicketIds[i % handledTicketIds.length];
    return RatingModel(
      id: 'RA-GEN-${(i + 1).toString().padLeft(3, '0')}',
      ticketId: 'TKT-GEN-${ticketIndex.toString().padLeft(4, '0')}',
      ticketTitle: titles[i % titles.length],
      category: categories[i % categories.length],
      score: scores[i % scores.length],
      comment: i % 3 == 0 ? null : comments[i % comments.length],
      createdAt: now.subtract(Duration(days: 2 + (i * 2))),
    );
  });

  return [...handCrafted, ...generated];
}

final ratingProvider =
    StateNotifierProvider<RatingNotifier, AsyncValue<List<RatingModel>>>(
  (ref) => RatingNotifier(ref.watch(csatRepositoryProvider)),
);

final adminRatingProvider =
    StateNotifierProvider<RatingNotifier, AsyncValue<List<RatingModel>>>(
  (ref) => RatingNotifier(ref.watch(csatRepositoryProvider), initialRatings: _buildAdminMockRatings()),
);
