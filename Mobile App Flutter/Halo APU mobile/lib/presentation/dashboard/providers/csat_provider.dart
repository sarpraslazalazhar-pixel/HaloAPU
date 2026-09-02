import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:halo_apu_mobile/data/repositories/csat_repository.dart';
import 'package:halo_apu_mobile/domain/models/csat_model.dart';

class CsatState {
  final AsyncValue<List<PendingCsatModel>> pendingCsats;
  final AsyncValue<List<CsatModel>> csatHistory;

  CsatState({
    this.pendingCsats = const AsyncValue.loading(),
    this.csatHistory = const AsyncValue.loading(),
  });

  CsatState copyWith({
    AsyncValue<List<PendingCsatModel>>? pendingCsats,
    AsyncValue<List<CsatModel>>? csatHistory,
  }) {
    return CsatState(
      pendingCsats: pendingCsats ?? this.pendingCsats,
      csatHistory: csatHistory ?? this.csatHistory,
    );
  }
}

class CsatNotifier extends StateNotifier<CsatState> {
  final CsatRepository _repository;

  CsatNotifier(this._repository) : super(CsatState()) {
    fetchData();
  }

  Future<void> fetchData() async {
    fetchPendingCsat();
    fetchCsatHistory();
  }

  Future<void> fetchPendingCsat() async {
    state = state.copyWith(pendingCsats: const AsyncValue.loading());
    try {
      final pending = await _repository.getPendingCsat();
      state = state.copyWith(pendingCsats: AsyncValue.data(pending));
    } catch (e, stack) {
      state = state.copyWith(pendingCsats: AsyncValue.error(e, stack));
    }
  }

  Future<void> fetchCsatHistory() async {
    state = state.copyWith(csatHistory: const AsyncValue.loading());
    try {
      final history = await _repository.getCsatHistory();
      state = state.copyWith(csatHistory: AsyncValue.data(history));
    } catch (e, stack) {
      state = state.copyWith(csatHistory: AsyncValue.error(e, stack));
    }
  }

  Future<bool> submitCsat(String ticketId, int rating, String? komentar) async {
    try {
      final success = await _repository.submitCsat(ticketId, rating, komentar);
      if (success) {
        // Refresh data setelah berhasil
        fetchData();
        return true;
      }
      return false;
    } catch (e) {
      rethrow;
    }
  }
}

final csatProvider = StateNotifierProvider<CsatNotifier, CsatState>((ref) {
  return CsatNotifier(ref.watch(csatRepositoryProvider));
});
