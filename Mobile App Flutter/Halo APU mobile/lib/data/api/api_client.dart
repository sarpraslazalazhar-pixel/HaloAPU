import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pretty_dio_logger/pretty_dio_logger.dart';
import '../../core/config/api_config.dart';

final apiClientProvider = Provider<ApiClient>((ref) {
  return ApiClient();
});

class ApiClient {
  late final Dio _dio;
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  ApiClient() {
    _dio = Dio(BaseOptions(
      baseUrl: ApiConfig.baseUrl,
      connectTimeout: const Duration(seconds: 15),
      receiveTimeout: const Duration(seconds: 15),
      headers: {
        'Accept': 'application/json',
      },
    ));

    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        options.baseUrl = ApiConfig.baseUrl;
        // Otomatis inject auth token jika tersedia di local storage
        final token = await _storage.read(key: 'auth_token');
        if (token != null && token.isNotEmpty) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        return handler.next(options);
      },
      onError: (DioException e, handler) {
        // Penanganan error global ramah pengguna
        String errorMessage = 'Terjadi kesalahan sistem';

        if (e.type == DioExceptionType.connectionTimeout ||
            e.type == DioExceptionType.receiveTimeout) {
          errorMessage = 'Koneksi ke server habis (Timeout). Silakan coba lagi.';
        } else if (e.type == DioExceptionType.connectionError) {
          errorMessage = 'Tidak dapat terhubung ke server. Periksa jaringan Anda.';
        } else if (e.response != null) {
          final data = e.response?.data;
          if (data is Map && data.containsKey('message')) {
            errorMessage = data['message'].toString();
          } else {
            switch (e.response?.statusCode) {
              case 400:
                errorMessage = 'Permintaan tidak valid.';
                break;
              case 401:
                errorMessage = 'Sesi telah berakhir. Silakan login kembali.';
                break;
              case 403:
                errorMessage = 'Anda tidak memiliki hak akses.';
                break;
              case 404:
                errorMessage = 'Data tidak ditemukan di server.';
                break;
              case 500:
                errorMessage = 'Kesalahan server internal. Hubungi admin.';
                break;
            }
          }
        }

        final customException = DioException(
          requestOptions: e.requestOptions,
          response: e.response,
          type: e.type,
          error: errorMessage,
        );

        return handler.next(customException);
      },
    ));

    // Logger hanya saat debug mode
    if (kDebugMode) {
      _dio.interceptors.add(PrettyDioLogger(
        requestHeader: true,
        requestBody: true,
        responseBody: true,
        responseHeader: false,
        error: true,
        compact: true,
        maxWidth: 90,
      ));
    }
  }

  Dio get dio => _dio;
}
