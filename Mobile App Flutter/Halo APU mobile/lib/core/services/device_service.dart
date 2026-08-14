import 'dart:math';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class DeviceService {
  static const String _deviceIdKey = 'app_device_unique_id';
  static const FlutterSecureStorage _storage = FlutterSecureStorage();

  /// Mengambil Device ID unik yang persisten untuk instalasi HP ini
  static Future<String> getDeviceId() async {
    try {
      String? deviceId = await _storage.read(key: _deviceIdKey);
      if (deviceId != null && deviceId.isNotEmpty) {
        return deviceId;
      }

      // Generate unique device ID
      final timestamp = DateTime.now().millisecondsSinceEpoch;
      final randomPart = Random().nextInt(999999).toString().padLeft(6, '0');
      final newDeviceId = 'DEV_${timestamp}_$randomPart';

      await _storage.write(key: _deviceIdKey, value: newDeviceId);
      return newDeviceId;
    } catch (_) {
      return 'DEV_UNKNOWN_${DateTime.now().millisecondsSinceEpoch}';
    }
  }

  /// Mengambil nama perangkat untuk identifikasi
  static Future<String> getDeviceName() async {
    if (kIsWeb) {
      return 'Web Browser';
    } else if (defaultTargetPlatform == TargetPlatform.android) {
      return 'Smartphone Android';
    } else if (defaultTargetPlatform == TargetPlatform.iOS) {
      return 'Apple iPhone / iOS';
    }
    return 'Mobile Device';
  }
}
