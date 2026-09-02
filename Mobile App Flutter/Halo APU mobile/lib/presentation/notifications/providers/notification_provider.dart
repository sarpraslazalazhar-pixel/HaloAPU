import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../domain/models/notification_model.dart';
import '../../../data/repositories/notification_repository.dart';

class NotificationNotifier
    extends StateNotifier<AsyncValue<List<NotificationModel>>> {
  final NotificationRepository _repository;

  int _currentPage = 1;
  bool _hasMore = true;
  bool _isLoadingMore = false;
  List<NotificationModel> _allNotifications = [];

  NotificationNotifier(this._repository) : super(const AsyncValue.loading()) {
    _loadInitial();
  }

  bool get hasMore => _hasMore;
  bool get isLoadingMore => _isLoadingMore;

  Future<void> _loadInitial() async {
    state = const AsyncValue.loading();
    _currentPage = 1;
    _allNotifications.clear();

    final result = await _repository.getNotifications(page: _currentPage);
    if (result['success']) {
      _allNotifications = result['data'] as List<NotificationModel>;
      final meta = result['meta'];
      _hasMore = _currentPage < (meta?['last_page'] ?? 1);

      state = AsyncValue.data(_allNotifications);
    } else {
      state = AsyncValue.error(result['message'], StackTrace.current);
    }
  }

  Future<void> loadMore() async {
    if (_isLoadingMore || !_hasMore || state is! AsyncData) return;
    _isLoadingMore = true;

    _currentPage++;
    final result = await _repository.getNotifications(page: _currentPage);

    if (result['success']) {
      final newItems = result['data'] as List<NotificationModel>;
      _allNotifications.addAll(newItems);

      final meta = result['meta'];
      _hasMore = _currentPage < (meta?['last_page'] ?? 1);

      state = AsyncValue.data(_allNotifications);
    }

    _isLoadingMore = false;
  }

  Future<void> refresh() => _loadInitial();

  int get unreadCount =>
      state.value?.where((n) => !n.isRead).length ?? 0;

  Future<void> markAsRead(String id) async {
    if (state is! AsyncData) return;

    // Optimistic UI update
    final current = List<NotificationModel>.from(state.value!);
    final index = current.indexWhere((n) => n.id == id);

    if (index != -1 && !current[index].isRead) {
      current[index] = current[index].copyWith(isRead: true);
      _allNotifications = current;
      state = AsyncValue.data(current);

      // Background network request
      await _repository.markAsRead(id);
    }
  }

  Future<void> markAllAsRead() async {
    if (state is! AsyncData) return;

    // Optimistic UI update
    final current = state.value!.map((n) => n.isRead ? n : n.copyWith(isRead: true)).toList();
    _allNotifications = current;
    state = AsyncValue.data(current);

    // Background network request
    await _repository.markAllAsRead();
  }

  Future<void> remove(String id) async {
    if (state is! AsyncData) return;

    // Optimistic UI update
    final current = state.value!.where((n) => n.id != id).toList();
    _allNotifications = current;
    state = AsyncValue.data(current);

    // Background network request
    await _repository.deleteNotification(id);
  }

  void restore(NotificationModel notification) {
    if (state is! AsyncData) return;
    final current = [notification, ...state.value!];
    _allNotifications = current;
    state = AsyncValue.data(current);
  }
}

final notificationProvider =
    StateNotifierProvider<NotificationNotifier, AsyncValue<List<NotificationModel>>>(
  (ref) => NotificationNotifier(ref.watch(notificationRepositoryProvider)),
);
