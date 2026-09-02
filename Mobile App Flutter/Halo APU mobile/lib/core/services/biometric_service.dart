import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Layanan Autentikasi Biometrik (Sidik Jari / Face ID) dengan penyimpanan aman
class BiometricService {
  static const String _flagKey = 'biometric_enabled';
  static const String _bioEmailKey = 'biometric_user_email';
  static const String _bioPasswordKey = 'biometric_user_password';
  static const String _bioNameKey = 'biometric_user_name';
  static const String _bioRoleKey = 'biometric_user_role';

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

  /// Simpan kredensial terenkripsi di Hardware Keystore untuk login biometrik cepat
  Future<void> saveBiometricCredentials({
    required String email,
    required String password,
    required String name,
    required String role,
  }) async {
    await _storage.write(key: _bioEmailKey, value: email);
    await _storage.write(key: _bioPasswordKey, value: password);
    await _storage.write(key: _bioNameKey, value: name);
    await _storage.write(key: _bioRoleKey, value: role);
  }

  /// Ambil info profil tersimpan untuk tampilan kartu di layar Login
  Future<Map<String, String>?> getSavedBiometricUser() async {
    final email = await _storage.read(key: _bioEmailKey);
    final password = await _storage.read(key: _bioPasswordKey);
    if (email == null || email.isEmpty || password == null || password.isEmpty) {
      return null;
    }

    final name = await _storage.read(key: _bioNameKey) ?? 'Pengguna';
    final role = await _storage.read(key: _bioRoleKey) ?? 'user';

    return {
      'email': email,
      'name': name,
      'role': role,
    };
  }

  /// Ambil kredensial lengkap saat verifikasi sidik jari sukses untuk login ke server
  Future<Map<String, String>?> getBiometricCredentials() async {
    final email = await _storage.read(key: _bioEmailKey);
    final password = await _storage.read(key: _bioPasswordKey);
    final role = await _storage.read(key: _bioRoleKey) ?? 'user';
    final name = await _storage.read(key: _bioNameKey) ?? 'Pengguna';

    if (email != null && email.isNotEmpty && password != null && password.isNotEmpty) {
      return {
        'email': email,
        'password': password,
        'role': role,
        'name': name,
      };
    }
    return null;
  }

  /// Hapus kredensial biometrik yang tersimpan
  Future<void> clearBiometricSession() async {
    await _storage.delete(key: _bioEmailKey);
    await _storage.delete(key: _bioPasswordKey);
    await _storage.delete(key: _bioNameKey);
    await _storage.delete(key: _bioRoleKey);
  }

  /// Cek apakah perangkat mendukung dan memiliki sensor biometrik aktif
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

  /// Tampilkan dialog sensor sidik jari / Face ID native OS
  Future<bool> authenticate() async {
    if (kIsWeb) return false;
    try {
      return await _auth.authenticate(
        localizedReason: 'Pindai sidik jari untuk masuk ke Halo APU',
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
