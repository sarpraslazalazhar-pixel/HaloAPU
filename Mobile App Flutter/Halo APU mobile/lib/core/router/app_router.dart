import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:halo_apu_mobile/presentation/splash/splash_screen.dart';
import 'package:halo_apu_mobile/presentation/auth/login_screen.dart';
import 'package:halo_apu_mobile/presentation/auth/forgot_password_screen.dart';
import 'package:halo_apu_mobile/presentation/dashboard/user_dashboard_screen.dart';
import 'package:halo_apu_mobile/presentation/dashboard/admin_dashboard_screen.dart';
import 'package:halo_apu_mobile/presentation/help/help_screen.dart';
import 'package:halo_apu_mobile/presentation/help/contact_admin_screen.dart';
import 'package:halo_apu_mobile/presentation/tickets/user_ticket_list_screen.dart';
import 'package:halo_apu_mobile/presentation/tickets/admin_ticket_list_screen.dart';
import 'package:halo_apu_mobile/presentation/tickets/user_ticket_detail_screen.dart';
import 'package:halo_apu_mobile/presentation/tickets/admin_ticket_detail_screen.dart';
import 'package:halo_apu_mobile/presentation/tickets/create_ticket_screen.dart';
import 'package:halo_apu_mobile/presentation/ratings/rating_history_screen.dart';
import 'package:halo_apu_mobile/presentation/notifications/notification_screen.dart';
import 'package:halo_apu_mobile/presentation/profile/profile_screen.dart';
import 'package:halo_apu_mobile/presentation/profile/edit_profile_screen.dart';
import 'package:halo_apu_mobile/presentation/profile/change_password_screen.dart';
import 'package:halo_apu_mobile/presentation/settings/admin_settings_screen.dart';
import 'package:halo_apu_mobile/presentation/profile/providers/user_profile_provider.dart';
import 'package:halo_apu_mobile/domain/models/ticket_model.dart';
import 'package:halo_apu_mobile/presentation/dashboard/monitor_screen.dart';

Page<void> _fadeSlidePage(GoRouterState state, Widget child) {
  return CustomTransitionPage<void>(
    key: state.pageKey,
    transitionDuration: const Duration(milliseconds: 350),
    reverseTransitionDuration: const Duration(milliseconds: 250),
    transitionsBuilder: (context, animation, secondaryAnimation, child) {
      final curved = CurvedAnimation(
        parent: animation,
        curve: Curves.easeOutCubic,
        reverseCurve: Curves.easeInCubic,
      );
      return FadeTransition(
        opacity: curved,
        child: SlideTransition(
          position: Tween<Offset>(
            begin: const Offset(0, 0.04),
            end: Offset.zero,
          ).animate(curved),
          child: child,
        ),
      );
    },
    child: child,
  );
}

final appRouter = GoRouter(
  initialLocation: '/splash',
  redirect: (context, state) async {
    const storage = FlutterSecureStorage();
    final token = await storage.read(key: 'auth_token');
    final isAuth = token != null && token.isNotEmpty;
    final isPublicRoute = state.matchedLocation == '/splash' ||
        state.matchedLocation == '/login' ||
        state.matchedLocation == '/forgot-password' ||
        state.matchedLocation == '/help' ||
        state.matchedLocation == '/contact-admin';

    // 1. Biarkan /splash melakukan inisialisasi dan routing animasinya sendiri
    if (state.matchedLocation == '/splash') {
      return null;
    }

    // 2. Jika sudah punya sesi login dan membuka /login, langsung ke dashboard
    if (isAuth && state.matchedLocation == '/login') {
      final role = await storage.read(key: 'user_role');
      if (role == 'admin') {
        return '/dashboard/admin';
      } else {
        return '/dashboard/user';
      }
    }

    // 3. Jika belum login dan mencoba mengakses rute terlindungi -> arahkan ke /login
    if (!isAuth && !isPublicRoute) {
      return '/login';
    }

    return null;
  },
  routes: [
    GoRoute(
      path: '/splash',
      pageBuilder: (context, state) => _fadeSlidePage(state, const SplashScreen()),
    ),
    GoRoute(
      path: '/login',
      pageBuilder: (context, state) => _fadeSlidePage(state, const LoginScreen()),
    ),
    GoRoute(
      path: '/forgot-password',
      pageBuilder: (context, state) => _fadeSlidePage(state, const ForgotPasswordScreen()),
    ),
    GoRoute(
      path: '/help',
      pageBuilder: (context, state) => _fadeSlidePage(state, const HelpScreen()),
    ),
    GoRoute(
      path: '/contact-admin',
      pageBuilder: (context, state) => _fadeSlidePage(state, const ContactAdminScreen()),
    ),
    GoRoute(
      path: '/dashboard/user',
      pageBuilder: (context, state) => _fadeSlidePage(state, const UserDashboardScreen()),
    ),
    GoRoute(
      path: '/dashboard/admin',
      pageBuilder: (context, state) => _fadeSlidePage(state, const AdminDashboardScreen()),
    ),
    GoRoute(
      path: '/tickets/user',
      pageBuilder: (context, state) => _fadeSlidePage(state, const UserTicketListScreen()),
    ),
    GoRoute(
      path: '/tickets/admin',
      pageBuilder: (context, state) => _fadeSlidePage(state, const AdminTicketListScreen()),
    ),
    GoRoute(
      path: '/tickets/user/detail',
      pageBuilder: (context, state) {
        final ticket = state.extra as TicketModel;
        return _fadeSlidePage(state, UserTicketDetailScreen(ticket: ticket));
      },
    ),
    GoRoute(
      path: '/tickets/admin/detail',
      pageBuilder: (context, state) {
        final ticket = state.extra as TicketModel;
        return _fadeSlidePage(state, AdminTicketDetailScreen(ticket: ticket));
      },
    ),
    GoRoute(
      path: '/tickets/create',
      pageBuilder: (context, state) => _fadeSlidePage(state, const CreateTicketScreen()),
    ),
    GoRoute(
      path: '/ratings/history',
      pageBuilder: (context, state) => _fadeSlidePage(state, const RatingHistoryScreen()),
    ),
    GoRoute(
      path: '/ratings/admin',
      pageBuilder: (context, state) =>
          _fadeSlidePage(state, const RatingHistoryScreen(isAdmin: true)),
    ),
    GoRoute(
      path: '/notifications',
      pageBuilder: (context, state) => _fadeSlidePage(state, const NotificationScreen()),
    ),
    GoRoute(
      path: '/notifications/admin',
      pageBuilder: (context, state) =>
          _fadeSlidePage(state, const NotificationScreen(isAdmin: true)),
    ),
    GoRoute(
      path: '/settings/admin',
      pageBuilder: (context, state) => _fadeSlidePage(state, const AdminSettingsScreen()),
    ),
    GoRoute(
      path: '/settings/edit-admin',
      pageBuilder: (context, state) =>
          _fadeSlidePage(state, EditProfileScreen(profileProvider: adminProfileProvider)),
    ),
    GoRoute(
      path: '/profile',
      pageBuilder: (context, state) => _fadeSlidePage(state, const ProfileScreen()),
    ),
    GoRoute(
      path: '/profile/edit',
      pageBuilder: (context, state) => _fadeSlidePage(state, const EditProfileScreen()),
    ),
    GoRoute(
      path: '/profile/change-password',
      pageBuilder: (context, state) => _fadeSlidePage(state, const ChangePasswordScreen()),
    ),
    GoRoute(
      path: '/monitor',
      pageBuilder: (context, state) => _fadeSlidePage(state, const MonitorScreen()),
    ),
  ],
);
