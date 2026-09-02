class ApiConfig {
  /// URL API Server Utama
  static const String mainApiUrl = 'https://dev.haloapu.id/api';

  static String get defaultBaseUrl => mainApiUrl;

  static String get baseUrl => mainApiUrl;

  static Future<void> loadSavedBaseUrl() async {}
}
