import 'package:flutter/foundation.dart';
import 'package:local_auth/local_auth.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Lapisan akses lokal ke sesi tersimpan via biometrik.
/// Bukan pengganti autentikasi server (PRD 14.1).
class BiometricService {
  static const String _flagKey = 'biometric_enabled';

  final LocalAuthentication _auth = LocalAuthentication();

  Future<bool> isEnabled() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_flagKey) ?? false;
  }

  Future<void> setEnabled(bool enabled) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_flagKey, enabled);
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
