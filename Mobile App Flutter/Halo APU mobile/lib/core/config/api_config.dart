import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

class ApiConfig {
  /// URL cPanel / Staging online
  static const String cpanelUrl = 'https://dev.haloapu.id/api';

  /// Mode pengujian lokal vs cPanel online.
  static const bool useCpanel = false;

  /// IP Host default (127.0.0.1 didukung langsung oleh adb reverse USB)
  static const String defaultLocalHost = '127.0.0.1';
  static const String defaultLocalPort = '8000';

  static String? _customBaseUrl;

  static String get defaultBaseUrl {
    const envUrl = String.fromEnvironment('API_URL');
    if (envUrl.isNotEmpty) {
      return envUrl;
    }

    if (useCpanel) {
      return cpanelUrl;
    }

    if (kIsWeb) {
      return 'http://127.0.0.1:$defaultLocalPort/api';
    } else {
      return 'http://$defaultLocalHost:$defaultLocalPort/api';
    }
  }

  static String get baseUrl {
    return _customBaseUrl ?? defaultBaseUrl;
  }

  static Future<void> loadSavedBaseUrl() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final saved = prefs.getString('custom_api_base_url');
      if (saved != null && saved.trim().isNotEmpty) {
        _customBaseUrl = saved.trim();
      }
    } catch (_) {}
  }

  static Future<void> setCustomBaseUrl(String url) async {
    final clean = url.trim().replaceAll(RegExp(r'/+$'), '');
    _customBaseUrl = clean;
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('custom_api_base_url', clean);
    } catch (_) {}
  }

  static Future<void> resetToDefault() async {
    _customBaseUrl = null;
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove('custom_api_base_url');
    } catch (_) {}
  }

  static Future<bool> testConnection(String url) async {
    final target = url.trim().replaceAll(RegExp(r'/+$'), '');
    final clean = target.endsWith('/api') ? target : '$target/api';
    try {
      final dio = Dio(BaseOptions(
        baseUrl: clean,
        connectTimeout: const Duration(seconds: 4),
        receiveTimeout: const Duration(seconds: 4),
        headers: {'Accept': 'application/json'},
      ));
      final res = await dio.get('/attachments/serve?path=ping');
      // If we get any HTTP response (200, 400, 404, 401), the server is reachable
      return res.statusCode != null && res.statusCode! >= 200 && res.statusCode! < 500;
    } catch (e) {
      if (e is DioException && e.response != null) {
        return true; // Server reached, even if 400/401/404
      }
      return false;
    }
  }
}
