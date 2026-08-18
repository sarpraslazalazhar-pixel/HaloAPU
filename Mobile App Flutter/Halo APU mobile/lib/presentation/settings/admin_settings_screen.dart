import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../core/theme/app_theme.dart';
import '../../core/services/auth_service.dart';
import '../../core/services/biometric_service.dart';
import '../../data/repositories/auth_repository.dart';
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
                  const _AdminBiometricTile(),
                  const Divider(height: 1, indent: 60, endIndent: 16),
                  _SettingsTile(
                    icon: Icons.phonelink_lock_rounded,
                    color: const Color(0xFFD97706),
                    title: 'Buka Kunci Perangkat (1 Akun 1 HP)',
                    subtitle: 'Reset kunci HP user yang berganti perangkat',
                    onTap: () => _showDeviceManagementSheet(context, ref),
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

  void _showDeviceManagementSheet(BuildContext context, WidgetRef ref) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const _DeviceManagementBottomSheet(),
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
}

class _DeviceManagementBottomSheet extends ConsumerStatefulWidget {
  const _DeviceManagementBottomSheet();

  @override
  ConsumerState<_DeviceManagementBottomSheet> createState() => _DeviceManagementBottomSheetState();
}

class _DeviceManagementBottomSheetState extends ConsumerState<_DeviceManagementBottomSheet> {
  final TextEditingController _searchController = TextEditingController();
  bool _isLoading = true;
  bool _deviceLockEnabled = true;
  List<dynamic> _users = [];
  String _errorMessage = '';

  @override
  void initState() {
    super.initState();
    _loadUsers();
  }

  Future<void> _loadUsers([String query = '']) async {
    setState(() {
      _isLoading = true;
      _errorMessage = '';
    });

    try {
      final authRepo = ref.read(authRepositoryProvider);
      final response = await authRepo.getAdminUsersList(search: query);
      if (mounted) {
        setState(() {
          _users = response['data'] ?? [];
          _deviceLockEnabled = response['device_lock_enabled'] ?? true;
          _isLoading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _errorMessage = e.toString().replaceAll('Exception: ', '');
          _isLoading = false;
        });
      }
    }
  }

