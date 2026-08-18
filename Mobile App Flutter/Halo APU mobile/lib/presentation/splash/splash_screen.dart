import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:go_router/go_router.dart';
import 'package:halo_apu_mobile/core/theme/app_theme.dart';
import 'package:halo_apu_mobile/presentation/profile/providers/user_profile_provider.dart';

class SplashScreen extends ConsumerStatefulWidget {
  const SplashScreen({super.key});

  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen>
    with SingleTickerProviderStateMixin {
  late AnimationController _controller;
  late Animation<double> _logoScaleAnimation;
  late Animation<double> _logoFadeAnimation;
  late Animation<double> _glowAnimation;
  late Animation<Offset> _textSlideAnimation;
  late Animation<double> _textFadeAnimation;
  late Animation<double> _taglineFadeAnimation;

  final FlutterSecureStorage _storage = const FlutterSecureStorage();
  String? _targetRoute;
  bool _isInitCompleted = false;

  @override
  void initState() {
    super.initState();

    // 1. Controller durasi ~1800ms untuk pengalaman cepat & responsif
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1800),
    );

    // 2. Staggered Animations:
    // Phase 1: Logo Scale & Fade (0.0 -> 0.55)
    _logoScaleAnimation = Tween<double>(begin: 0.4, end: 1.0).animate(
      CurvedAnimation(
        parent: _controller,
        curve: const Interval(0.0, 0.55, curve: Curves.easeOutBack),
      ),
    );

