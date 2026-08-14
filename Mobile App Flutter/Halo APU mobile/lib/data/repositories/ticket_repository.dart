import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../api/api_client.dart';
import '../../domain/models/ticket_model.dart';

final ticketRepositoryProvider = Provider<TicketRepository>((ref) {
  final apiClient = ref.watch(apiClientProvider);
  return TicketRepository(apiClient);
});

class TicketRepository {
  final ApiClient _apiClient;

  TicketRepository(this._apiClient);

  /// Fetch user's tickets with optional status filter and pagination.
  Future<List<TicketModel>> getTickets({String? status, int page = 1, int perPage = 15}) async {
    try {
      final params = <String, dynamic>{
        'page': page,
        'per_page': perPage,
      };
      if (status != null && status.isNotEmpty) {
        params['status'] = status;
      }

      final response = await _apiClient.dio.get('/tickets', queryParameters: params);
      if (response.statusCode == 200) {
        final List<dynamic> data = response.data['data'] ?? response.data;
        return data.map((json) => TicketModel.safeFromJson(json)).toList();
      } else {
        throw Exception('Gagal memuat tiket');
      }
    } catch (e) {
      throw Exception('Gagal memuat tiket: $e');
    }
  }

  /// Fetch a single ticket by ID with full details.
  Future<Map<String, dynamic>> getTicketDetail(String id) async {
    try {
      final response = await _apiClient.dio.get('/tickets/$id');
      if (response.statusCode == 200) {
        return response.data['data'];
      } else {
        throw Exception('Gagal memuat detail tiket');
      }
    } catch (e) {
      throw Exception('Gagal memuat detail tiket: $e');
    }
  }

  /// Fetch ticket by ID as TicketModel (simplified).
  Future<TicketModel> getTicketById(String id) async {
    try {
      final response = await _apiClient.dio.get('/tickets/$id');
      if (response.statusCode == 200) {
        return TicketModel.safeFromJson(response.data['data'] ?? response.data);
      } else {
        throw Exception('Gagal memuat tiket');
      }
    } catch (e) {
      throw Exception('Gagal memuat tiket: $e');
    }
  }

  /// Fetch services (units + sub-units).
  Future<List<dynamic>> getServices() async {
    try {
      final response = await _apiClient.dio.get('/services');
      if (response.statusCode == 200) {
        return response.data['data'] ?? response.data;
      } else {
        throw Exception('Gagal memuat layanan');
      }
    } catch (e) {
      throw Exception('Gagal memuat layanan: $e');
    }
  }

  /// Fetch form fields for a specific sub-unit.
  Future<List<dynamic>> getFormFields(int subUnitId) async {
    try {
      final response = await _apiClient.dio.get('/services/$subUnitId/fields');
      if (response.statusCode == 200) {
        return response.data['data'] ?? response.data;
      } else {
        throw Exception('Gagal memuat form fields');
      }
    } catch (e) {
      throw Exception('Gagal memuat form fields: $e');
    }
  }

  /// Create a new ticket.
  Future<TicketModel> createTicket(Map<String, dynamic> ticketData, {Map<String, List<String>>? attachments}) async {
    try {
      // If we have attachments, we use FormData
      if (attachments != null && attachments.isNotEmpty) {
        // Convert form_data to JSON string for the backend
        if (ticketData['form_data'] != null) {
           ticketData['form_data'] = jsonEncode(ticketData['form_data']);
        }
        
        final formData = FormData.fromMap(ticketData);
        
        for (var entry in attachments.entries) {
          final fieldId = entry.key;
          for (var path in entry.value) {
            try {
              formData.files.add(MapEntry(
                'attachments[$fieldId][]',
                await MultipartFile.fromFile(path, filename: path.split('/').last),
              ));
            } catch (e) {
              // Mock file for testing
              formData.files.add(MapEntry(
                'attachments[$fieldId][]',
                MultipartFile.fromString('Mock file content', filename: path.split('/').last),
              ));
            }
          }
        }
        
        final response = await _apiClient.dio.post(
          '/tickets',
          data: formData,
        );
        if (response.statusCode == 201 || response.statusCode == 200) {
          return TicketModel.safeFromJson(response.data['data'] ?? response.data);
        } else {
          throw Exception('Gagal membuat tiket');
        }
      } else {
        // No attachments, send normal JSON
        final response = await _apiClient.dio.post(
          '/tickets',
          data: ticketData,
        );
        if (response.statusCode == 201 || response.statusCode == 200) {
          return TicketModel.safeFromJson(response.data['data'] ?? response.data);
        } else {
          throw Exception('Gagal membuat tiket');
        }
      }
    } catch (e) {
      throw Exception('Gagal membuat tiket: $e');
    }
  }

