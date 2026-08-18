import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:go_router/go_router.dart';
import '../../core/theme/app_theme.dart';
import '../../core/services/biometric_service.dart';
import '../../core/services/auth_service.dart';
import '../../domain/models/user_profile_model.dart';
import '../widgets/custom_bottom_nav.dart';
import '../widgets/entrance_animation.dart';
import '../widgets/app_avatar.dart';
import 'providers/user_profile_provider.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profile = ref.watch(userProfileProvider);

    return Scaffold(
      backgroundColor: AppTheme.lightBg,
      appBar: AppBar(
        title: const Text('Profil', style: TextStyle(fontWeight: FontWeight.bold)),
        elevation: 0,
        backgroundColor: Colors.white,
        foregroundColor: Colors.black,
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
        children: [
          EntranceAnimation(
            child: _ProfileHeaderCard(profile: profile),
          ),
          const SizedBox(height: 16),
          EntranceAnimation(
            delay: const Duration(milliseconds: 120),
            child: _MenuSection(
              items: [
                _MenuItem(
                  icon: Icons.edit_outlined,
                  color: AppTheme.oceanWater,
                  title: 'Edit Profil',
                  subtitle: 'Ubah nama, email, dan nomor HP',
                  onTap: () => context.push('/profile/edit'),
                ),
                _MenuItem(
                  icon: Icons.lock_outline_rounded,
                  color: AppTheme.brilliantBlue,
                  title: 'Ubah Kata Sandi',
                  subtitle: 'Ganti kata sandi akun Anda',
                  onTap: () => context.push('/profile/change-password'),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          const EntranceAnimation(
            delay: Duration(milliseconds: 240),
            child: _BiometricSection(),
          ),
          const SizedBox(height: 16),
          EntranceAnimation(
            delay: const Duration(milliseconds: 360),
            child: _MenuSection(
              items: [
                _MenuItem(
                  icon: Icons.logout_rounded,
                  color: AppTheme.danger,
                  title: 'Keluar',
                  subtitle: 'Akhiri sesi akun Anda',
                  onTap: () => _confirmLogout(context),
                  isDanger: true,
                ),
                _MenuItem(
                  icon: Icons.delete_forever_outlined,
                  color: AppTheme.danger,
                  title: 'Hapus Akun',
                  subtitle: 'Hapus akun dan semua data Anda',
                  onTap: () => _confirmDeleteAccount(context, ref),
                  isDanger: true,
                ),
              ],
            ),
          ),
        ],
      ),
      extendBody: true,
      bottomNavigationBar: const UserBottomNav(currentIndex: 3),
      floatingActionButton: CustomFloatingActionButton(
        icon: Icons.add,
        onPressed: () => context.push('/tickets/create'),
      ),
      floatingActionButtonLocation: FloatingActionButtonLocation.centerDocked,
    );
  }

  Future<void> _confirmLogout(BuildContext context) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        title: const Text('Keluar dari akun?'),
        content: const Text('Anda akan kembali ke halaman masuk.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: const Text('Batal'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: AppTheme.danger,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
              ),
            ),
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: const Text('Keluar'),
          ),
        ],
      ),
    );

    if (confirmed == true && context.mounted) {
      showDialog(
        context: context,
        barrierDismissible: false,
        builder: (_) => const Center(child: CircularProgressIndicator()),
      );

      final authService = AuthService();
      await authService.logout();

      if (!context.mounted) return;
      Navigator.of(context, rootNavigator: true).pop(); // dismiss loading dialog
      context.go('/login');
    }
  }

  Future<void> _confirmDeleteAccount(BuildContext context, WidgetRef ref) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        title: const Text('Hapus akun?'),
        content: const Text(
          'Akun dan seluruh data Anda akan dihapus permanen. Tindakan ini tidak dapat dibatalkan.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: const Text('Batal'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: AppTheme.danger,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
              ),
            ),
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: const Text('Hapus'),
          ),
        ],
      ),
    );

    if (confirmed == true && context.mounted) {
      showDialog(
        context: context,
        barrierDismissible: false,
        builder: (_) => const Center(child: CircularProgressIndicator()),
      );

      final result = await ref.read(userProfileProvider.notifier).deleteAccount();
      if (!context.mounted) return;
      Navigator.of(context, rootNavigator: true).pop(); // dismiss loading

      if (result['success'] == true) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            behavior: SnackBarBehavior.floating,
            content: Text(result['message'] ?? 'Akun Anda telah berhasil dihapus permanen'),
            backgroundColor: AppTheme.danger,
          ),
        );
        context.go('/login');
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            behavior: SnackBarBehavior.floating,
            content: Text(result['message'] ?? 'Gagal menghapus akun'),
            backgroundColor: AppTheme.danger,
          ),
        );
      }
    }
  }
}

