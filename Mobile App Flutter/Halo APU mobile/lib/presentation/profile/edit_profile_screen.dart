import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';
import '../../core/theme/app_theme.dart';
import '../../domain/models/user_profile_model.dart';
import '../widgets/app_avatar.dart';
import 'providers/user_profile_provider.dart';

class EditProfileScreen extends ConsumerStatefulWidget {
  final StateNotifierProvider<UserProfileNotifier, UserProfile>?
      profileProvider;

  const EditProfileScreen({
    super.key,
    this.profileProvider,
  });

  @override
  ConsumerState<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends ConsumerState<EditProfileScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _usernameController;
  late final TextEditingController _nameController;
  late final TextEditingController _emailController;
  late final TextEditingController _phoneController;
  late String _avatarUrl;
  XFile? _pickedImage;
  Uint8List? _pickedImageBytes;
  bool _isSaving = false;

  @override
  void initState() {
    super.initState();
    final profile = ref.read(widget.profileProvider ?? userProfileProvider);
    _usernameController = TextEditingController(text: profile.username);
    _nameController = TextEditingController(text: profile.name);
    _emailController = TextEditingController(text: profile.email);
    _phoneController = TextEditingController(text: profile.phone);
    _avatarUrl = profile.avatarUrl;
  }

  @override
  void dispose() {
    _usernameController.dispose();
    _nameController.dispose();
    _emailController.dispose();
    _phoneController.dispose();
    super.dispose();
  }

  Future<void> _pickAvatar() async {
    final ImagePicker picker = ImagePicker();
    
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.transparent,
      builder: (sheetContext) => Container(
        padding: const EdgeInsets.fromLTRB(20, 24, 20, 32),
        decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'Ganti Foto Profil',
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 24),
            ListTile(
              leading: Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: AppTheme.oceanWater.withValues(alpha: 0.1),
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.photo_library_rounded, color: AppTheme.oceanWater),
              ),
              title: const Text('Pilih dari Galeri'),
              onTap: () async {
                Navigator.pop(sheetContext);
                final XFile? image = await picker.pickImage(
                  source: ImageSource.gallery,
                  maxWidth: 1024,
                  maxHeight: 1024,
                  imageQuality: 85,
                );
                if (image != null) {
                  final bytes = await image.readAsBytes();
                  setState(() {
                    _pickedImage = image;
                    _pickedImageBytes = bytes;
                  });
                }
              },
            ),
            const SizedBox(height: 8),
            ListTile(
              leading: Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: AppTheme.oceanWater.withValues(alpha: 0.1),
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.camera_alt_rounded, color: AppTheme.oceanWater),
              ),
              title: const Text('Ambil Foto'),
              onTap: () async {
                Navigator.pop(sheetContext);
                final XFile? image = await picker.pickImage(
                  source: ImageSource.camera,
                  maxWidth: 1024,
                  maxHeight: 1024,
                  imageQuality: 85,
                );
                if (image != null) {
                  final bytes = await image.readAsBytes();
                  setState(() {
                    _pickedImage = image;
                    _pickedImageBytes = bytes;
                  });
                }
              },
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isSaving = true);

    final success = await ref.read((widget.profileProvider ?? userProfileProvider).notifier).updateProfile(
          username: _usernameController.text.trim(),
          name: _nameController.text.trim(),
          phone: _phoneController.text.trim(),
          avatarUrl: _avatarUrl,
          avatarFile: _pickedImage,
        );

    if (!mounted) return;
    setState(() => _isSaving = false);

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        behavior: SnackBarBehavior.floating,
        content: Text(success ? 'Profil berhasil diperbarui' : 'Gagal memperbarui profil'),
        backgroundColor: success ? Colors.green : Colors.red,
      ),
    );
    
    if (success) {
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.lightBg,
      appBar: AppBar(
        title: const Text('Edit Profil', style: TextStyle(fontWeight: FontWeight.bold)),
        elevation: 0,
        backgroundColor: Colors.white,
        foregroundColor: Colors.black,
      ),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 24, 20, 40),
          children: [
            Center(
              child: InkWell(
                onTap: _pickAvatar,
                borderRadius: BorderRadius.circular(48),
                child: Stack(
                  children: [
                    AppAvatar(
                      name: _nameController.text.isNotEmpty ? _nameController.text : 'Pengguna',
                      imageUrl: _pickedImageBytes == null && _avatarUrl.startsWith('http') ? _avatarUrl : null,
                      imageBytes: _pickedImageBytes,
                      radius: 44,
                      fontSize: 32,
                    ),
                    Positioned(
                      right: 0,
                      bottom: 0,
                      child: Container(
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          color: AppTheme.brilliantBlue,
                          shape: BoxShape.circle,
                          border: Border.all(color: Colors.white, width: 2),
                        ),
                        child: const Icon(
                          Icons.photo_camera_rounded,
                          size: 16,
                          color: Colors.white,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 8),
            Center(
              child: Text(
                'Ketuk untuk ganti foto',
                style: TextStyle(fontSize: 12, color: Colors.grey.shade500),
              ),
            ),
            const SizedBox(height: 20),
            TextFormField(
              controller: _usernameController,
              textInputAction: TextInputAction.next,
              decoration: const InputDecoration(
                labelText: 'Username',
                prefixIcon: Icon(Icons.alternate_email_rounded),
              ),
              validator: (value) => (value == null || value.trim().isEmpty)
                  ? 'Username wajib diisi'
                  : null,
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _nameController,
              textInputAction: TextInputAction.next,
              decoration: const InputDecoration(
                labelText: 'Nama Lengkap',
                prefixIcon: Icon(Icons.person_outline_rounded),
              ),
              validator: (value) =>
                  (value == null || value.trim().isEmpty) ? 'Nama wajib diisi' : null,
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _emailController,
              keyboardType: TextInputType.emailAddress,
              textInputAction: TextInputAction.next,
              decoration: const InputDecoration(
                labelText: 'Email',
                prefixIcon: Icon(Icons.mail_outline_rounded),
              ),
              validator: (value) {
                if (value == null || value.trim().isEmpty) {
                  return 'Email wajib diisi';
                }
                if (!RegExp(r'^[\w.+-]+@[\w-]+\.[\w.]+$').hasMatch(value.trim())) {
                  return 'Format email tidak valid';
                }
                return null;
              },
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _phoneController,
              keyboardType: TextInputType.phone,
              textInputAction: TextInputAction.done,
              decoration: const InputDecoration(
                labelText: 'Nomor HP',
                prefixIcon: Icon(Icons.phone_outlined),
              ),
              validator: (value) =>
                  (value == null || value.trim().isEmpty) ? 'Nomor HP wajib diisi' : null,
              onFieldSubmitted: (_) => _save(),
            ),
            const SizedBox(height: 28),
            FilledButton(
              onPressed: _isSaving ? null : _save,
              child: _isSaving
                  ? const SizedBox(
                      width: 22,
                      height: 22,
                      child: CircularProgressIndicator(
                        strokeWidth: 2.5,
                        color: Colors.white,
                      ),
                    )
                  : const Text('Simpan Perubahan'),
            ),
          ],
        ),
      ),
    );
  }
}
