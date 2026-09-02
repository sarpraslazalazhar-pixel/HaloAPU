import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../api/api_client.dart';

final monitorRepositoryProvider = Provider<MonitorRepository>((ref) {
  final apiClient = ref.watch(apiClientProvider);
  return MonitorRepository(apiClient);
});

class MonitorRepository {
  final ApiClient _apiClient;

  MonitorRepository(this._apiClient);

  Future<Map<String, dynamic>> getAssets() async {
    try {
      final response = await _apiClient.dio.get('/monitor/assets');
      if (response.statusCode == 200) {
        return {'success': true, 'data': response.data['data']};
      }
      return {'success': false, 'message': 'Gagal mengambil data aset'};
    } on DioException catch (e) {
      return {'success': false, 'message': e.error?.toString() ?? 'Kesalahan saat mengambil data aset'};
    }
  }

  Future<Map<String, dynamic>> getCalendar() async {
    try {
      final response = await _apiClient.dio.get('/monitor/calendar');
      if (response.statusCode == 200) {
        return {'success': true, 'data': response.data['data']};
      }
      return {'success': false, 'message': 'Gagal mengambil jadwal'};
    } on DioException catch (e) {
      return {'success': false, 'message': e.error?.toString() ?? 'Kesalahan saat mengambil jadwal'};
    }
  }
}
