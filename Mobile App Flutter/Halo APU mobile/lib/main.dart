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
