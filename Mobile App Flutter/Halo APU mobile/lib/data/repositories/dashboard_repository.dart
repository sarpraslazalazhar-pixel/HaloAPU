import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../api/api_client.dart';
import '../../domain/models/ticket_model.dart';

final dashboardRepositoryProvider = Provider<DashboardRepository>((ref) {
  final apiClient = ref.watch(apiClientProvider);
  return DashboardRepository(apiClient);
});

class DashboardRepository {
  final ApiClient _apiClient;
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  DashboardRepository(this._apiClient);

  Future<Map<String, dynamic>> getDashboardData() async {
    try {
      final response = await _apiClient.dio.get(
        '/dashboard',
        options: Options(
          receiveTimeout: const Duration(seconds: 8),
          sendTimeout: const Duration(seconds: 8),
        ),
      );

      if (response.statusCode == 200) {
        final data = response.data['data'];

        // Save successful fetch to local cache for instant offline fallback
        await _saveCache(data);

        final tickets = _parseTickets(data['recentTickets']);

        return {
          'success': true,
          'statusCode': 200,
          'isOffline': false,
          'stats': data['stats'] ?? _defaultStats(),
          'recentTickets': tickets,
        };
      }
      final fallback = await _fallbackFromCache('Gagal mengambil data dari server (${response.statusCode})');
      fallback['statusCode'] = response.statusCode;
      return fallback;
    } on DioException catch (e) {
      final errorMsg = e.error?.toString() ?? e.message ?? 'Tidak dapat terhubung ke server';
      final fallback = await _fallbackFromCache(errorMsg);
      fallback['statusCode'] = e.response?.statusCode;
      return fallback;
    } catch (e) {
      final fallback = await _fallbackFromCache('Terjadi kesalahan: $e');
      fallback['statusCode'] = null;
      return fallback;
    }
  }

  List<TicketModel> _parseTickets(dynamic rawTickets) {
    if (rawTickets is! List) return [];
    final List<TicketModel> list = [];
    for (final t in rawTickets) {
      try {
        final map = Map<String, dynamic>.from(t as Map);
        map['description'] ??= '';
        map['requesterName'] ??= 'Pengguna';
        map['category'] ??= 'Umum';

        final rawStatus = map['status']?.toString().toLowerCase() ?? 'open';
        switch (rawStatus) {
          case 'on_proses':
          case 'processing':
          case 'assigned':
            map['status'] = 'on_proses';
            break;
          case 'solve':
          case 'solved':
          case 'selesai':
            map['status'] = 'solve';
            break;
          case 'reject':
          case 'rejected':
            map['status'] = 'reject';
            break;
          case 'dibatalkan':
          case 'cancelled':
            map['status'] = 'dibatalkan';
            break;
          case 'need_revision':
            map['status'] = 'need_revision';
            break;
          case 'pending':
            map['status'] = 'pending';
            break;
          default:
            map['status'] = 'open';
        }

        list.add(TicketModel.fromJson(map));
      } catch (_) {}
    }
    return list;
  }

  Map<String, dynamic> _defaultStats() {
    return {
      'aktif': 0,
      'selesai': 0,
      'diproses': 0,
      'ditolak': 0,
    };
  }

  Future<void> _saveCache(dynamic data) async {
    try {
      await _storage.write(key: 'dashboard_cache', value: jsonEncode(data));
    } catch (_) {}
  }

  Future<Map<String, dynamic>> _fallbackFromCache(String reason) async {
    try {
      final cached = await _storage.read(key: 'dashboard_cache');
      if (cached != null) {
        final data = jsonDecode(cached);
        final tickets = _parseTickets(data['recentTickets']);
        return {
          'success': true,
          'isOffline': true,
          'message': reason,
          'stats': data['stats'] ?? _defaultStats(),
          'recentTickets': tickets,
        };
      }
    } catch (_) {}

    // Clean initial fallback if never connected before
    return {
      'success': true,
      'isOffline': true,
      'message': reason,
      'stats': _defaultStats(),
      'recentTickets': <TicketModel>[],
    };
  }
}
