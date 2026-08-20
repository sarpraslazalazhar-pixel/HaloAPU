import 'dart:typed_data';
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:halo_apu_mobile/core/theme/app_theme.dart';

class CircularCropScreen extends StatefulWidget {
  final Uint8List imageBytes;

  const CircularCropScreen({
    super.key,
    required this.imageBytes,
  });

  @override
  State<CircularCropScreen> createState() => _CircularCropScreenState();
}

class _CircularCropScreenState extends State<CircularCropScreen> {
  final GlobalKey _cropKey = GlobalKey();
  final TransformationController _transformationController = TransformationController();
  int _rotationQuarterTurns = 0;
  bool _isProcessing = false;

  void _rotateClockwise() {
    setState(() {
      _rotationQuarterTurns = (_rotationQuarterTurns + 1) % 4;
      _transformationController.value = Matrix4.identity();
    });
  }

  void _rotateCounterClockwise() {
    setState(() {
      _rotationQuarterTurns = (_rotationQuarterTurns - 1 + 4) % 4;
      _transformationController.value = Matrix4.identity();
    });
  }

  void _resetZoom() {
    setState(() {
      _transformationController.value = Matrix4.identity();
    });
  }

  Future<void> _cropAndSave() async {
    if (_isProcessing) return;
    setState(() => _isProcessing = true);

    try {
      final boundary = _cropKey.currentContext?.findRenderObject() as RenderRepaintBoundary?;
      if (boundary == null) {
        Navigator.pop(context, widget.imageBytes);
        return;
      }

      // Capture high resolution cropped circle (pixelRatio: 3.0 gives ~900x900px crisp image)
      final ui.Image image = await boundary.toImage(pixelRatio: 3.0);
      final ByteData? byteData = await image.toByteData(format: ui.ImageByteFormat.png);

      if (byteData != null) {
        final Uint8List croppedBytes = byteData.buffer.asUint8List();
        if (mounted) {
          Navigator.pop(context, croppedBytes);
        }
      } else {
        if (mounted) {
          Navigator.pop(context, widget.imageBytes);
        }
      }
    } catch (_) {
      if (mounted) {
        Navigator.pop(context, widget.imageBytes);
      }
    } finally {
      if (mounted) setState(() => _isProcessing = false);
    }
  }

  @override
  void dispose() {
    _transformationController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final double screenWidth = MediaQuery.of(context).size.width;
    final double cropSize = screenWidth * 0.76;

    return Scaffold(
      backgroundColor: const Color(0xFF0F172A), // Deep dark matte
      appBar: AppBar(
        backgroundColor: const Color(0xFF0F172A),
        foregroundColor: Colors.white,
        elevation: 0,
        title: const Text(
          'Pangkas Foto Profil',
          style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
        ),
        leading: IconButton(
          icon: const Icon(Icons.close_rounded),
          onPressed: () => Navigator.pop(context),
        ),
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: TextButton.icon(
              onPressed: _isProcessing ? null : _cropAndSave,
              icon: _isProcessing
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                    )
                  : const Icon(Icons.check_rounded, color: AppTheme.oceanWater, size: 20),
              label: const Text(
                'Selesai',
                style: TextStyle(
                  color: AppTheme.oceanWater,
                  fontWeight: FontWeight.bold,
                  fontSize: 15,
                ),
              ),
            ),
          ),
        ],
      ),
      body: SafeArea(
        child: Column(
          children: [
            const SizedBox(height: 12),
            const Text(
              'Geser & cubit untuk memperbesar / memposisikan foto',
              style: TextStyle(color: Color(0xFF94A3B8), fontSize: 12),
            ),
            const SizedBox(height: 20),

            // Center Viewport Area
            Expanded(
              child: Center(
                child: Stack(
                  alignment: Alignment.center,
                  children: [
                    // Cropped Target Boundary
                    ClipOval(
                      child: RepaintBoundary(
                        key: _cropKey,
                        child: SizedBox(
                          width: cropSize,
                          height: cropSize,
                          child: InteractiveViewer(
                            transformationController: _transformationController,
                            minScale: 0.8,
                            maxScale: 4.5,
                            boundaryMargin: const EdgeInsets.all(double.infinity),
                            panEnabled: true,
                            scaleEnabled: true,
                            child: RotatedBox(
                              quarterTurns: _rotationQuarterTurns,
                              child: Image.memory(
                                widget.imageBytes,
                                fit: BoxFit.cover,
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),

                    // Circular Ring Overlay with Grid
                    IgnorePointer(
                      child: SizedBox(
                        width: cropSize,
                        height: cropSize,
                        child: CustomPaint(
                          painter: _CircleCropGuidePainter(),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),

            // Bottom Control Toolbar
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 20),
              decoration: const BoxDecoration(
                color: Color(0xFF1E293B),
                borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                    children: [
                      _buildToolButton(
                        icon: Icons.rotate_left_rounded,
                        label: 'Putar Kiri',
                        onTap: _rotateCounterClockwise,
                      ),
                      _buildToolButton(
                        icon: Icons.restart_alt_rounded,
                        label: 'Reset',
                        onTap: _resetZoom,
                      ),
                      _buildToolButton(
                        icon: Icons.rotate_right_rounded,
                        label: 'Putar Kanan',
                        onTap: _rotateClockwise,
                      ),
                    ],
                  ),
                  const SizedBox(height: 20),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton.icon(
                      onPressed: _isProcessing ? null : _cropAndSave,
                      icon: _isProcessing
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                            )
                          : const Icon(Icons.crop_rounded, size: 20),
                      label: Text(
                        _isProcessing ? 'Memproses...' : 'Terapkan Foto Profil',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                      ),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AppTheme.oceanWater,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(14),
                        ),
                        elevation: 4,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildToolButton({
    required IconData icon,
    required String label,
    required VoidCallback onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, color: Colors.white, size: 24),
            const SizedBox(height: 4),
            Text(
              label,
              style: const TextStyle(color: Color(0xFFCBD5E1), fontSize: 11),
            ),
          ],
        ),
      ),
    );
  }
}

class _CircleCropGuidePainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final double radius = size.width / 2;
    final Offset center = Offset(radius, radius);

    // Circular white glowing border
    final Paint borderPaint = Paint()
      ..color = Colors.white.withValues(alpha: 0.9)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.0;

    canvas.drawCircle(center, radius, borderPaint);

    // Subtle guide grid (rule of thirds)
    final Paint gridPaint = Paint()
      ..color = Colors.white.withValues(alpha: 0.25)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 0.8;

    final double oneThird = size.width / 3;
    final double twoThird = size.width * 2 / 3;

    // Draw clipped grid inside circle
    canvas.save();
    canvas.clipPath(Path()..addOval(Rect.fromCircle(center: center, radius: radius)));

    canvas.drawLine(Offset(oneThird, 0), Offset(oneThird, size.height), gridPaint);
    canvas.drawLine(Offset(twoThird, 0), Offset(twoThird, size.height), gridPaint);
    canvas.drawLine(Offset(0, oneThird), Offset(size.width, oneThird), gridPaint);
    canvas.drawLine(Offset(0, twoThird), Offset(size.width, twoThird), gridPaint);

    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