  Future<void> _toggleLock(bool val) async {
    setState(() => _deviceLockEnabled = val);
    try {
      final authRepo = ref.read(authRepositoryProvider);
      await authRepo.toggleDeviceLockSetting(val);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(val ? 'Kunci 1 Akun 1 HP diaktifkan' : 'Kunci 1 Akun 1 HP dinonaktifkan'),
            backgroundColor: val ? AppTheme.oceanWater : Colors.grey.shade800,
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        setState(() => _deviceLockEnabled = !val);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger),
        );
      }
    }
  }

  Future<void> _resetDevice(int userId, String userName, String? currentDevice) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogCtx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        title: const Row(
          children: [
            Icon(Icons.lock_open_rounded, color: Color(0xFFD97706)),
            SizedBox(width: 8),
            Text('Buka Kunci HP?'),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Buka kunci perangkat untuk pengguna:'),
            const SizedBox(height: 6),
            Text(
              userName,
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
            ),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: const Color(0xFFFFFBEB),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: const Color(0xFFFDE68A)),
              ),
              child: Row(
                children: [
                  const Icon(Icons.smartphone_rounded, size: 18, color: Color(0xFFB45309)),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      currentDevice ?? 'Smartphone Android',
                      style: const TextStyle(fontSize: 12.5, color: Color(0xFF92400E), fontWeight: FontWeight.w600),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 12),
            const Text(
              'Setelah dibuka, pengguna dapat login kembali di HP barunya.',
              style: TextStyle(fontSize: 12, color: Colors.black54),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogCtx, false),
            child: const Text('Batal'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: const Color(0xFFD97706),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            ),
            onPressed: () => Navigator.pop(dialogCtx, true),
            child: const Text('Buka Kunci'),
          ),
        ],
      ),
    );

    if (confirmed == true && mounted) {
      try {
        final authRepo = ref.read(authRepositoryProvider);
        await authRepo.resetUserDevice(userId);
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text('Kunci perangkat $userName berhasil dibuka!'),
              backgroundColor: AppTheme.oceanWater,
            ),
          );
          _loadUsers(_searchController.text.trim());
        }
      } catch (e) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.danger),
          );
        }
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      height: MediaQuery.of(context).size.height * 0.85,
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      child: Column(
        children: [
          // Drag handle
          Center(
            child: Container(
              margin: const EdgeInsets.only(top: 12, bottom: 8),
              width: 40,
              height: 4,
              decoration: BoxDecoration(
                color: Colors.grey.shade300,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
          ),

          // Header
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: const Color(0xFFFFFBEB),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(Icons.phonelink_lock_rounded, color: Color(0xFFD97706), size: 22),
                ),
                const SizedBox(width: 12),
                const Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Kunci Perangkat (1 Akun 1 HP)',
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                      Text(
                        'Buka kunci akun pengguna yang berganti HP',
                        style: TextStyle(color: Colors.grey, fontSize: 12),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close, color: Colors.grey),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
          ),

          const Divider(height: 1),

          // Master Switch Banner
          Container(
            margin: const EdgeInsets.all(16),
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: BoxDecoration(
              color: _deviceLockEnabled ? const Color(0xFFF0FDF4) : const Color(0xFFF8FAFC),
              borderRadius: BorderRadius.circular(16),
              border: Border.all(
                color: _deviceLockEnabled ? const Color(0xFFBBF7D0) : const Color(0xFFE2E8F0),
              ),
            ),
            child: Row(
              children: [
                Icon(
                  _deviceLockEnabled ? Icons.shield_rounded : Icons.shield_outlined,
                  color: _deviceLockEnabled ? const Color(0xFF16A34A) : Colors.grey,
                  size: 24,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Aturan 1 Akun 1 HP',
                        style: TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 13.5,
                          color: _deviceLockEnabled ? const Color(0xFF166534) : const Color(0xFF334155),
                        ),
                      ),
                      Text(
                        _deviceLockEnabled ? 'Aktif (1 akun terkunci di 1 HP)' : 'Nonaktif (Bebas login di mana saja)',
                        style: TextStyle(
                          fontSize: 11.5,
                          color: _deviceLockEnabled ? const Color(0xFF15803D) : Colors.grey.shade600,
                        ),
                      ),
                    ],
                  ),
                ),
                Switch(
                  value: _deviceLockEnabled,
                  activeTrackColor: const Color(0xFF16A34A),
                  onChanged: _toggleLock,
                ),
              ],
            ),
          ),

          // Search Field
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: TextField(
              controller: _searchController,
              decoration: InputDecoration(
                hintText: 'Cari nama, username, atau email...',
                hintStyle: TextStyle(fontSize: 13, color: Colors.grey.shade400),
                prefixIcon: const Icon(Icons.search, size: 20, color: Colors.grey),
                suffixIcon: _searchController.text.isNotEmpty
                    ? IconButton(
                        icon: const Icon(Icons.clear, size: 18),
                        onPressed: () {
                          _searchController.clear();
                          _loadUsers('');
                        },
                      )
                    : null,
                filled: true,
                fillColor: const Color(0xFFF8FAFC),
                contentPadding: const EdgeInsets.symmetric(vertical: 0, horizontal: 16),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(14),
                  borderSide: const BorderSide(color: Color(0xFFE2E8F0)),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(14),
                  borderSide: const BorderSide(color: Color(0xFFE2E8F0)),
                ),
              ),
              onChanged: (val) {
                // Debounced lookup
                Future.delayed(const Duration(milliseconds: 350), () {
                  if (_searchController.text == val) {
                    _loadUsers(val.trim());
                  }
                });
              },
            ),
          ),

          const SizedBox(height: 10),

          // User List
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : _errorMessage.isNotEmpty
                    ? Center(
                        child: Padding(
                          padding: const EdgeInsets.all(20),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              const Icon(Icons.error_outline, size: 40, color: AppTheme.danger),
                              const SizedBox(height: 8),
                              Text(_errorMessage, textAlign: TextAlign.center),
                              const SizedBox(height: 12),
                              OutlinedButton(
                                onPressed: () => _loadUsers(_searchController.text),
                                child: const Text('Coba Lagi'),
                              ),
                            ],
                          ),
                        ),
                      )
                    : _users.isEmpty
                        ? Center(
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(Icons.person_off_outlined, size: 48, color: Colors.grey.shade300),
                                const SizedBox(height: 8),
                                Text(
                                  'Tidak ada pengguna ditemukan',
                                  style: TextStyle(color: Colors.grey.shade500),
                                ),
                              ],
                            ),
                          )
                        : ListView.separated(
                            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                            itemCount: _users.length,
                            separatorBuilder: (_, __) => const SizedBox(height: 10),
                            itemBuilder: (ctx, idx) {
                              final u = _users[idx];
                              final isLocked = u['isDeviceLocked'] == true;
                              final name = u['name'] ?? u['username'] ?? 'User';
                              final department = u['department'] ?? '';
                              final deviceName = u['deviceName'] ?? 'Smartphone Android';

                              return Container(
                                padding: const EdgeInsets.all(14),
                                decoration: BoxDecoration(
                                  color: Colors.white,
                                  borderRadius: BorderRadius.circular(16),
                                  border: Border.all(
                                    color: isLocked ? const Color(0xFFFDE68A) : const Color(0xFFE2E8F0),
                                  ),
                                  boxShadow: [
                                    BoxShadow(
                                      color: Colors.black.withValues(alpha: 0.02),
                                      blurRadius: 6,
                                      offset: const Offset(0, 2),
                                    ),
                                  ],
                                ),
                                child: Row(
                                  children: [
                                    // User Avatar Circle
                                    CircleAvatar(
                                      radius: 20,
                                      backgroundColor: isLocked ? const Color(0xFFFEF3C7) : const Color(0xFFF1F5F9),
                                      child: Text(
                                        name.isNotEmpty ? name[0].toUpperCase() : 'U',
                                        style: TextStyle(
                                          fontWeight: FontWeight.bold,
                                          color: isLocked ? const Color(0xFFB45309) : const Color(0xFF475569),
                                        ),
                                      ),
                                    ),
                                    const SizedBox(width: 12),

                                    // Info
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          Text(
                                            name,
                                            style: const TextStyle(
                                              fontWeight: FontWeight.bold,
                                              fontSize: 14,
                                              color: Color(0xFF1E293B),
                                            ),
                                          ),
                                          if (department.isNotEmpty)
                                            Text(
                                              department,
                                              style: TextStyle(
                                                fontSize: 11.5,
                                                color: Colors.grey.shade500,
                                              ),
                                            ),
                                          const SizedBox(height: 4),
                                          // Device Badge
                                          if (isLocked)
                                            Container(
                                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                                              decoration: BoxDecoration(
                                                color: const Color(0xFFFFFBEB),
                                                borderRadius: BorderRadius.circular(6),
                                                border: Border.all(color: const Color(0xFFFDE68A)),
                                              ),
                                              child: Row(
                                                mainAxisSize: MainAxisSize.min,
                                                children: [
                                                  const Icon(Icons.smartphone_rounded, size: 12, color: Color(0xFFD97706)),
                                                  const SizedBox(width: 4),
                                                  Flexible(
                                                    child: Text(
                                                      'Terikat: $deviceName',
                                                      style: const TextStyle(
                                                        fontSize: 11,
                                                        fontWeight: FontWeight.w600,
                                                        color: Color(0xFF92400E),
                                                      ),
                                                      overflow: TextOverflow.ellipsis,
                                                    ),
                                                  ),
                                                ],
                                              ),
                                            )
                                          else
                                            Container(
                                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                                              decoration: BoxDecoration(
                                                color: const Color(0xFFF0FDF4),
                                                borderRadius: BorderRadius.circular(6),
                                                border: Border.all(color: const Color(0xFFBBF7D0)),
                                              ),
                                              child: const Row(
                                                mainAxisSize: MainAxisSize.min,
                                                children: [
                                                  Icon(Icons.check_circle_outline_rounded, size: 12, color: Color(0xFF16A34A)),
                                                  SizedBox(width: 4),
                                                  Text(
                                                    'Belum Terikat HP',
                                                    style: TextStyle(
                                                      fontSize: 11,
                                                      fontWeight: FontWeight.w600,
                                                      color: Color(0xFF166534),
                                                    ),
                                                  ),
                                                ],
                                              ),
                                            ),
                                        ],
                                      ),
                                    ),

                                    // Action Button
                                    if (isLocked)
                                      FilledButton.icon(
                                        onPressed: () => _resetDevice(u['id'], name, u['deviceName']),
                                        icon: const Icon(Icons.lock_open_rounded, size: 14),
                                        label: const Text('Buka', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                                        style: FilledButton.styleFrom(
                                          backgroundColor: const Color(0xFFD97706),
                                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                                          minimumSize: const Size(60, 32),
                                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                                        ),
                                      ),
                                  ],
                                ),
                              );
                            },
                          ),
          ),
        ],
      ),
    );
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

class _AdminBiometricTile extends StatefulWidget {
  const _AdminBiometricTile();

  @override
  State<_AdminBiometricTile> createState() => _AdminBiometricTileState();
}

class _AdminBiometricTileState extends State<_AdminBiometricTile> {
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
      final role = await storage.read(key: 'user_role') ?? 'admin';
      final userData = await storage.read(key: 'user_data') ?? '{}';
      if (token != null) {
        String name = 'Admin';
        String email = '';
        try {
          final decoded = jsonDecode(userData);
          name = decoded['name'] ?? decoded['username'] ?? 'Admin';
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
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
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
    );
  }
}
