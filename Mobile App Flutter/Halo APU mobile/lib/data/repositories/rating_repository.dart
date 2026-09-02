import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../api/api_client.dart';
import '../../domain/models/rating_model.dart';

final ratingRepositoryProvider = Provider<RatingRepository>((ref) {
  final apiClient = ref.watch(apiClientProvider);
  return RatingRepository(apiClient);
});

class RatingRepository {
  final ApiClient _apiClient;

  RatingRepository(this._apiClient);

  /// Submit a CSAT rating for a ticket.
  Future<Map<String, dynamic>> submitRating({
    required String ticketId,
    required int score,
    String? comment,
  }) async {
    try {
      final response = await _apiClient.dio.post(
        '/tickets/$ticketId/rate',
        data: {
          'rating': score,
          'komentar': comment,
        },
      );

      if (response.statusCode == 201 || response.statusCode == 200) {
        return response.data['data'];
      } else {
        throw Exception(response.data['message'] ?? 'Gagal mengirim rating');
      }
    } on DioException catch (e) {
      if (e.response?.statusCode == 422) {
        throw Exception(e.response!.data['message'] ?? 'Rating tidak dapat diberikan');
      }
      throw Exception('Gagal mengirim rating: ${e.message}');
    }
  }

  /// Fetch user's rating history with pagination.
  Future<List<RatingModel>> getRatingHistory({int page = 1, int perPage = 10}) async {
    try {
      final response = await _apiClient.dio.get('/ratings', queryParameters: {
        'page': page,
        'per_page': perPage,
      });

      if (response.statusCode == 200) {
        final List<dynamic> data = response.data['data'] ?? [];
        return data.map((json) {
          final map = Map<String, dynamic>.from(json as Map);
          final rawScore = map['score'];
          if (rawScore is String) {
            map['score'] = int.tryParse(rawScore) ?? 0;
          } else if (rawScore is num) {
            map['score'] = rawScore.toInt();
          } else if (rawScore == null) {
            map['score'] = 0;
          }
          return RatingModel.fromJson(map);
        }).toList();
      } else {
        throw Exception('Gagal memuat riwayat rating');
      }
    } on DioException catch (e) {
      if (e.response?.statusCode == 401) {
        throw Exception('Sesi telah berakhir');
      }
      throw Exception('Gagal memuat riwayat rating: ${e.message}');
    }
  }
}
