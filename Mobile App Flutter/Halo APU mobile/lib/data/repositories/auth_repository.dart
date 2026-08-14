import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../api/api_client.dart';
import '../../domain/models/user_profile_model.dart';

final authRepositoryProvider = Provider<AuthRepository>((ref) {
  final apiClient = ref.watch(apiClientProvider);
  return AuthRepository(apiClient);
});

class AuthRepository {
  final ApiClient _apiClient;
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  AuthRepository(this._apiClient);

  /// Login with email/username and password.
  /// Returns the user profile data on success.
  Future<UserProfile> login(String email, String password, {bool isAdmin = false}) async {
    try {
      final response = await _apiClient.dio.post(
        '/login',
        data: {
          'email': email,
          'password': password,
          'is_admin': isAdmin,
        },
      );

      if (response.statusCode == 200) {
        final data = response.data['data'];
        final token = data['token'];
        final userData = data['user'];
        final role = data['role'] ?? 'user';

        // Save token
        await _storage.write(key: 'auth_token', value: token);
        await _storage.write(key: 'user_role', value: role);

        // Save user data as JSON
        await _storage.write(key: 'user_data', value: jsonEncode(userData));

        return UserProfile.fromJson(userData);
      } else {
        throw Exception('Gagal login: ${response.data['message']}');
      }
    } on DioException catch (e) {
      if (e.response != null && e.response!.statusCode == 401) {
        throw Exception('Email/username atau password salah');
      }
      if (e.response != null && e.response!.statusCode == 422) {
        final errors = e.response!.data['errors'];
        if (errors != null) {
          final firstError = (errors as Map).values.first;
          throw Exception(firstError is List ? firstError.first : firstError.toString());
        }
      }
      throw Exception('Gagal menghubungi server: ${e.message}');
    } catch (e) {
      if (e is Exception) rethrow;
      throw Exception('Terjadi kesalahan: $e');
    }
  }

  /// Logout user and clear stored data.
  Future<void> logout() async {
    try {
      await _apiClient.dio.post('/logout');
    } catch (e) {
      // Ignore errors on logout
    } finally {
      await _storage.delete(key: 'auth_token');
      await _storage.delete(key: 'user_role');
      await _storage.delete(key: 'user_data');
    }
  }

  /// Check if user is currently logged in (has a stored token).
  Future<bool> isLoggedIn() async {
    final token = await _storage.read(key: 'auth_token');
    return token != null && token.isNotEmpty;
  }

  /// Get stored user data from secure storage (cached from last login/fetch).
  Future<UserProfile?> getStoredUser() async {
    final userData = await _storage.read(key: 'user_data');
    if (userData != null) {
      try {
        return UserProfile.fromJson(jsonDecode(userData));
      } catch (e) {
        return null;
      }
    }
    return null;
  }

  /// Fetch latest user profile from API and update local cache.
  Future<UserProfile> fetchProfile() async {
    try {
      final response = await _apiClient.dio.get('/user');
      if (response.statusCode == 200) {
        final userData = response.data['data'];
        // Update cached user data
        await _storage.write(key: 'user_data', value: jsonEncode(userData));
        return UserProfile.fromJson(userData);
      } else {
        throw Exception('Gagal memuat profil');
      }
    } on DioException catch (e) {
      if (e.response?.statusCode == 401) {
        // Token expired, clear data
        await _storage.delete(key: 'auth_token');
        await _storage.delete(key: 'user_data');
        throw Exception('Sesi telah berakhir, silakan login kembali');
      }
      throw Exception('Gagal menghubungi server: ${e.message}');
    }
  }

  /// Update user profile.
  Future<UserProfile> updateProfile({
    String? name,
    String? username,
    String? email,
    String? phone,
  }) async {
    final data = <String, dynamic>{};
    if (name != null) data['name'] = name;
    if (username != null) data['username'] = username;
    if (email != null) data['email'] = email;
    if (phone != null) data['no_wa'] = phone;

    try {
      final response = await _apiClient.dio.put('/user', data: data);
      if (response.statusCode == 200) {
        final userData = response.data['data'];
        await _storage.write(key: 'user_data', value: jsonEncode(userData));
        return UserProfile.fromJson(userData);
      } else {
        throw Exception('Gagal memperbarui profil');
      }
    } on DioException catch (e) {
      if (e.response?.statusCode == 422) {
        final errors = e.response!.data['errors'];
        if (errors != null) {
          final firstError = (errors as Map).values.first;
          throw Exception(firstError is List ? firstError.first : firstError.toString());
        }
      }
      throw Exception('Gagal memperbarui profil: ${e.message}');
    }
  }

  /// Upload avatar image.
  Future<UserProfile> uploadAvatar(String filePath) async {
    try {
      final formData = FormData.fromMap({
        'avatar': await MultipartFile.fromFile(filePath, filename: filePath.split('/').last),
      });

      final response = await _apiClient.dio.post('/user/avatar', data: formData);
      if (response.statusCode == 200) {
        final userData = response.data['data'];
        await _storage.write(key: 'user_data', value: jsonEncode(userData));
        return UserProfile.fromJson(userData);
      } else {
        throw Exception('Gagal mengupload foto profil');
      }
    } on DioException catch (e) {
      throw Exception('Gagal mengupload foto: ${e.message}');
    }
  }

  /// Change password.
  Future<void> changePassword({
    required String currentPassword,
    required String newPassword,
    required String confirmPassword,
  }) async {
    try {
      final response = await _apiClient.dio.post('/user/change-password', data: {
        'current_password': currentPassword,
        'password': newPassword,
        'password_confirmation': confirmPassword,
      });

      if (response.statusCode != 200) {
        throw Exception(response.data['message'] ?? 'Gagal mengubah password');
      }
    } on DioException catch (e) {
      if (e.response?.statusCode == 422) {
        final message = e.response!.data['message'] ?? 'Password lama tidak sesuai';
        throw Exception(message);
      }
      throw Exception('Gagal mengubah password: ${e.message}');
    }
  }
}
