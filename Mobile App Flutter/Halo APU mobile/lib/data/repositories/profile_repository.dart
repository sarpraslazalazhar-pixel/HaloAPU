import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../api/api_client.dart';

final profileRepositoryProvider = Provider<ProfileRepository>((ref) {
  final apiClient = ref.watch(apiClientProvider);
  return ProfileRepository(apiClient);
});

class ProfileRepository {
  final ApiClient _apiClient;
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  ProfileRepository(this._apiClient);

  Future<Map<String, dynamic>> getProfile() async {
    try {
      final response = await _apiClient.dio.get('/user');
      if (response.statusCode == 200) {
        final data = response.data['data'];
        await _storage.write(key: 'user_data', value: jsonEncode(data));
        return {'success': true, 'data': data};
      }
      return {'success': false, 'message': 'Gagal mengambil profil'};
    } on DioException catch (e) {
      return {'success': false, 'message': e.error?.toString() ?? 'Kesalahan saat mengambil profil'};
    }
  }

  Future<Map<String, dynamic>> updateProfile(Map<String, dynamic> updateData) async {
    try {
      final response = await _apiClient.dio.put('/user', data: updateData);
      if (response.statusCode == 200) {
        final data = response.data['data'];
        await _storage.write(key: 'user_data', value: jsonEncode(data));
        return {'success': true, 'data': data};
      }
      return {'success': false, 'message': 'Gagal perbarui profil'};
    } on DioException catch (e) {
      return {'success': false, 'message': e.error?.toString() ?? 'Kesalahan saat perbarui profil'};
    }
  }

  Future<Map<String, dynamic>> uploadAvatar(dynamic fileInput) async {
    try {
      MultipartFile multipartFile;
      if (fileInput is List<int>) {
        multipartFile = MultipartFile.fromBytes(
          fileInput,
          filename: 'avatar_${DateTime.now().millisecondsSinceEpoch}.jpg',
        );
      } else if (fileInput is String) {
        // Fallback for path if needed
        multipartFile = await MultipartFile.fromFile(fileInput, filename: fileInput.split('/').last);
      } else {
        // XFile or similar
        final bytes = await fileInput.readAsBytes();
        final name = fileInput.name ?? 'avatar_${DateTime.now().millisecondsSinceEpoch}.jpg';
        multipartFile = MultipartFile.fromBytes(bytes, filename: name);
      }

      final formData = FormData.fromMap({
        'avatar': multipartFile,
      });

      final response = await _apiClient.dio.post(
        '/user/avatar',
        data: formData,
      );

      if (response.statusCode == 200) {
        final data = response.data['data'];
        await _storage.write(key: 'user_data', value: jsonEncode(data));
        return {'success': true, 'data': data};
      }
      return {'success': false, 'message': 'Gagal upload foto profil'};
    } on DioException catch (e) {
      return {'success': false, 'message': e.error?.toString() ?? 'Kesalahan upload foto'};
    } catch (e) {
      return {'success': false, 'message': 'Gagal upload foto: $e'};
    }
  }

  Future<Map<String, dynamic>> deleteAccount() async {
    try {
      final response = await _apiClient.dio.delete('/user');
      if (response.statusCode == 200) {
        await _storage.deleteAll();
        return {
          'success': true,
          'message': response.data?['message'] ?? 'Akun berhasil dihapus permanen'
        };
      }
      return {'success': false, 'message': 'Gagal menghapus akun'};
    } on DioException catch (e) {
      return {'success': false, 'message': e.error?.toString() ?? 'Kesalahan saat menghapus akun'};
    } catch (e) {
      return {'success': false, 'message': 'Gagal menghapus akun: $e'};
    }
  }
}
