import 'dart:async';
import 'dart:convert';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:halo_apu_mobile/core/router/app_router.dart';
import 'package:halo_apu_mobile/data/api/api_client.dart';
import 'package:halo_apu_mobile/domain/models/ticket_model.dart';

/// Top-level background message handler for Firebase Messaging
@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  try {
    await Firebase.initializeApp();
  } catch (_) {}
  debugPrint('FCM Background message received: ${message.messageId}');
}

class PushNotificationService {
  static final FlutterLocalNotificationsPlugin _localNotifications =
      FlutterLocalNotificationsPlugin();

  static const AndroidNotificationChannel _channel = AndroidNotificationChannel(
    'halo_apu_high_importance_v2',
    'Notifikasi Tiket Halo APU',
    description: 'Channel prioritas tinggi untuk pembaruan tiket dan pesan masuk.',
    importance: Importance.max,
    playSound: true,
    enableVibration: true,
    showBadge: true,
  );

  static bool _isInitialized = false;
  static Timer? _pollingTimer;
  static String? _lastKnownNotifId;
  static VoidCallback? onNotificationReceived;

  /// Inisialisasi Firebase Messaging dan Flutter Local Notifications
  static Future<void> init() async {
    if (_isInitialized) return;

    try {
      // 1. Inisialisasi Firebase App jika belum
      try {
        await Firebase.initializeApp();
      } catch (e) {
        debugPrint('Firebase already initialized or initialization error: $e');
      }

      // 2. Setup Background Handler
      FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);

      // 3. Inisialisasi Local Notifications
      const AndroidInitializationSettings androidSettings =
          AndroidInitializationSettings('@mipmap/ic_launcher');
      const DarwinInitializationSettings iosSettings =
          DarwinInitializationSettings(
        requestAlertPermission: true,
        requestBadgePermission: true,
        requestSoundPermission: true,
      );

      const InitializationSettings initSettings = InitializationSettings(
        android: androidSettings,
        iOS: iosSettings,
      );

      await _localNotifications.initialize(
        settings: initSettings,
        onDidReceiveNotificationResponse: (NotificationResponse response) {
          final payload = response.payload;
          if (payload != null && payload.isNotEmpty) {
            try {
              final Map<String, dynamic> data = jsonDecode(payload);
              _handleNotificationData(data);
            } catch (e) {
              debugPrint('Error parsing notification payload: $e');
            }
          }
        },
      );

      // 4. Create Android Notification Channel
      final androidPlugin = _localNotifications
          .resolvePlatformSpecificImplementation<
              AndroidFlutterLocalNotificationsPlugin>();
      if (androidPlugin != null) {
        await androidPlugin.createNotificationChannel(_channel);
      }

      // 5. Setup Foreground Notification Presentation Options
      await FirebaseMessaging.instance
          .setForegroundNotificationPresentationOptions(
        alert: true,
        badge: true,
        sound: true,
      );

      // 6. Listener Foreground Messages (Saat aplikasi dibuka)
      FirebaseMessaging.onMessage.listen((RemoteMessage message) {
        debugPrint('FCM Foreground message: ${message.notification?.title}');
        _showLocalNotification(message);
        onNotificationReceived?.call();
      });

      // 7. Listener saat notifikasi diklik ketika aplikasi di background
      FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
        debugPrint('FCM Notification tapped from background: ${message.data}');
        _handleNotificationData(message.data);
      });

      // 8. Cek apakah aplikasi dibuka dari kondisi terminated oleh notifikasi
      final RemoteMessage? initialMessage =
          await FirebaseMessaging.instance.getInitialMessage();
      if (initialMessage != null) {
        debugPrint('FCM Initial message found: ${initialMessage.data}');
        Future.delayed(const Duration(milliseconds: 800), () {
          _handleNotificationData(initialMessage.data);
        });
      }

      // 9. Listener perubahan token FCM
      FirebaseMessaging.instance.onTokenRefresh.listen((newToken) {
        debugPrint('FCM Token refreshed: $newToken');
        syncFcmTokenWithBackend(token: newToken);
      });

      // 10. Mulai Real-time Poller otomatis
      startRealtimePoller();

      _isInitialized = true;
      debugPrint('PushNotificationService initialized successfully');
    } catch (e) {
      debugPrint('PushNotificationService init error: $e');
    }
  }

  /// Memulai Real-time Poller untuk memeriksa notifikasi baru secara instan
  static void startRealtimePoller() {
    _pollingTimer?.cancel();
    _checkNewNotifications(isInitial: true);

    _pollingTimer = Timer.periodic(const Duration(seconds: 5), (_) {
      _checkNewNotifications();
    });
  }

  /// Menghentikan Poller saat logout
  static void stopRealtimePoller() {
    _pollingTimer?.cancel();
    _pollingTimer = null;
  }

  /// Memeriksa notifikasi baru dan membunyikan alert lokal jika ada pembaruan status
  static Future<void> _checkNewNotifications({bool isInitial = false}) async {
    try {
      const storage = FlutterSecureStorage();
      final token = await storage.read(key: 'auth_token');
      if (token == null || token.isEmpty) return;

      final apiClient = ApiClient();
      final notifListResponse = await apiClient.dio.get(
        '/notifications',
        queryParameters: {'page': 1, 'per_page': 1},
      );

      if (notifListResponse.statusCode == 200) {
        final List<dynamic> list = notifListResponse.data['data'] ?? [];
        if (list.isNotEmpty) {
          final latest = list.first as Map<String, dynamic>;
          final id = latest['id']?.toString();

          if (isInitial) {
            _lastKnownNotifId = id;
            return;
          }

          if (id != null && id != _lastKnownNotifId) {
            _lastKnownNotifId = id;
            await showNotificationAlert(
              title: latest['title'] ?? 'Pembaruan Tiket',
              body: latest['body'] ?? 'Ada perubahan status pada tiket Anda',
              data: {
                'ticket_id': latest['ticketId'] ?? latest['ticket_id'],
                'id': id,
              },
            );
            onNotificationReceived?.call();
          }
        }
      }
    } catch (_) {
      // Abaikan error jaringan sementara
    }
  }

  /// Menampilkan banner notifikasi pop-up native OS + suara + getar
  static Future<void> showNotificationAlert({
    required String title,
    required String body,
    Map<String, dynamic>? data,
  }) async {
    final AndroidNotificationDetails androidDetails =
        AndroidNotificationDetails(
      _channel.id,
      _channel.name,
      channelDescription: _channel.description,
      importance: Importance.max,
      priority: Priority.max,
      icon: '@mipmap/ic_launcher',
      playSound: true,
      enableVibration: true,
      visibility: NotificationVisibility.public,
      ticker: title,
      category: AndroidNotificationCategory.message,
    );

    const DarwinNotificationDetails iosDetails = DarwinNotificationDetails(
      presentAlert: true,
      presentBadge: true,
      presentSound: true,
    );

    final NotificationDetails platformDetails = NotificationDetails(
      android: androidDetails,
      iOS: iosDetails,
    );

    final int notifId = DateTime.now().millisecondsSinceEpoch ~/ 1000;
    await _localNotifications.show(
      id: notifId,
      title: title,
      body: body,
      notificationDetails: platformDetails,
      payload: data != null ? jsonEncode(data) : null,
    );
  }

  /// Menampilkan notifikasi lokal heads-up banner saat pesan diterima dari FCM foreground
  static Future<void> _showLocalNotification(RemoteMessage message) async {
    final notification = message.notification;
    final data = message.data;

    final title = notification?.title ?? data['title'] ?? 'Halo APU';
    final body = notification?.body ?? data['body'] ?? data['message'] ?? 'Ada pembaruan tiket baru';

    await showNotificationAlert(
      title: title,
      body: body,
      data: data,
    );
  }

  /// Mengirimkan FCM Token ke backend Laravel agar device terdaftar
  static Future<void> syncFcmTokenWithBackend({String? token}) async {
    try {
      const storage = FlutterSecureStorage();
      final authToken = await storage.read(key: 'auth_token');
      if (authToken == null || authToken.isEmpty) {
        debugPrint('Skip FCM sync: User not logged in yet');
        return;
      }

      final fcmToken = token ?? await FirebaseMessaging.instance.getToken();
      if (fcmToken == null || fcmToken.isEmpty) {
        debugPrint('Skip FCM sync: FCM token is null or empty');
        return;
      }

      debugPrint('Sending FCM token to backend: $fcmToken');
      final apiClient = ApiClient();
      final response = await apiClient.dio.post(
        '/fcm-token',
        data: {'fcm_token': fcmToken},
      );

      debugPrint('FCM Token synced to backend: ${response.statusCode}');
    } catch (e) {
      debugPrint('Error syncing FCM token with backend: $e');
    }
  }

  /// Menangani navigasi / Deep Linking saat notifikasi diklik
  static Future<void> _handleNotificationData(Map<String, dynamic> data) async {
    try {
      const storage = FlutterSecureStorage();
      final role = await storage.read(key: 'user_role');
      final authToken = await storage.read(key: 'auth_token');

      if (authToken == null || authToken.isEmpty) {
        debugPrint('Cannot navigate to ticket: User not authenticated');
        return;
      }

      final ticketId = data['ticket_id']?.toString() ?? data['id']?.toString();
      if (ticketId != null && ticketId.isNotEmpty) {
        try {
          final apiClient = ApiClient();
          final response = await apiClient.dio.get('/tickets/$ticketId');
          if (response.statusCode == 200) {
            final ticketData = response.data['data'] ?? response.data;
            final ticket = TicketModel.safeFromJson(ticketData);

            if (role == 'admin') {
              appRouter.push('/tickets/admin/detail', extra: ticket);
            } else {
              appRouter.push('/tickets/user/detail', extra: ticket);
            }
            return;
          }
        } catch (e) {
          debugPrint('Error fetching ticket detail for notification deep link: $e');
        }
      }

      // Fallback: Navigasi ke halaman notifikasi
      if (role == 'admin') {
        appRouter.push('/notifications/admin');
      } else {
        appRouter.push('/notifications');
      }
    } catch (e) {
      debugPrint('Error handling notification tap navigation: $e');
    }
  }
}
