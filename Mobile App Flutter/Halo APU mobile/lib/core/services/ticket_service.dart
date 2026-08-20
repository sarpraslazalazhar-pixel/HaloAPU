import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:image_picker/image_picker.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http_parser/http_parser.dart';
import 'package:mime/mime.dart';
import '../config/api_config.dart';

class TicketService {
  final Dio _dio = Dio(BaseOptions(
    baseUrl: ApiConfig.baseUrl,
    connectTimeout: const Duration(seconds: 15),
    receiveTimeout: const Duration(seconds: 15),
    headers: {
      'Accept': 'application/json',
    },
  ));

  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  TicketService() {
    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        final token = await _storage.read(key: 'auth_token');
        if (token != null) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        return handler.next(options);
      },
    ));
  }

  Future<MultipartFile> _createMultipartFile(XFile file) async {
    final bytes = await file.readAsBytes();
    String mimeType = lookupMimeType(file.name, headerBytes: bytes) ?? 'application/octet-stream';
    
    // Fallback: detect image type from magic bytes if octet-stream
    if (mimeType == 'application/octet-stream') {
      if (bytes.length >= 2 && bytes[0] == 0xFF && bytes[1] == 0xD8) {
        mimeType = 'image/jpeg';
      } else if (bytes.length >= 8 && bytes[0] == 0x89 && bytes[1] == 0x50 && bytes[2] == 0x4E && bytes[3] == 0x47) {
        mimeType = 'image/png';
      }
    }

    final mediaType = MediaType.parse(mimeType);

    String name = file.name.trim();
    if (name.isEmpty || name == 'attachment' || name == 'image_picker' || !name.contains('.')) {
      final ext = mimeType.contains('png') ? 'png' : (mimeType.contains('pdf') ? 'pdf' : 'jpg');
      final base = (name.isEmpty || name == 'attachment' || name == 'image_picker') ? 'foto_${DateTime.now().millisecondsSinceEpoch}' : name;
      name = '$base.$ext';
    }

    return MultipartFile.fromBytes(
      bytes,
      filename: name,
      contentType: mediaType,
    );
  }

  // ============ SERVICES ============

  /// Get all service categories (Units with SubUnits)
  Future<Map<String, dynamic>> getServices() async {
    try {
      final response = await _dio.get('/services');
      return {'success': true, 'data': response.data['data']};
    } on DioException catch (e) {
      return {
        'success': false,
        'message': e.response?.data['message'] ?? 'Gagal memuat layanan'
      };
    }
  }

  /// Get form fields for a specific sub unit
  Future<Map<String, dynamic>> getFormFields(int subUnitId) async {
    try {
      final response = await _dio.get('/services/$subUnitId/fields');
      return {'success': true, 'data': response.data['data']};
    } on DioException catch (e) {
      return {
        'success': false,
        'message': e.response?.data['message'] ?? 'Gagal memuat form'
      };
    }
  }

  // ============ TICKETS ============

  Future<Map<String, dynamic>> getTickets({
    List<String>? statuses,
    String? dateFrom,
    String? dateTo,
    String? search,
    int page = 1,
  }) async {
    try {
      final Map<String, dynamic> queryParameters = {
        'page': page,
        if (dateFrom != null) 'date_from': dateFrom,
        if (dateTo != null) 'date_to': dateTo,
        if (search != null && search.isNotEmpty) 'search': search,
      };

      if (statuses != null && statuses.isNotEmpty) {
        queryParameters['status'] = statuses.join(',');
      }

      final response = await _dio.get('/tickets', queryParameters: queryParameters);
      return {
        'success': true,
        'data': response.data['data'],
        'meta': response.data['meta']
      };
    } on DioException catch (e) {
      return {
        'success': false,
        'message': e.response?.data['message'] ?? 'Gagal mengambil tiket'
      };
    }
  }

  Future<Map<String, dynamic>> getTicketDetail(String id) async {
    try {
      final response = await _dio.get('/tickets/$id');
      return {'success': true, 'data': response.data['data']};
    } on DioException catch (e) {
      return {
        'success': false,
        'message': e.response?.data['message'] ?? 'Gagal memuat tiket'
      };
    }
  }

  /// Create a new ticket with form_data and optional file attachments
  Future<Map<String, dynamic>> createTicket({
    required int subUnitId,
    required Map<String, dynamic> formData,
    String priority = 'normal',
    Map<String, List<XFile>>? attachments,
  }) async {
    try {
      final Map<String, dynamic> dataMap = {
        'sub_unit_id': subUnitId.toString(),
        'priority': priority,
        'form_data': jsonEncode(formData),
      };

      if (attachments != null && attachments.isNotEmpty) {
        final formDataObj = FormData.fromMap(dataMap);

        for (var entry in attachments.entries) {
          final fieldKey = entry.key;
          for (var file in entry.value) {
            final multipartFile = await _createMultipartFile(file);
            formDataObj.files.add(MapEntry(
              'attachments[$fieldKey][]',
              multipartFile,
            ));
          }
        }

        final response = await _dio.post('/tickets', data: formDataObj);
        return {
          'success': true,
          'data': response.data['data'],
          'message': response.data['message']
        };
      } else {
        final response = await _dio.post('/tickets', data: dataMap);
        return {
          'success': true,
          'data': response.data['data'],
          'message': response.data['message']
        };
      }
    } on DioException catch (e) {
      return {
        'success': false,
        'message': e.response?.data['message'] ?? 'Gagal membuat tiket'
      };
    }
  }

  Future<Map<String, dynamic>> changeStatus(
    String id,
    String newStatus, {
    String? catatan,
    List<XFile>? attachments,
  }) async {
    try {
      if (attachments == null || attachments.isEmpty) {
        final response = await _dio.post(
          '/tickets/$id/status',
          data: {
            'status': newStatus,
            if (catatan != null && catatan.isNotEmpty) 'catatan': catatan,
          },
        );
        return {'success': true, 'message': response.data['message']};
      } else {
        final formData = FormData.fromMap({
          'status': newStatus,
          if (catatan != null && catatan.isNotEmpty) 'catatan': catatan,
        });

        for (var file in attachments) {
          final multipartFile = await _createMultipartFile(file);
          formData.files.add(MapEntry('general_attachments[]', multipartFile));
        }

        final response = await _dio.post('/tickets/$id/status', data: formData);
        return {'success': true, 'message': response.data['message']};
      }
    } on DioException catch (e) {
      return {
        'success': false,
        'message': e.response?.data['message'] ?? 'Gagal mengubah status'
      };
    }
  }

  Future<Map<String, dynamic>> assignOperator(String id, int adminId) async {
    try {
      final response = await _dio.post(
        '/tickets/$id/assign',
        data: {'admin_id': adminId},
      );
      return {'success': true, 'data': response.data['data']};
    } on DioException catch (e) {
      return {
        'success': false,
        'message': e.response?.data['message'] ?? 'Gagal menugaskan operator'
      };
    }
  }

  Future<Map<String, dynamic>> replyTicket(
    String id,
    String note, {
    List<XFile>? attachments,
  }) async {
    try {
      final cleanId = id.replaceAll('-', '');
      if (attachments != null && attachments.isNotEmpty) {
        final formData = FormData.fromMap({
          'catatan': note,
        });

        for (var file in attachments) {
          final multipartFile = await _createMultipartFile(file);
          formData.files.add(MapEntry('attachments[]', multipartFile));
        }

        final response = await _dio.post('/tickets/$cleanId/reply', data: formData);
        return {'success': true, 'data': response.data['data']};
      } else {
        final response = await _dio.post(
          '/tickets/$cleanId/reply',
          data: {'catatan': note},
        );
        return {'success': true, 'data': response.data['data']};
      }
    } on DioException catch (e) {
      return {
        'success': false,
        'message': e.response?.data['message'] ?? e.error?.toString() ?? 'Gagal membalas tiket'
      };
    } catch (e) {
      return {
        'success': false,
        'message': 'Gagal membalas tiket: $e'
      };
    }
  }

  /// Cancel a ticket (only if status == 'open')
  Future<Map<String, dynamic>> cancelTicket(String id) async {
    try {
      final response = await _dio.patch('/tickets/$id/cancel');
      return {'success': true, 'message': response.data['message']};
    } on DioException catch (e) {
      return {
        'success': false,
        'message': e.response?.data['message'] ?? 'Gagal membatalkan tiket'
      };
    }
  }

  /// Accept the result of a solved ticket
  Future<Map<String, dynamic>> acceptResult(String id) async {
    try {
      final response = await _dio.post('/tickets/$id/accept');
      return {'success': true, 'message': response.data['message']};
    } on DioException catch (e) {
      return {
        'success': false,
        'message': e.response?.data['message'] ?? 'Gagal menerima hasil'
      };
    }
  }

  /// Request revision on a solved ticket
  Future<Map<String, dynamic>> requestRevision(
    String id,
    String note, {
    List<XFile>? attachments,
  }) async {
    try {
      if (attachments != null && attachments.isNotEmpty) {
        final formData = FormData.fromMap({
          'catatan': note,
        });

        for (var file in attachments) {
          final multipartFile = await _createMultipartFile(file);
          formData.files.add(MapEntry('attachments[]', multipartFile));
        }

        final response = await _dio.post('/tickets/$id/revision', data: formData);
        return {'success': true, 'message': response.data['message']};
      } else {
        final response = await _dio.post(
          '/tickets/$id/revision',
          data: {'catatan': note},
        );
        return {'success': true, 'message': response.data['message']};
      }
    } on DioException catch (e) {
      return {
        'success': false,
        'message': e.response?.data['message'] ?? 'Gagal mengajukan revisi'
      };
    }
  }

  /// Submit CSAT rating for a ticket
  Future<Map<String, dynamic>> rateTicket(String id, int rating, {String? comment}) async {
    try {
      final response = await _dio.post(
        '/tickets/$id/rate',
        data: {
          'rating': rating,
          if (comment != null && comment.isNotEmpty) 'komentar': comment,
        },
      );
      return {'success': true, 'message': response.data['message']};
    } on DioException catch (e) {
      return {
        'success': false,
        'message': e.response?.data['message'] ?? 'Gagal memberi rating'
      };
    }
  }
}
