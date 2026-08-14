import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:halo_apu_mobile/core/theme/app_theme.dart';
import 'package:halo_apu_mobile/core/services/auth_service.dart';
import 'package:halo_apu_mobile/core/services/biometric_service.dart';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:halo_apu_mobile/core/config/api_config.dart';
import 'package:halo_apu_mobile/presentation/profile/providers/user_profile_provider.dart';
import 'package:halo_apu_mobile/presentation/notifications/providers/notification_provider.dart';
import 'package:halo_apu_mobile/data/repositories/dashboard_repository.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  final AuthService _authService = AuthService();
  final BiometricService _biometricService = BiometricService();
  bool _obscurePassword = true;
  bool _isLoading = false;
  bool _biometricVisible = false;
  int _biometricFailures = 0;

  @override
  void initState() {
    super.initState();
    _initBiometric();
  }

  Future<void> _initBiometric() async {
    const storage = FlutterSecureStorage();
    final token = await storage.read(key: 'auth_token');
    final hasSession = token != null;
    final enabled = await _biometricService.isEnabled();
    final supported = await _biometricService.canAuthenticate();
    if (mounted && enabled && hasSession && supported) {
      setState(() => _biometricVisible = true);
    }
  }

  Future<void> _handleBiometricLogin() async {
    if (_isLoading) return;
    final ok = await _biometricService.authenticate();
    if (!mounted) return;

    if (!ok) {
      setState(() => _biometricFailures++);
      if (_biometricFailures >= 3) {
        setState(() => _biometricVisible = false);
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Gagal 3 kali. Silakan login dengan password.')),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Verifikasi biometrik gagal')),
        );
      }
      return;
    }

    const storage = FlutterSecureStorage();
    final token = await storage.read(key: 'auth_token');
    if (!mounted) return;
    if (token == null) {
      setState(() => _biometricVisible = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Sesi berakhir, silakan login dengan password')),
      );
      return;
    }

    final isAdmin = await storage.read(key: 'user_role') == 'admin';
    if (!mounted) return;
    if (isAdmin) {
      context.go('/dashboard/admin');
    } else {
      context.go('/dashboard/user');
    }
  }

  Future<void> _handleLoginUser() async {
    await _performLogin(false);
  }

  Future<void> _handleLoginAdmin() async {
    await _performLogin(true);
  }

  Future<void> _performLogin(bool isAdmin) async {
    final email = _emailController.text.trim();
    final password = _passwordController.text;

    if (email.isEmpty || password.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Email/Username dan Password tidak boleh kosong')),
      );
      return;
    }

    setState(() {
      _isLoading = true;
    });

    final result = await _authService.login(email, password, isAdmin);

    if (mounted) {
      setState(() {
        _isLoading = false;
      });

      if (result['success']) {
        ref.invalidate(userProfileProvider);
        ref.invalidate(adminProfileProvider);
        ref.invalidate(notificationProvider);
        ref.invalidate(dashboardRepositoryProvider);

        if (isAdmin) {
          context.go('/dashboard/admin');
        } else {
          context.go('/dashboard/user');
        }
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(result['message']),
            backgroundColor: Colors.red,
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [
              Color(0xFFE0F4FE),
              Color(0xFFF6FAFF),
            ],
          ),
        ),
        child: Stack(
          children: [
            // Floating Blobs
            Positioned(
              top: -100,
              left: -100,
              child: _buildBlob(
                gradient: const RadialGradient(
                  colors: [
                    Color(0x4000A2E8),
                    Color(0x0000A2E8),
                  ],
                ),
              ),
            ),
            Positioned(
              bottom: -100,
              right: -100,
              child: _buildBlob(
                gradient: const RadialGradient(
                  colors: [
                    Color(0x30FEA520),
                    Color(0x00FEA520),
                  ],
                ),
              ),
            ),
            
            SafeArea(
              child: Center(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.symmetric(horizontal: 24.0, vertical: 48.0),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      // Header
                      Stack(
                        alignment: Alignment.center,
                        children: [
                          Container(
                            width: 120,
                            height: 120,
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              boxShadow: [
                                BoxShadow(
                                  color: AppTheme.oceanWater.withValues(alpha: 0.15),
                                  blurRadius: 40,
                                  spreadRadius: 10,
                                ),
                              ],
                            ),
                          ),
                          Image.network(
                            'https://lh3.googleusercontent.com/aida-public/AB6AXuCZMOv3eP3XCfm25f_YWhibtiBikYrzdS21Qo1RwftKHKwOJzX-1yUbw4YnKvyO7FDFvm9d7-9TxXGt12xonKj_ez1Kd6Ju8gEBRoxqd6__jjF-RV1RklCFNZTOGiLUsDUvM3UwRBwRsDmMGSh4WAaQS2yHVgM9OsB7NStDr01E4PxFqbKeFOt12sLiSOXv9zTLWasQmq-S1ljY0gZcRMuiaQnTYDCxTv2WDQaz8j1ogdVuAuwwfF0_kefy52BL9EaxIGomR7srrn0',
                            width: 112,
                            height: 112,
                            fit: BoxFit.contain,
                            errorBuilder: (context, error, stackTrace) {
                              return const Icon(Icons.support_agent, size: 80, color: AppTheme.oceanWater);
                            },
                          ),
                        ],
                      ),
                      const SizedBox(height: 16),
                      const Text(
                        'Halo APU',
                        style: TextStyle(
                          fontSize: 28,
                          fontWeight: FontWeight.bold,
                          color: AppTheme.oceanWater,
                          letterSpacing: -0.5,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Ajukan, Pantau, Selesai',
                        style: TextStyle(
                          fontSize: 14,
                          fontStyle: FontStyle.italic,
                          color: Colors.blueGrey.shade600,
                        ),
                      ),
                      const SizedBox(height: 32),

                      // Glass Card Form
                      Container(
                        padding: const EdgeInsets.all(24),
                        decoration: BoxDecoration(
                          color: Colors.white.withValues(alpha: 0.85),
                          borderRadius: BorderRadius.circular(24),
                          border: Border.all(
                            color: AppTheme.oceanWater.withValues(alpha: 0.08),
                          ),
                          boxShadow: [
                            BoxShadow(
                              color: AppTheme.oceanWater.withValues(alpha: 0.1),
                              blurRadius: 25,
                              offset: const Offset(0, 10),
                            ),
                          ],
                        ),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                // Username
                                const Text(
                                  'Email atau Username',
                                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                                ),
                                const SizedBox(height: 8),
                                TextField(
                                  controller: _emailController,
                                  decoration: InputDecoration(
                                    hintText: 'Masukkan ID Anda',
                                    hintStyle: TextStyle(color: Colors.grey.shade400),
                                    prefixIcon: const Icon(Icons.person_outline, color: Colors.grey),
                                    filled: true,
                                    fillColor: Colors.white,
                                    border: OutlineInputBorder(
                                      borderRadius: BorderRadius.circular(12),
                                      borderSide: BorderSide(color: Colors.grey.shade200),
                                    ),
                                    enabledBorder: OutlineInputBorder(
                                      borderRadius: BorderRadius.circular(12),
                                      borderSide: BorderSide(color: Colors.grey.shade200),
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 16),

                                // Password
                                Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    const Text(
                                      'Kata Sandi',
                                      style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                                    ),
                                    TextButton(
                                      onPressed: () {
                                        context.push('/forgot-password');
                                      },
                                      style: TextButton.styleFrom(
                                        padding: EdgeInsets.zero,
                                        minimumSize: Size.zero,
                                        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                                      ),
                                      child: const Text(
                                        'Lupa Password?',
                                        style: TextStyle(fontSize: 11, color: AppTheme.oceanWater),
                                      ),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 8),
                                TextField(
                                  controller: _passwordController,
                                  obscureText: _obscurePassword,
                                  decoration: InputDecoration(
                                    hintText: '••••••••',
                                    hintStyle: TextStyle(color: Colors.grey.shade400),
                                    prefixIcon: const Icon(Icons.lock_outline, color: Colors.grey),
                                    suffixIcon: IconButton(
                                      icon: Icon(
                                        _obscurePassword ? Icons.visibility_off : Icons.visibility,
                                        color: Colors.grey,
                                      ),
                                      onPressed: () {
                                        setState(() {
                                          _obscurePassword = !_obscurePassword;
                                        });
                                      },
                                    ),
                                    filled: true,
                                    fillColor: Colors.white,
                                    border: OutlineInputBorder(
                                      borderRadius: BorderRadius.circular(12),
                                      borderSide: BorderSide(color: Colors.grey.shade200),
                                    ),
                                    enabledBorder: OutlineInputBorder(
                                      borderRadius: BorderRadius.circular(12),
                                      borderSide: BorderSide(color: Colors.grey.shade200),
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 24),

                                // Login Button
                                ElevatedButton(
                                  onPressed: _isLoading ? null : _handleLoginUser,
                                  style: ElevatedButton.styleFrom(
                                    backgroundColor: AppTheme.oceanWater,
                                    foregroundColor: Colors.white,
                                    padding: const EdgeInsets.symmetric(vertical: 16),
                                    shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(12),
                                    ),
                                    elevation: 8,
                                    shadowColor: AppTheme.oceanWater.withValues(alpha: 0.5),
                                  ),
                                  child: _isLoading && !_emailController.text.contains("admin") 
                                      ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                                      : const Row(
                                          mainAxisAlignment: MainAxisAlignment.center,
                                          children: [
                                            Text('Login sebagai User', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                                            SizedBox(width: 8),
                                            Icon(Icons.arrow_forward),
                                          ],
                                        ),
                                ),
                                
                                const SizedBox(height: 16),
                                Row(
                                  children: [
                                    Expanded(child: Divider(color: Colors.grey.shade300)),
                                    Padding(
                                      padding: const EdgeInsets.symmetric(horizontal: 16),
                                      child: Text('ATAU', style: TextStyle(fontSize: 11, color: Colors.grey.shade400, fontWeight: FontWeight.w600)),
                                    ),
                                    Expanded(child: Divider(color: Colors.grey.shade300)),
                                  ],
                                ),
                                const SizedBox(height: 16),

                                OutlinedButton(
                                  onPressed: _isLoading ? null : _handleLoginAdmin,
                                  style: OutlinedButton.styleFrom(
                                    foregroundColor: AppTheme.oceanWater,
                                    side: BorderSide(color: AppTheme.oceanWater.withValues(alpha: 0.2), width: 2),
                                    padding: const EdgeInsets.symmetric(vertical: 16),
                                    shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(12),
                                    ),
                                  ),
                                  child: _isLoading && _emailController.text.contains("admin") 
                                      ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(color: AppTheme.oceanWater, strokeWidth: 2))
                                      : const Row(
                                          mainAxisAlignment: MainAxisAlignment.center,
                                          children: [
                                            Text('Login sebagai Admin', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                                            SizedBox(width: 8),
                                            Icon(Icons.support_agent),
                                          ],
                                        ),
                                ),

                                if (_biometricVisible) ...[
                                  const SizedBox(height: 16),
                                  OutlinedButton.icon(
                                    onPressed: _isLoading ? null : _handleBiometricLogin,
                                    style: OutlinedButton.styleFrom(
                                      foregroundColor: AppTheme.brilliantBlue,
                                      side: BorderSide(color: AppTheme.brilliantBlue.withValues(alpha: 0.2), width: 2),
                                      padding: const EdgeInsets.symmetric(vertical: 16),
                                      shape: RoundedRectangleBorder(
                                        borderRadius: BorderRadius.circular(12),
                                      ),
                                    ),
                                    icon: const Icon(Icons.fingerprint, size: 22),
                                    label: const Text('Login dengan Biometrik', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                                  ),
                                ],
                              ],
                            ),
                          ),

                      const SizedBox(height: 40),
                      
                      // Footer
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          _buildFooterIcon(Icons.help_outline, 'Bantuan', () => context.push('/help')),
                          const SizedBox(width: 24),
                          _buildFooterIcon(Icons.contact_support_outlined, 'Hubungi Admin', () => context.push('/contact-admin')),
                          const SizedBox(width: 24),
                          _buildFooterIcon(Icons.dns_outlined, 'Host Server', _showServerSettingsModal),
                        ],
                      ),
                      const SizedBox(height: 20),
                      GestureDetector(
                        onTap: _showServerSettingsModal,
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                          decoration: BoxDecoration(
                            color: AppTheme.oceanWater.withValues(alpha: 0.08),
                            borderRadius: BorderRadius.circular(16),
                          ),
                          child: Text(
                            'API: ${ApiConfig.baseUrl}',
                            style: const TextStyle(
                              fontSize: 10,
                              fontFamily: 'monospace',
                              color: AppTheme.oceanWater,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(height: 16),
                      Text(
                        'VERSI 1.0.0 • © 2026 HALO APU',
                        style: TextStyle(
                          fontSize: 11,
                          letterSpacing: 2,
                          color: Colors.blueGrey.withValues(alpha: 0.4),
                          fontWeight: FontWeight.bold,
                        ),
                      )
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

  Widget _buildBlob({required Gradient gradient}) {
    return Container(
      width: 400,
      height: 400,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: gradient,
      ),
    );
  }

  Widget _buildFooterIcon(IconData icon, String label, VoidCallback onTap) {
    return GestureDetector(
      onTap: onTap,
      child: Column(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: Colors.white,
              shape: BoxShape.circle,
              border: Border.all(color: Colors.blueGrey.withValues(alpha: 0.1)),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.05),
                  blurRadius: 4,
                  offset: const Offset(0, 2),
                ),
              ],
            ),
            child: Icon(icon, color: AppTheme.oceanWater, size: 20),
          ),
          const SizedBox(height: 4),
          Text(
            label,
            style: const TextStyle(fontSize: 11, color: Colors.blueGrey, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }

  void _showServerSettingsModal() {
    final hostController = TextEditingController(text: ApiConfig.baseUrl);
    bool isTesting = false;
    String? testResult;
    bool? testSuccess;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            return Container(
              padding: EdgeInsets.only(
                top: 24,
                left: 24,
                right: 24,
                bottom: MediaQuery.of(context).viewInsets.bottom + 24,
              ),
              decoration: const BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Row(
                        children: [
                          Icon(Icons.dns_rounded, color: AppTheme.oceanWater),
                          SizedBox(width: 8),
                          Text(
                            'Konfigurasi Host Server',
                            style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                          ),
                        ],
                      ),
                      IconButton(
                        icon: const Icon(Icons.close),
                        onPressed: () => Navigator.pop(ctx),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  const Text(
                    'Pilih preset jaringan atau masukkan URL/IP server backend Laravel Anda.',
                    style: TextStyle(fontSize: 13, color: Colors.grey),
                  ),
                  const SizedBox(height: 16),

                  // Quick presets
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      ActionChip(
                        avatar: const Icon(Icons.wifi, size: 16, color: AppTheme.oceanWater),
                        label: const Text('Wi-Fi (192.168.3.116)', style: TextStyle(fontSize: 12)),
                        onPressed: () {
                          setModalState(() {
                            hostController.text = 'http://192.168.3.116:8000/api';
                            testResult = null;
                          });
                        },
                      ),
                      ActionChip(
                        avatar: const Icon(Icons.usb, size: 16, color: AppTheme.oceanWater),
                        label: const Text('USB Reverse (127.0.0.1)', style: TextStyle(fontSize: 12)),
                        onPressed: () {
                          setModalState(() {
                            hostController.text = 'http://127.0.0.1:8000/api';
                            testResult = null;
                          });
                        },
                      ),
                      ActionChip(
                        avatar: const Icon(Icons.cloud_outlined, size: 16, color: AppTheme.oceanWater),
                        label: const Text('Live cPanel', style: TextStyle(fontSize: 12)),
                        onPressed: () {
                          setModalState(() {
                            hostController.text = ApiConfig.cpanelUrl;
                            testResult = null;
                          });
                        },
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),

                  TextField(
                    controller: hostController,
                    decoration: InputDecoration(
                      labelText: 'Base API URL',
                      hintText: 'http://192.168.3.116:8000/api',
                      prefixIcon: const Icon(Icons.link, color: AppTheme.oceanWater),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    ),
                  ),
                  const SizedBox(height: 12),

                  if (testResult != null) ...[
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: (testSuccess == true ? Colors.green : Colors.red).withValues(alpha: 0.1),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(
                          color: (testSuccess == true ? Colors.green : Colors.red).withValues(alpha: 0.3),
                        ),
                      ),
                      child: Row(
                        children: [
                          Icon(
                            testSuccess == true ? Icons.check_circle : Icons.error_outline,
                            color: testSuccess == true ? Colors.green : Colors.red,
                            size: 18,
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              testResult!,
                              style: TextStyle(
                                fontSize: 12,
                                color: testSuccess == true ? Colors.green.shade800 : Colors.red.shade800,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                  ],

                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: isTesting
                              ? null
                              : () async {
                                  setModalState(() {
                                    isTesting = true;
                                    testResult = null;
                                  });
                                  final ok = await ApiConfig.testConnection(hostController.text);
                                  setModalState(() {
                                    isTesting = false;
                                    testSuccess = ok;
                                    testResult = ok
                                        ? '🟢 Berhasil terhubung ke server!'
                                        : '🔴 Gagal terhubung (Timeout/Refused). Coba preset lain.';
                                  });
                                },
                          icon: isTesting
                              ? const SizedBox(
                                  width: 16,
                                  height: 16,
                                  child: CircularProgressIndicator(strokeWidth: 2),
                                )
                              : const Icon(Icons.network_check, size: 18),
                          label: const Text('Tes Koneksi'),
                          style: OutlinedButton.styleFrom(
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: ElevatedButton.icon(
                          onPressed: () async {
                            final target = hostController.text.trim();
                            if (target.isNotEmpty) {
                              final messenger = ScaffoldMessenger.of(context);
                              final nav = Navigator.of(ctx);
                              await ApiConfig.setCustomBaseUrl(target);
                              if (mounted) setState(() {});
                              nav.pop();
                              messenger.showSnackBar(
                                SnackBar(
                                  content: Text('Host server diubah ke: ${ApiConfig.baseUrl}'),
                                  backgroundColor: AppTheme.oceanWater,
                                ),
                              );
                            }
                          },
                          icon: const Icon(Icons.save, size: 18),
                          label: const Text('Simpan'),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: AppTheme.oceanWater,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }
}

