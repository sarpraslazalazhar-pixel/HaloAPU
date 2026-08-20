import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:halo_apu_mobile/core/router/app_router.dart';
import 'package:halo_apu_mobile/core/theme/app_theme.dart';
import 'package:halo_apu_mobile/core/services/push_notification_service.dart';
import 'package:halo_apu_mobile/core/services/pending_ticket_service.dart';
import 'package:halo_apu_mobile/core/config/api_config.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  
  // Load saved API configuration
  await ApiConfig.loadSavedBaseUrl();

  // Initialize Push Notifications (Firebase)
  await PushNotificationService.init();

  // Initialize Hive for local storage (drafts, etc)
  await Hive.initFlutter();
  await Hive.openBox('ticket_drafts');
  await PendingTicketService.init();

  // Custom user-friendly Error Widget (Mencegah Layar Merah / Crash Screen)
  ErrorWidget.builder = (FlutterErrorDetails details) {
    FlutterError.dumpErrorToConsole(details);
    debugPrint('Widget build error: ${details.exception}');

    return Material(
      color: const Color(0xFFF8FAFC),
      child: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 24.0, vertical: 32.0),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Container(
                  padding: const EdgeInsets.all(18),
                  decoration: const BoxDecoration(
                    color: Color(0xFFFEF2F2),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(
                    Icons.info_outline_rounded,
                    color: Color(0xFFDC2626),
                    size: 42,
                  ),
                ),
                const SizedBox(height: 18),
                const Text(
                  'Terjadi Kendala Tampilan',
                  style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    color: Color(0xFF1E293B),
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  kDebugMode
                      ? 'Error: ${details.exception}'
                      : 'Sesi akun Anda telah berakhir atau tampilan memerlukan pembaruan. Silakan masuk kembali.',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    fontSize: 12,
                    color: Colors.grey.shade600,
                    height: 1.4,
                  ),
                  maxLines: 3,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 24),
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    OutlinedButton.icon(
                      onPressed: () {
                        appRouter.go('/splash');
                      },
                      icon: const Icon(Icons.refresh_rounded, size: 18),
                      label: const Text('Muat Ulang'),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: const Color(0xFF00768C),
                        side: const BorderSide(color: Color(0xFF00768C)),
                        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    ElevatedButton.icon(
                      onPressed: () {
                        appRouter.go('/login');
                      },
                      icon: const Icon(Icons.login_rounded, size: 18),
                      label: const Text('Masuk ke Akun'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF00768C),
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                        ),
                        elevation: 2,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  };

  runApp(
    const ProviderScope(
      child: HaloApuApp(),
    ),
  );
}

class HaloApuApp extends StatelessWidget {
  const HaloApuApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'Halo APU Mobile',
      theme: AppTheme.lightTheme,
      routerConfig: appRouter,
      debugShowCheckedModeBanner: false,
    );
  }
}
