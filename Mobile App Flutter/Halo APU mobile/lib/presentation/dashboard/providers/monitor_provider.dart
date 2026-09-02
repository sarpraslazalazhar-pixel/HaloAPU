import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../domain/models/monitor_asset_model.dart';
import '../../../../domain/models/monitor_calendar_model.dart';
import '../../../../data/repositories/monitor_repository.dart';

class MonitorState {
  final AsyncValue<List<MonitorAssetModel>> assets;
  final AsyncValue<List<MonitorCalendarModel>> calendar;

  MonitorState({
    required this.assets,
    required this.calendar,
  });

  MonitorState copyWith({
    AsyncValue<List<MonitorAssetModel>>? assets,
    AsyncValue<List<MonitorCalendarModel>>? calendar,
  }) {
    return MonitorState(
      assets: assets ?? this.assets,
      calendar: calendar ?? this.calendar,
    );
  }
}

class MonitorNotifier extends StateNotifier<MonitorState> {
  final MonitorRepository _repository;

  MonitorNotifier(this._repository)
      : super(MonitorState(
          assets: const AsyncValue.loading(),
          calendar: const AsyncValue.loading(),
        )) {
    loadData();
  }

  Future<void> loadData() async {
    state = state.copyWith(
      assets: const AsyncValue.loading(),
      calendar: const AsyncValue.loading(),
    );

    try {
      final assetsResult = await _repository.getAssets();
      if (assetsResult['success']) {
        final List<dynamic> data = assetsResult['data'];
        final assets = data.map((e) => MonitorAssetModel.fromJson(e)).toList();
        state = state.copyWith(assets: AsyncValue.data(assets));
      } else {
        state = state.copyWith(assets: AsyncValue.error(assetsResult['message'], StackTrace.current));
      }
    } catch (e) {
      state = state.copyWith(assets: AsyncValue.error(e.toString(), StackTrace.current));
    }

    try {
      final calendarResult = await _repository.getCalendar();
      if (calendarResult['success']) {
        final List<dynamic> data = calendarResult['data'];
        final calendar = data.map((e) => MonitorCalendarModel.fromJson(e)).toList();
        state = state.copyWith(calendar: AsyncValue.data(calendar));
      } else {
        state = state.copyWith(calendar: AsyncValue.error(calendarResult['message'], StackTrace.current));
      }
    } catch (e) {
      state = state.copyWith(calendar: AsyncValue.error(e.toString(), StackTrace.current));
    }
  }

  Future<void> refresh() => loadData();
}

final monitorProvider = StateNotifierProvider<MonitorNotifier, MonitorState>((ref) {
  return MonitorNotifier(ref.watch(monitorRepositoryProvider));
});
