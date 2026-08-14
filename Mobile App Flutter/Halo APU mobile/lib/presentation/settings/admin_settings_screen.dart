import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../core/theme/app_theme.dart';
import '../profile/providers/user_profile_provider.dart';
import '../widgets/custom_bottom_nav.dart';
import '../widgets/entrance_animation.dart';
import '../widgets/app_avatar.dart';

class AdminSettingsScreen extends ConsumerWidget {
  const AdminSettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profileState = ref.watch(adminProfileProvider);

    return Scaffold(
      backgroundColor: AppTheme.lightBg,
      appBar: AppBar(
        title: const Text('Pengaturan', style: TextStyle(fontWeight: FontWeight.bold)),
        elevation: 0,
        backgroundColor: Colors.white,
        foregroundColor: Colors.black,
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
        children: [
          EntranceAnimation(
            child: Container(
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
                    name: profileState.name.isNotEmpty ? profileState.name : 'Admin',
                    imageUrl: profileState.avatarUrl,
                    radius: 28,
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          profileState.name,
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 17,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        const SizedBox(height: 3),
                        Text(
                          profileState.position,
                          style: const TextStyle(
                            color: Colors.white70,
                            fontSize: 12.5,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          EntranceAnimation(
            delay: const Duration(milliseconds: 120),
            child: Container(
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(20),
              ),
              child: Column(
                children: [
                  _SettingsTile(
                    icon: Icons.person_outline_rounded,
                    color: AppTheme.oceanWater,
                    title: 'Edit Profil',
                    subtitle: 'Perbarui nama, username, dan foto',
                    onTap: () => context.push('/settings/edit-admin'),
                  ),
                  const Divider(height: 1, indent: 60, endIndent: 16),
                  _SettingsTile(
                    icon: Icons.star_outline_rounded,
                    color: AppTheme.brilliantBlue,
                    title: 'Riwayat Rating',
                    subtitle: 'Rating tiket yang Anda tangani',
                    onTap: () => context.push('/ratings/admin'),
                  ),
                  const Divider(height: 1, indent: 60, endIndent: 16),
                  _SettingsTile(
                    icon: Icons.language_rounded,
                    color: AppTheme.oceanWater,
                    title: 'Buka Halo APU Web',
                    subtitle: 'Kelola penuh via dev.haloapu.id',
                    onTap: () async {
                      await launchUrl(
                        Uri.parse('https://dev.haloapu.id'),
                        mode: LaunchMode.externalApplication,
                      );
                    },
                  ),
                  const Divider(height: 1, indent: 60, endIndent: 16),
                  const _SettingsTile(
                    icon: Icons.info_outline_rounded,
                    color: AppTheme.brilliantBlue,
                    title: 'Versi Aplikasi',
                    subtitle: '1.0.0',
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          EntranceAnimation(
            delay: const Duration(milliseconds: 240),
            child: Container(
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(20),
              ),
              child: _SettingsTile(
                icon: Icons.logout_rounded,
                color: AppTheme.danger,
                title: 'Keluar',
                subtitle: 'Akhiri sesi akun admin',
                isDanger: true,
                onTap: () => _confirmLogout(context),
              ),
            ),
          ),
        ],
      ),
      extendBody: true,
      bottomNavigationBar: const AdminBottomNav(currentIndex: 3),
      floatingActionButton: CustomFloatingActionButton(
        icon: Icons.language,
        onPressed: () async {
          final Uri url = Uri.parse('https://dev.haloapu.id');
          await launchUrl(url, mode: LaunchMode.externalApplication);
        },
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
      context.go('/login');
    }
  }
}

class _SettingsTile extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String title;
  final String subtitle;
  final bool isDanger;
  final VoidCallback? onTap;

  const _SettingsTile({
    required this.icon,
    required this.color,
    required this.title,
    required this.subtitle,
    this.isDanger = false,
    this.onTap,
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
            if (onTap != null)
              Icon(Icons.chevron_right_rounded, color: Colors.grey.shade400),
          ],
        ),
      ),
    );
  }
}
