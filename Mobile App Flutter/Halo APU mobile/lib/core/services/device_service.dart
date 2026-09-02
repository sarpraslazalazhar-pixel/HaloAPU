import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class DeviceService {
  static const String _deviceIdKey = 'app_device_hardware_id';
  static const FlutterSecureStorage _storage = FlutterSecureStorage();
  static final DeviceInfoPlugin _deviceInfo = DeviceInfoPlugin();

  /// Mengambil Device ID Hardware unik & permanen (tetap sama meski aplikasi di-uninstall)
  static Future<String> getDeviceId() async {
    try {
      // 1. Cek apakah sudah pernah tersimpan di local storage
      String? cachedId = await _storage.read(key: _deviceIdKey);
      if (cachedId != null && cachedId.isNotEmpty && !cachedId.startsWith('DEV_UNKNOWN')) {
        return cachedId;
      }

      String hardwareIdentifier = '';

      if (kIsWeb) {
        final webInfo = await _deviceInfo.webBrowserInfo;
        hardwareIdentifier = 'WEB_${webInfo.browserName.name}_${webInfo.userAgent?.hashCode.abs().toRadixString(16).toUpperCase()}';
      } else if (defaultTargetPlatform == TargetPlatform.android) {
        final androidInfo = await _deviceInfo.androidInfo;
        // Kombinasi identitas hardware permanen
        final rawKey = '${androidInfo.brand}_${androidInfo.model}_${androidInfo.id}_${androidInfo.fingerprint}';
        final hash = (rawKey.hashCode.abs() & 0xFFFFFFFFFFFFFFFF).toRadixString(16).padLeft(12, '0').toUpperCase();
        hardwareIdentifier = 'AND_$hash';
      } else if (defaultTargetPlatform == TargetPlatform.iOS) {
        final iosInfo = await _deviceInfo.iosInfo;
        final vendorId = iosInfo.identifierForVendor ?? '${iosInfo.model}_${iosInfo.utsname.machine}';
        final hash = (vendorId.hashCode.abs() & 0xFFFFFFFFFFFFFFFF).toRadixString(16).padLeft(12, '0').toUpperCase();
        hardwareIdentifier = 'IOS_$hash';
      } else {
        hardwareIdentifier = 'DEV_${defaultTargetPlatform.name.toUpperCase()}';
      }

      await _storage.write(key: _deviceIdKey, value: hardwareIdentifier);
      return hardwareIdentifier;
    } catch (_) {
      return 'DEV_LEGACY_DEFAULT';
    }
  }

  /// Mengambil nama model perangkat nyata (misal: "XIAOMI 2201117PG" atau "SAMSUNG SM-S918B")
  static Future<String> getDeviceName() async {
    try {
      if (kIsWeb) {
        final webInfo = await _deviceInfo.webBrowserInfo;
        return 'Web Browser (${webInfo.browserName.name})';
      } else if (defaultTargetPlatform == TargetPlatform.android) {
        final androidInfo = await _deviceInfo.androidInfo;
        final brand = androidInfo.brand.isNotEmpty ? androidInfo.brand.toUpperCase() : 'Android';
        final model = androidInfo.model;
        return '$brand $model';
      } else if (defaultTargetPlatform == TargetPlatform.iOS) {
        final iosInfo = await _deviceInfo.iosInfo;
        return iosInfo.name.isNotEmpty ? iosInfo.name : 'Apple iPhone';
      }
      return 'Smartphone';
    } catch (_) {
      return 'Smartphone Android';
    }
  }
}
