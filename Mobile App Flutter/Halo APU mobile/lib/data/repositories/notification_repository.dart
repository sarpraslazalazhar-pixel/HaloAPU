import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../api/api_client.dart';
import '../../domain/models/notification_model.dart';

final notificationRepositoryProvider = Provider<NotificationRepository>((ref) {
  final apiClient = ref.watch(apiClientProvider);
  return NotificationRepository(apiClient);
});

class NotificationRepository {
  final ApiClient _apiClient;

  NotificationRepository(this._apiClient);

  Future<Map<String, dynamic>> getNotifications({int page = 1, int perPage = 15}) async {
    try {
      final response = await _apiClient.dio.get(
        '/notifications',
        queryParameters: {'page': page, 'per_page': perPage},
      );
      if (response.statusCode == 200) {
        final List<dynamic> data = response.data['data'] ?? [];
        final list = data.map((json) => NotificationModel.fromJson(json)).toList();
        return {'success': true, 'data': list, 'meta': response.data['meta']};
      }
      return {'success': false, 'message': 'Gagal mengambil notifikasi'};
    } on DioException catch (e) {
      return {'success': false, 'message': e.error?.toString() ?? 'Gagal mengambil notifikasi'};
    }
  }

  Future<bool> markAsRead(String id) async {
    try {
      final response = await _apiClient.dio.patch('/notifications/$id/read');
      return response.statusCode == 200;
    } catch (_) {
      return false;
    }
  }

  Future<bool> markAllAsRead() async {
    try {
      final response = await _apiClient.dio.post('/notifications/read-all');
      return response.statusCode == 200;
    } catch (_) {
      return false;
    }
  }

  Future<bool> deleteNotification(String id) async {
    try {
      final response = await _apiClient.dio.delete('/notifications/$id');
      return response.statusCode == 200;
    } catch (_) {
      return false;
    }
  }
}
