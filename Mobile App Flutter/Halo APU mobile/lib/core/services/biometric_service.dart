import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Lapisan akses lokal ke sesi tersimpan via biometrik.
class BiometricService {
  static const String _flagKey = 'biometric_enabled';
  static const String _bioTokenKey = 'biometric_auth_token';
  static const String _bioRoleKey = 'biometric_user_role';
  static const String _bioNameKey = 'biometric_user_name';
  static const String _bioEmailKey = 'biometric_user_email';
  static const String _bioUserDataKey = 'biometric_user_data';

  final LocalAuthentication _auth = LocalAuthentication();
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  Future<bool> isEnabled() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_flagKey) ?? false;
  }

  Future<void> setEnabled(bool enabled) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_flagKey, enabled);
    if (!enabled) {
      await clearBiometricSession();
    }
  }

  /// Simpan sesi terenkripsi untuk login biometrik cepat
  Future<void> saveBiometricSession({
    required String token,
    required String role,
    required String name,
    required String email,
    required String userData,
  }) async {
    await _storage.write(key: _bioTokenKey, value: token);
    await _storage.write(key: _bioRoleKey, value: role);
    await _storage.write(key: _bioNameKey, value: name);
    await _storage.write(key: _bioEmailKey, value: email);
    await _storage.write(key: _bioUserDataKey, value: userData);
  }

  /// Ambil info akun yang tersimpan untuk login biometrik
  Future<Map<String, String>?> getSavedBiometricUser() async {
    final token = await _storage.read(key: _bioTokenKey);
    if (token == null || token.isEmpty) return null;

    final name = await _storage.read(key: _bioNameKey) ?? 'Pengguna';
    final email = await _storage.read(key: _bioEmailKey) ?? '';
    final role = await _storage.read(key: _bioRoleKey) ?? 'user';

    return {
      'token': token,
      'name': name,
      'email': email,
      'role': role,
    };
  }

  /// Pulihkan sesi aktif dari kredensial biometrik
  Future<bool> restoreSessionFromBiometric() async {
    final token = await _storage.read(key: _bioTokenKey);
    final role = await _storage.read(key: _bioRoleKey);
    final userData = await _storage.read(key: _bioUserDataKey);

    if (token != null && token.isNotEmpty) {
      await _storage.write(key: 'auth_token', value: token);
      if (role != null) await _storage.write(key: 'user_role', value: role);
      if (userData != null) await _storage.write(key: 'user_data', value: userData);
      return true;
    }
    return false;
  }

  /// Hapus kredensial biometrik yang tersimpan
  Future<void> clearBiometricSession() async {
    await _storage.delete(key: _bioTokenKey);
    await _storage.delete(key: _bioRoleKey);
    await _storage.delete(key: _bioNameKey);
    await _storage.delete(key: _bioEmailKey);
    await _storage.delete(key: _bioUserDataKey);
  }

  /// Device punya biometrik terdaftar? (false di web: local_auth tidak mendukung web)
  Future<bool> canAuthenticate() async {
    if (kIsWeb) return false;
    try {
      final isSupported = await _auth.isDeviceSupported();
      final canCheck = await _auth.canCheckBiometrics;
      final available = await _auth.getAvailableBiometrics();
      return isSupported && (canCheck || available.isNotEmpty);
    } catch (_) {
      return false;
    }
  }

  Future<bool> authenticate() async {
    if (kIsWeb) return false;
    try {
      return await _auth.authenticate(
        localizedReason: 'Pindai sidik jari atau biometrik untuk masuk ke Halo APU',
        biometricOnly: false,
        sensitiveTransaction: true,
        persistAcrossBackgrounding: true,
      );
    } catch (e) {
      debugPrint('Biometric authentication error: $e');
      return false;
    }
  }
}
