import 'package:flutter/material.dart';
import '../../core/theme/app_theme.dart';

class ChangePasswordScreen extends StatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  State<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends State<ChangePasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _oldPasswordController = TextEditingController();
  final _newPasswordController = TextEditingController();
  final _confirmPasswordController = TextEditingController();
  bool _isSaving = false;

  @override
  void dispose() {
    _oldPasswordController.dispose();
    _newPasswordController.dispose();
    _confirmPasswordController.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isSaving = true);
    await Future.delayed(const Duration(milliseconds: 900));

    if (!mounted) return;
    setState(() => _isSaving = false);
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        behavior: SnackBarBehavior.floating,
        content: Text('Kata sandi berhasil diubah'),
      ),
    );
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.lightBg,
      appBar: AppBar(
        title: const Text('Ubah Kata Sandi', style: TextStyle(fontWeight: FontWeight.bold)),
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
              child: Container(
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(
                  color: AppTheme.oceanWater.withValues(alpha: 0.1),
                  shape: BoxShape.circle,
                ),
                child: const Icon(
                  Icons.lock_outline_rounded,
                  size: 40,
                  color: AppTheme.oceanWater,
                ),
              ),
            ),
            const SizedBox(height: 28),
            _PasswordField(
              controller: _oldPasswordController,
              label: 'Kata Sandi Lama',
              validator: (value) =>
                  (value == null || value.isEmpty) ? 'Kata sandi lama wajib diisi' : null,
            ),
            const SizedBox(height: 16),
            _PasswordField(
              controller: _newPasswordController,
              label: 'Kata Sandi Baru',
              helperText: 'Minimal 8 karakter',
              validator: (value) {
                if (value == null || value.isEmpty) {
                  return 'Kata sandi baru wajib diisi';
                }
                if (value.length < 8) {
                  return 'Minimal 8 karakter';
                }
                return null;
              },
            ),
            const SizedBox(height: 16),
            _PasswordField(
              controller: _confirmPasswordController,
              label: 'Konfirmasi Kata Sandi Baru',
              validator: (value) {
                if (value == null || value.isEmpty) {
                  return 'Konfirmasi kata sandi wajib diisi';
                }
                if (value != _newPasswordController.text) {
                  return 'Kata sandi tidak sama';
                }
                return null;
              },
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
                  : const Text('Simpan Kata Sandi'),
            ),
          ],
        ),
      ),
    );
  }
}

class _PasswordField extends StatefulWidget {
  final TextEditingController controller;
  final String label;
  final String? helperText;
  final String? Function(String?) validator;
  final void Function(String)? onFieldSubmitted;

  const _PasswordField({
    required this.controller,
    required this.label,
    this.helperText,
    required this.validator,
    this.onFieldSubmitted,
  });

  @override
  State<_PasswordField> createState() => _PasswordFieldState();
}

class _PasswordFieldState extends State<_PasswordField> {
  bool _obscured = true;

  @override
  Widget build(BuildContext context) {
    return TextFormField(
      controller: widget.controller,
      obscureText: _obscured,
      textInputAction: TextInputAction.next,
      decoration: InputDecoration(
        labelText: widget.label,
        helperText: widget.helperText,
        prefixIcon: const Icon(Icons.key_rounded),
        suffixIcon: IconButton(
          icon: Icon(
            _obscured ? Icons.visibility_outlined : Icons.visibility_off_outlined,
            color: Colors.grey.shade500,
          ),
          onPressed: () => setState(() => _obscured = !_obscured),
        ),
      ),
      validator: widget.validator,
      onFieldSubmitted: widget.onFieldSubmitted,
    );
  }
}
