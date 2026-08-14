import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import '../../core/config/api_config.dart';

class AppAvatar extends StatelessWidget {
  final String? imageUrl;
  final String? imagePath;
  final Uint8List? imageBytes;
  final String name;
  final double radius;
  final double? fontSize;
  final VoidCallback? onTap;
  final bool showBorder;
  final Color? borderColor;

  const AppAvatar({
    super.key,
    this.imageUrl,
    this.imagePath,
    this.imageBytes,
    required this.name,
    this.radius = 22,
    this.fontSize,
    this.onTap,
    this.showBorder = true,
    this.borderColor,
  });

  String _getInitials(String input) {
    final trimmed = input.trim();
    if (trimmed.isEmpty) return '?';
    final parts = trimmed.split(RegExp(r'\s+'));
    if (parts.length == 1) {
      return parts[0].substring(0, parts[0].length >= 2 ? 2 : 1).toUpperCase();
    }
    return '${parts[0][0]}${parts[1][0]}'.toUpperCase();
  }

  LinearGradient _getGradientForName(String input) {
    final hash = input.hashCode.abs();
    final gradientIndex = hash % 4;

    switch (gradientIndex) {
      case 0:
        return const LinearGradient(
          colors: [Color(0xFF00768C), Color(0xFF00B4D8)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        );
      case 1:
        return const LinearGradient(
          colors: [Color(0xFF0066FF), Color(0xFF38BDF8)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        );
      case 2:
        return const LinearGradient(
          colors: [Color(0xFF0D9488), Color(0xFF2DD4BF)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        );
      case 3:
      default:
        return const LinearGradient(
          colors: [Color(0xFF2563EB), Color(0xFF60A5FA)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        );
    }
  }

  String _resolveImageUrl(String? url) {
    if (url == null || url.trim().isEmpty) return '';
    final trimmed = url.trim();
    if (trimmed.contains('ui-avatars.com') || trimmed.contains('pravatar.cc')) return '';

    if (trimmed.startsWith('blob:') || trimmed.startsWith('data:')) {
      return trimmed;
    }

    if (trimmed.contains('/attachments/serve')) {
      return trimmed;
    }

    if (trimmed.contains('/storage/')) {
      final cleanPath = trimmed.split('/storage/').last;
      return '${ApiConfig.baseUrl}/attachments/serve?path=$cleanPath';
    }

    if (trimmed.startsWith('avatars/') || trimmed.startsWith('ticket-attachments/')) {
      return '${ApiConfig.baseUrl}/attachments/serve?path=$trimmed';
    }

    return trimmed;
  }

  @override
  Widget build(BuildContext context) {
    final size = radius * 2;
    final initials = _getInitials(name);
    final calculatedFontSize = fontSize ?? (radius * 0.78);
    final resolvedUrl = _resolveImageUrl(imageUrl ?? imagePath);

    Widget avatarChild;

    if (imageBytes != null && imageBytes!.isNotEmpty) {
      avatarChild = Image.memory(
        imageBytes!,
        width: size,
        height: size,
        fit: BoxFit.cover,
        errorBuilder: (_, __, ___) => _buildInitialsPlaceholder(initials, calculatedFontSize),
      );
    } else if (resolvedUrl.isNotEmpty &&
        (resolvedUrl.startsWith('http://') ||
         resolvedUrl.startsWith('https://') ||
         resolvedUrl.startsWith('blob:'))) {
      avatarChild = Image.network(
        resolvedUrl,
        width: size,
        height: size,
        fit: BoxFit.cover,
        loadingBuilder: (context, child, loadingProgress) {
          if (loadingProgress == null) return child;
          return _buildInitialsPlaceholder(initials, calculatedFontSize);
        },
        errorBuilder: (_, __, ___) => _buildInitialsPlaceholder(initials, calculatedFontSize),
      );
    } else {
      avatarChild = _buildInitialsPlaceholder(initials, calculatedFontSize);
    }

    Widget content = Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: showBorder
            ? Border.all(
                color: borderColor ?? Colors.white,
                width: 2.0,
              )
            : null,
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.08),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: ClipOval(child: avatarChild),
    );

    if (onTap != null) {
      return InkWell(
        onTap: onTap,
        customBorder: const CircleBorder(),
        child: content,
      );
    }

    return content;
  }

  Widget _buildInitialsPlaceholder(String initials, double fontSize) {
    return Container(
      decoration: BoxDecoration(
        gradient: _getGradientForName(name),
        shape: BoxShape.circle,
      ),
      alignment: Alignment.center,
      child: Text(
        initials,
        style: TextStyle(
          color: Colors.white,
          fontSize: fontSize,
          fontWeight: FontWeight.bold,
          letterSpacing: 0.5,
        ),
      ),
    );
  }
}