class _ProfileHeaderCard extends StatelessWidget {
  final UserProfile profile;

  const _ProfileHeaderCard({required this.profile});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: AppTheme.primaryGradient,
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: AppTheme.brilliantBlue.withValues(alpha: 0.25),
            blurRadius: 20,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Row(
        children: [
          AppAvatar(
            name: profile.name.isNotEmpty ? profile.name : 'Pengguna',
            imageUrl: profile.avatarUrl,
            radius: 32,
            showBorder: true,
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  profile.name,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    letterSpacing: -0.3,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  '@${profile.username}',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.85),
                    fontSize: 12.5,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  profile.position,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.9),
                    fontSize: 13,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  '${profile.division} - ${profile.department}',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.8),
                    fontSize: 12,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _BiometricSection extends StatefulWidget {
  const _BiometricSection();

  @override
  State<_BiometricSection> createState() => _BiometricSectionState();
}

class _BiometricSectionState extends State<_BiometricSection> {
  final BiometricService _service = BiometricService();
  bool _enabled = false;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final enabled = await _service.isEnabled();
    if (mounted) setState(() => _enabled = enabled);
    _loading = false;
  }

  Future<void> _onChanged(bool value) async {
    if (value) {
      final supported = await _service.canAuthenticate();
      if (!supported) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Perangkat tidak mendukung biometrik')),
          );
        }
        return;
      }
      final ok = await _service.authenticate();
      if (!ok) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Verifikasi biometrik gagal')),
          );
        }
        return;
      }
      await _service.setEnabled(true);
      const storage = FlutterSecureStorage();
      final token = await storage.read(key: 'auth_token');
      final role = await storage.read(key: 'user_role') ?? 'user';
      final userData = await storage.read(key: 'user_data') ?? '{}';
      if (token != null) {
        String name = 'Pengguna';
        String email = '';
        try {
          final decoded = jsonDecode(userData);
          name = decoded['name'] ?? decoded['username'] ?? 'Pengguna';
          email = decoded['email'] ?? '';
        } catch (_) {}
        await _service.saveBiometricSession(
          token: token,
          role: role,
          name: name,
          email: email,
          userData: userData,
        );
      }
    } else {
      await _service.setEnabled(false);
    }
    if (mounted) setState(() => _enabled = value);
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        child: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: AppTheme.brilliantBlue.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.fingerprint, color: AppTheme.brilliantBlue, size: 22),
            ),
            const SizedBox(width: 14),
            const Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Login Biometrik',
                    style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: Colors.black87),
                  ),
                  SizedBox(height: 2),
                  Text(
                    'Masuk cepat dengan sidik jari / Face ID',
                    style: TextStyle(fontSize: 12, color: Colors.grey),
                  ),
                ],
              ),
            ),
            Switch(
              value: _enabled,
              onChanged: _loading ? null : _onChanged,
              activeThumbColor: AppTheme.brilliantBlue,
            ),
          ],
        ),
      ),
    );
  }
}

class _MenuSection extends StatelessWidget {
  final List<_MenuItem> items;

  const _MenuSection({required this.items});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Column(
        children: [
          for (var i = 0; i < items.length; i++) ...[
            if (i > 0)
              Divider(
                height: 1,
                indent: 60,
                endIndent: 16,
                color: Colors.grey.shade100,
              ),
            items[i],
          ],
        ],
      ),
    );
  }
}

class _MenuItem extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final bool isDanger;

  const _MenuItem({
    required this.icon,
    required this.color,
    required this.title,
    required this.subtitle,
    required this.onTap,
    this.isDanger = false,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(20),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        child: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, color: color, size: 22),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.bold,
                      color: isDanger ? AppTheme.danger : Colors.black87,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    subtitle,
                    style: TextStyle(fontSize: 12, color: Colors.grey.shade500),
                  ),
                ],
              ),
            ),
            Icon(
              Icons.chevron_right_rounded,
              color: Colors.grey.shade400,
            ),
          ],
        ),
      ),
    );
  }
}