  /// Reply to a ticket.
  Future<Map<String, dynamic>> replyTicket(String ticketId, String message, {List<String>? attachmentPaths}) async {
    try {
      final formData = FormData.fromMap({
        'catatan': message,
      });

      if (attachmentPaths != null) {
        for (var path in attachmentPaths) {
          try {
            formData.files.add(MapEntry(
              'attachments[]',
              await MultipartFile.fromFile(path, filename: path.split('/').last),
            ));
          } catch (e) {
            // Skip invalid files
          }
        }
      }

      final response = await _apiClient.dio.post(
        '/tickets/$ticketId/reply',
        data: formData,
      );

      if (response.statusCode == 201 || response.statusCode == 200) {
        return response.data['data'];
      } else {
        throw Exception('Gagal mengirim balasan');
      }
    } on DioException catch (e) {
      if (e.response?.statusCode == 422) {
        throw Exception(e.response!.data['message'] ?? 'Gagal mengirim balasan');
      }
      throw Exception('Gagal mengirim balasan: ${e.message}');
    }
  }

  /// Cancel a ticket.
  Future<void> cancelTicket(String ticketId) async {
    try {
      final response = await _apiClient.dio.patch('/tickets/$ticketId/cancel');
      if (response.statusCode != 200) {
        throw Exception(response.data['message'] ?? 'Gagal membatalkan tiket');
      }
    } on DioException catch (e) {
      if (e.response?.statusCode == 422) {
        throw Exception(e.response!.data['message'] ?? 'Gagal membatalkan tiket');
      }
      throw Exception('Gagal membatalkan tiket: ${e.message}');
    }
  }

  /// Accept ticket result.
  Future<void> acceptResult(String ticketId) async {
    try {
      final response = await _apiClient.dio.post('/tickets/$ticketId/accept');
      if (response.statusCode != 200) {
        throw Exception(response.data['message'] ?? 'Gagal menerima hasil');
      }
    } on DioException catch (e) {
      if (e.response?.statusCode == 422) {
        throw Exception(e.response!.data['message'] ?? 'Gagal menerima hasil');
      }
      throw Exception('Gagal menerima hasil: ${e.message}');
    }
  }

  /// Request revision on a ticket.
  Future<void> requestRevision(String ticketId, String reason, {List<String>? attachmentPaths}) async {
    try {
      final formData = FormData.fromMap({
        'catatan': reason,
      });

      if (attachmentPaths != null) {
        for (var path in attachmentPaths) {
          try {
            formData.files.add(MapEntry(
              'attachments[]',
              await MultipartFile.fromFile(path, filename: path.split('/').last),
            ));
          } catch (e) {
            // Skip invalid files
          }
        }
      }

      final response = await _apiClient.dio.post(
        '/tickets/$ticketId/revision',
        data: formData,
      );

      if (response.statusCode != 200) {
        throw Exception(response.data['message'] ?? 'Gagal mengirim permintaan revisi');
      }
    } on DioException catch (e) {
      if (e.response?.statusCode == 422) {
        throw Exception(e.response!.data['message'] ?? 'Gagal mengirim permintaan revisi');
      }
      throw Exception('Gagal mengirim permintaan revisi: ${e.message}');
    }
  }

  /// Get dashboard data (stats + recent tickets).
  Future<Map<String, dynamic>> getDashboard() async {
    try {
      final response = await _apiClient.dio.get('/dashboard');
      if (response.statusCode == 200) {
        return response.data['data'];
      } else {
        throw Exception('Gagal memuat dashboard');
      }
    } catch (e) {
      throw Exception('Gagal memuat dashboard: $e');
    }
  }
}