    _logoFadeAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _controller,
        curve: const Interval(0.0, 0.4, curve: Curves.easeIn),
      ),
    );

    // Glow pulse animation
    _glowAnimation = Tween<double>(begin: 0.3, end: 0.8).animate(
      CurvedAnimation(
        parent: _controller,
        curve: const Interval(0.2, 0.8, curve: Curves.easeInOut),
      ),
    );

    // Phase 2: Title "HALO APU" Slide & Fade (0.35 -> 0.75)
    _textSlideAnimation = Tween<Offset>(
      begin: const Offset(0, 0.5),
      end: Offset.zero,
    ).animate(
      CurvedAnimation(
        parent: _controller,
        curve: const Interval(0.35, 0.75, curve: Curves.easeOutCubic),
      ),
    );

    _textFadeAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _controller,
        curve: const Interval(0.35, 0.7, curve: Curves.easeIn),
      ),
    );

    // Phase 3: Tagline Fade (0.55 -> 1.0)
    _taglineFadeAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _controller,
        curve: const Interval(0.55, 1.0, curve: Curves.easeIn),
      ),
    );

    // 3. Jalankan inisialisasi & lazy loading data di latar belakang
    _startParallelInit();
    _controller.forward();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // Pre-cache asset gambar agar langsung tampil instan tanpa flicker
    precacheImage(const AssetImage('assets/images/splash_logo.png'), context);
  }

  /// Pengecekan sesi & lazy warming dilakukan paralel bersamaan dengan animasi
  Future<void> _startParallelInit() async {
    try {
      final token = await _storage.read(key: 'auth_token');
      final isAuth = token != null && token.isNotEmpty;

      if (isAuth) {
        final role = await _storage.read(key: 'user_role');
        // Pre-warm profile provider jika token ada
        if (role == 'admin') {
          ref.read(adminProfileProvider.notifier).refresh();
          _targetRoute = '/dashboard/admin';
        } else {
          ref.read(userProfileProvider.notifier).refresh();
          _targetRoute = '/dashboard/user';
        }
      } else {
        _targetRoute = '/login';
      }
    } catch (_) {
      _targetRoute = '/login';
    } finally {
      _isInitCompleted = true;
      _checkAndNavigate();
    }
  }

  void _checkAndNavigate() {
    if (_isInitCompleted && _controller.isCompleted) {
      _navigateToTarget();
    } else {
      _controller.addStatusListener((status) {
        if (status == AnimationStatus.completed && _isInitCompleted) {
          _navigateToTarget();
        }
      });
    }
  }

  void _navigateToTarget() {
    if (!mounted) return;
    context.go(_targetRoute ?? '/login');
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        width: double.infinity,
        height: double.infinity,
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              Color(0xFF00B8D9), // Ocean Water
              Color(0xFF0066FF), // Brilliant Blue
              Color(0xFF0A2540), // Deep Midnight Navy
            ],
            stops: [0.0, 0.6, 1.0],
          ),
        ),
        child: Stack(
          children: [
            // Background ambient shapes
            Positioned(
              top: -60,
              right: -60,
              child: Container(
                width: 220,
                height: 220,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: Colors.white.withValues(alpha: 0.07),
                ),
              ),
            ),
            Positioned(
              bottom: -80,
              left: -40,
              child: Container(
                width: 260,
                height: 260,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: AppTheme.oceanWater.withValues(alpha: 0.15),
                ),
              ),
            ),

            // Main Content
            Center(
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  // Animated Logo with Glow Aura
                  AnimatedBuilder(
                    animation: _controller,
                    builder: (context, child) {
                      return Transform.scale(
                        scale: _logoScaleAnimation.value,
                        child: Opacity(
                          opacity: _logoFadeAnimation.value,
                          child: Stack(
                            alignment: Alignment.center,
                            children: [
                              // Glow Circle
                              Container(
                                width: 140,
                                height: 140,
                                decoration: BoxDecoration(
                                  shape: BoxShape.circle,
                                  boxShadow: [
                                    BoxShadow(
                                      color: Colors.white.withValues(
                                        alpha: _glowAnimation.value * 0.4,
                                      ),
                                      blurRadius: 40,
                                      spreadRadius: 10,
                                    ),
                                    BoxShadow(
                                      color: AppTheme.oceanWater.withValues(
                                        alpha: _glowAnimation.value * 0.5,
                                      ),
                                      blurRadius: 60,
                                      spreadRadius: 20,
                                    ),
                                  ],
                                ),
                              ),

                              // Splash Logo Image with Shadow
                              Container(
                                width: 135,
                                height: 135,
                                decoration: BoxDecoration(
                                  shape: BoxShape.circle,
                                  boxShadow: [
                                    BoxShadow(
                                      color: Colors.black.withValues(alpha: 0.25),
                                      blurRadius: 30,
                                      offset: const Offset(0, 12),
                                    ),
                                  ],
                                ),
                                child: Image.asset(
                                  'assets/images/splash_logo.png',
                                  fit: BoxFit.contain,
                                  errorBuilder: (context, error, stackTrace) {
                                    return const Icon(
                                      Icons.support_agent_rounded,
                                      size: 80,
                                      color: Colors.white,
                                    );
                                  },
                                ),
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                  const SizedBox(height: 32),

                  // Animated Title "HALO APU"
                  SlideTransition(
                    position: _textSlideAnimation,
                    child: FadeTransition(
                      opacity: _textFadeAnimation,
                      child: const Column(
                        children: [
                          Text(
                            'HALO APU',
                            style: TextStyle(
                              fontSize: 30,
                              fontWeight: FontWeight.w900,
                              color: Colors.white,
                              letterSpacing: 2.0,
                              shadows: [
                                Shadow(
                                  color: Colors.black26,
                                  offset: Offset(0, 4),
                                  blurRadius: 10,
                                ),
                              ],
                            ),
                          ),
                          SizedBox(height: 6),
                          Text(
                            'Layanan Helpdesk & Ticketing',
                            style: TextStyle(
                              fontSize: 14,
                              fontWeight: FontWeight.w500,
                              color: Color(0xFFE2E8F0),
                              letterSpacing: 0.5,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),

            // Footer Version
            Positioned(
              bottom: 36,
              left: 0,
              right: 0,
              child: FadeTransition(
                opacity: _taglineFadeAnimation,
                child: Center(
                  child: Column(
                    children: [
                      Container(
                        width: 32,
                        height: 3,
                        decoration: BoxDecoration(
                          color: Colors.white.withValues(alpha: 0.4),
                          borderRadius: BorderRadius.circular(2),
                        ),
                      ),
                      const SizedBox(height: 12),
                      Text(
                        'VERSI 1.0.0 • © 2026 HALO APU',
                        style: TextStyle(
                          fontSize: 11,
                          letterSpacing: 1.5,
                          fontWeight: FontWeight.w600,
                          color: Colors.white.withValues(alpha: 0.6),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
