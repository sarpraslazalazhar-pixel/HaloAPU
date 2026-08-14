import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:halo_apu_mobile/core/config/api_config.dart';
import 'package:halo_apu_mobile/core/services/device_service.dart';

class AuthService {
  Dio get _dio => Dio(BaseOptions(
    baseUrl: ApiConfig.baseUrl,
    connectTimeout: const Duration(seconds: 12),
    receiveTimeout: const Duration(seconds: 12),
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
  ));

  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  Future<Map<String, dynamic>> login(String email, String password, bool isAdmin) async {
    try {
      final deviceId = await DeviceService.getDeviceId();
      final deviceName = await DeviceService.getDeviceName();

      final response = await _dio.post(
        '/login',
        data: {
          'email': email,
          'password': password,
          'is_admin': isAdmin,
          'device_id': deviceId,
          'device_name': deviceName,
        },
      );

      final data = response.data;

      if (response.statusCode == 200) {
        // Simpan token & user info di secure storage untuk persistensi sesi
        await _storage.write(key: 'auth_token', value: data['data']['token']);
        await _storage.write(key: 'user_data', value: jsonEncode(data['data']['user']));
        await _storage.write(key: 'user_role', value: data['data']['role']);

        return {'success': true, 'data': data};
      } else {
        return {'success': false, 'message': data['message'] ?? 'Login gagal'};
      }
    } on DioException catch (e) {
      if (e.response?.data != null && e.response?.data is Map && e.response?.data['message'] != null) {
        return {
          'success': false,
          'message': e.response?.data['message'],
          'error_code': e.response?.data['error_code'],
        };
      }
      
      if (e.type == DioExceptionType.connectionTimeout || e.type == DioExceptionType.receiveTimeout) {
        return {
          'success': false,
          'message': 'Koneksi timeout ke ${ApiConfig.baseUrl}. Periksa jaringan atau setting Host.'
        };
      }
      
      return {
        'success': false,
        'message': 'Gagal terhubung ke ${ApiConfig.baseUrl}. Pastikan server aktif & satu jaringan.'
      };
    } catch (e) {
      return {'success': false, 'message': 'Terjadi kesalahan: $e'};
    }
  }

  Future<void> logout() async {
    try {
      final token = await _storage.read(key: 'auth_token');

      if (token != null) {
        await _dio.post(
          '/logout',
          options: Options(
            headers: {
              'Authorization': 'Bearer $token',
            },
          ),
        );
      }
    } catch (_) {
      // Abaikan jika token gagal di-revoke di server saat offline
    } finally {
      // Hanya hapus sesi user, pertahankan device_id dan biometric setting
      await _storage.delete(key: 'auth_token');
      await _storage.delete(key: 'user_data');
      await _storage.delete(key: 'user_role');
    }
  }
}
