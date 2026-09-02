import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../api/api_client.dart';
import '../../domain/models/csat_model.dart';

final csatRepositoryProvider = Provider<CsatRepository>((ref) {
  final apiClient = ref.watch(apiClientProvider);
  return CsatRepository(apiClient);
});

class CsatRepository {
  final ApiClient _apiClient;

  CsatRepository(this._apiClient);

  Future<List<PendingCsatModel>> getPendingCsat() async {
    try {
      final response = await _apiClient.dio.get('/ratings/pending');
      if (response.statusCode == 200) {
        final List<dynamic> data = response.data['data'] ?? [];
        return data.map((json) => PendingCsatModel.fromJson(json)).toList();
      }
      return [];
    } catch (_) {
      return [];
    }
  }

  Future<List<CsatModel>> getCsatHistory() async {
    try {
      final response = await _apiClient.dio.get('/ratings');
      if (response.statusCode == 200) {
        final List<dynamic> data = response.data['data'] ?? [];
        return data.map((json) => CsatModel.fromJson(json)).toList();
      }
      return [];
    } catch (_) {
      return [];
    }
  }

  Future<bool> submitCsat(String ticketId, int rating, String? comment) async {
    try {
      final response = await _apiClient.dio.post(
        '/tickets/$ticketId/rate',
        data: {
          'rating': rating,
          if (comment != null && comment.isNotEmpty) 'komentar': comment,
        },
      );
      return response.statusCode == 200 || response.statusCode == 201;
    } catch (_) {
      return false;
    }
  }
}
