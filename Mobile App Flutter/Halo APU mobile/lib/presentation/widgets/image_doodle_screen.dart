import 'dart:async';
import 'dart:typed_data';
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:image_picker/image_picker.dart';
import 'package:halo_apu_mobile/core/theme/app_theme.dart';

enum DoodleMode {
  pen,
  arrow,
  rectangle,
  circle,
}

class DrawnItem {
  final DoodleMode mode;
  final List<Offset> points;
  final Color color;
  final double strokeWidth;

  DrawnItem({
    required this.mode,
    required this.points,
    required this.color,
    required this.strokeWidth,
  });
}

class ImageDoodleScreen extends StatefulWidget {
  final Uint8List imageBytes;
  final String? originalFileName;

  const ImageDoodleScreen({
    super.key,
    required this.imageBytes,
    this.originalFileName,
  });

  static Future<XFile?> annotate(BuildContext context, XFile file) async {
    final bytes = await file.readAsBytes();
    if (!context.mounted) return null;
    final resultBytes = await Navigator.push<Uint8List>(
      context,
      MaterialPageRoute(
        builder: (ctx) => ImageDoodleScreen(
          imageBytes: bytes,
          originalFileName: file.name,
        ),
      ),
    );

    if (resultBytes != null) {
      final String originalName = file.name.toLowerCase();
      final bool isPng = originalName.endsWith('.png');
      final String ext = isPng ? 'png' : 'jpg';
      final String mime = isPng ? 'image/png' : 'image/jpeg';
      final String baseName = file.name.replaceAll(RegExp(r'\.[a-zA-Z0-9]+$'), '');

      return XFile.fromData(
        resultBytes,
        name: '${baseName}_doodle_${DateTime.now().millisecondsSinceEpoch}.$ext',
        mimeType: mime,
      );
    }
    return null;
  }

  @override
  State<ImageDoodleScreen> createState() => _ImageDoodleScreenState();
}

class _ImageDoodleScreenState extends State<ImageDoodleScreen> {
  final GlobalKey _canvasKey = GlobalKey();
  final List<DrawnItem> _history = [];
  DrawnItem? _currentStroke;

  DoodleMode _selectedMode = DoodleMode.pen;
  Color _selectedColor = const Color(0xFFEF4444); // Red
  double _selectedStrokeWidth = 4.0;
  bool _isSaving = false;

  final List<Color> _colors = const [
    Color(0xFFEF4444), // Red
    Color(0xFFFACC15), // Yellow
    Color(0xFF00B8D9), // Cyan / Ocean Water
    Color(0xFF10B981), // Emerald
    Color(0xFFFFFFFF), // White
    Color(0xFF000000), // Black
  ];

  final List<double> _strokeWidths = const [3.0, 6.0, 10.0];

  void _onPanStart(DragStartDetails details) {
    final RenderBox? box = _canvasKey.currentContext?.findRenderObject() as RenderBox?;
    if (box == null) return;
    final localPos = box.globalToLocal(details.globalPosition);

    setState(() {
      _currentStroke = DrawnItem(
        mode: _selectedMode,
        points: [localPos],
        color: _selectedColor,
        strokeWidth: _selectedStrokeWidth,
      );
    });
  }

  void _onPanUpdate(DragUpdateDetails details) {
    final RenderBox? box = _canvasKey.currentContext?.findRenderObject() as RenderBox?;
    if (box == null || _currentStroke == null) return;
    final localPos = box.globalToLocal(details.globalPosition);

    setState(() {
      if (_selectedMode == DoodleMode.pen) {
        _currentStroke = DrawnItem(
          mode: _currentStroke!.mode,
          points: [..._currentStroke!.points, localPos],
          color: _currentStroke!.color,
          strokeWidth: _currentStroke!.strokeWidth,
        );
      } else {
        // Shapes: keep start point, replace end point
        _currentStroke = DrawnItem(
          mode: _currentStroke!.mode,
          points: [_currentStroke!.points.first, localPos],
          color: _currentStroke!.color,
          strokeWidth: _currentStroke!.strokeWidth,
        );
      }
    });
  }

  void _onPanEnd(DragEndDetails details) {
    if (_currentStroke != null) {
      setState(() {
        _history.add(_currentStroke!);
        _currentStroke = null;
      });
    }
  }

  void _undo() {
    if (_history.isNotEmpty) {
      setState(() {
        _history.removeLast();
      });
    }
  }

  void _clearAll() {
    setState(() {
      _history.clear();
      _currentStroke = null;
    });
  }

  Future<void> _saveAndReturn() async {
    if (_isSaving) return;
    setState(() => _isSaving = true);

    try {
      final boundary = _canvasKey.currentContext?.findRenderObject() as RenderRepaintBoundary?;
      if (boundary == null) {
        Navigator.pop(context, widget.imageBytes);
        return;
      }

      final ui.Image image = await boundary.toImage(pixelRatio: 2.5);
      final ByteData? byteData = await image.toByteData(format: ui.ImageByteFormat.png);

      if (byteData != null) {
        final Uint8List pngBytes = byteData.buffer.asUint8List();
        if (mounted) {
          Navigator.pop(context, pngBytes);
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
      if (mounted) setState(() => _isSaving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        backgroundColor: const Color(0xFF0F172A),
        foregroundColor: Colors.white,
        elevation: 0,
        title: const Text(
          'Coret & Tandai Gambar',
          style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
        ),
        leading: IconButton(
          icon: const Icon(Icons.close_rounded),
          onPressed: () => Navigator.pop(context),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.undo_rounded),
            tooltip: 'Undo',
            onPressed: _history.isNotEmpty ? _undo : null,
          ),
          IconButton(
            icon: const Icon(Icons.delete_outline_rounded),
            tooltip: 'Hapus Semua',
            onPressed: _history.isNotEmpty ? _clearAll : null,
          ),
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: TextButton.icon(
              onPressed: _isSaving ? null : _saveAndReturn,
              icon: _isSaving
                  ? const SizedBox(
                      width: 14,
                      height: 14,
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
            // Center Image Drawing Area
            Expanded(
              child: Center(
                child: Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: RepaintBoundary(
                    key: _canvasKey,
                    child: Stack(
                      fit: StackFit.passthrough,
                      alignment: Alignment.center,
                      children: [
                        // Base Image
                        Image.memory(
                          widget.imageBytes,
                          fit: BoxFit.contain,
                        ),

                        // Interactive Drawing Gesture Listener
                        Positioned.fill(
                          child: GestureDetector(
                            onPanStart: _onPanStart,
                            onPanUpdate: _onPanUpdate,
                            onPanEnd: _onPanEnd,
                            child: CustomPaint(
                              painter: _DoodlePainter(
                                history: _history,
                                currentStroke: _currentStroke,
                              ),
                              size: Size.infinite,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),

            // Bottom Toolbar (Tool modes, Colors, Stroke Widths)
            Container(
              padding: const EdgeInsets.fromLTRB(12, 12, 12, 16),
              decoration: const BoxDecoration(
                color: Color(0xFF1E293B),
                borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // Row 1: Tool Mode Selector (Scrollable & Responsive)
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        _buildModeChip(DoodleMode.pen, Icons.edit_rounded, 'Coret'),
                        const SizedBox(width: 8),
                        _buildModeChip(DoodleMode.arrow, Icons.arrow_outward_rounded, 'Panah'),
                        const SizedBox(width: 8),
                        _buildModeChip(DoodleMode.rectangle, Icons.crop_square_rounded, 'Kotak'),
                        const SizedBox(width: 8),
                        _buildModeChip(DoodleMode.circle, Icons.circle_outlined, 'Lingkaran'),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),

                  // Row 2: Color Palette & Thickness
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        // Colors
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: _colors.map((c) {
                            final isSelected = _selectedColor == c;
                            return Padding(
                              padding: const EdgeInsets.symmetric(horizontal: 5),
                              child: GestureDetector(
                                onTap: () => setState(() => _selectedColor = c),
                                child: Container(
                                  width: 26,
                                  height: 26,
                                  decoration: BoxDecoration(
                                    color: c,
                                    shape: BoxShape.circle,
                                    border: Border.all(
                                      color: isSelected ? Colors.white : Colors.grey.shade600,
                                      width: isSelected ? 3.0 : 1.0,
                                    ),
                                    boxShadow: isSelected
                                        ? [
                                            BoxShadow(
                                              color: c.withValues(alpha: 0.6),
                                              blurRadius: 8,
                                              spreadRadius: 2,
                                            )
                                          ]
                                        : null,
                                  ),
                                ),
                              ),
                            );
                          }).toList(),
                        ),
                        const SizedBox(width: 10),
                        Container(width: 1, height: 22, color: Colors.grey.shade700),
                        const SizedBox(width: 10),

                        // Stroke width selector
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: _strokeWidths.map((w) {
                            final isSelected = _selectedStrokeWidth == w;
                            return GestureDetector(
                              onTap: () => setState(() => _selectedStrokeWidth = w),
                              child: Container(
                                margin: const EdgeInsets.symmetric(horizontal: 3),
                                padding: const EdgeInsets.all(5),
                                decoration: BoxDecoration(
                                  color: isSelected ? const Color(0xFF334155) : Colors.transparent,
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: Container(
                                  width: w * 2.0,
                                  height: w * 2.0,
                                  decoration: BoxDecoration(
                                    color: isSelected ? _selectedColor : Colors.grey.shade400,
                                    shape: BoxShape.circle,
                                  ),
                                ),
                              ),
                            );
                          }).toList(),
                        ),
                      ],
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

  Widget _buildModeChip(DoodleMode mode, IconData icon, String label) {
    final isSelected = _selectedMode == mode;
    return InkWell(
      onTap: () => setState(() => _selectedMode = mode),
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 7),
        decoration: BoxDecoration(
          color: isSelected ? AppTheme.oceanWater : const Color(0xFF334155),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 15, color: Colors.white),
            const SizedBox(width: 5),
            Text(
              label,
              style: const TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.bold,
                fontSize: 11.5,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _DoodlePainter extends CustomPainter {
  final List<DrawnItem> history;
  final DrawnItem? currentStroke;

  _DoodlePainter({
    required this.history,
    this.currentStroke,
  });

  @override
  void paint(Canvas canvas, Size size) {
    for (final item in history) {
      _paintItem(canvas, item);
    }
    if (currentStroke != null) {
      _paintItem(canvas, currentStroke!);
    }
  }

  void _paintItem(Canvas canvas, DrawnItem item) {
    final Paint paint = Paint()
      ..color = item.color
      ..strokeWidth = item.strokeWidth
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..style = PaintingStyle.stroke;

    switch (item.mode) {
      case DoodleMode.pen:
        if (item.points.length < 2) return;
        final Path path = Path();
        path.moveTo(item.points.first.dx, item.points.first.dy);
        for (int i = 1; i < item.points.length; i++) {
          path.lineTo(item.points[i].dx, item.points[i].dy);
        }
        canvas.drawPath(path, paint);
        break;

      case DoodleMode.rectangle:
        if (item.points.length < 2) return;
        final rect = Rect.fromPoints(item.points.first, item.points.last);
        canvas.drawRRect(RRect.fromRectAndRadius(rect, const Radius.circular(6)), paint);
        break;

      case DoodleMode.circle:
        if (item.points.length < 2) return;
        final rect = Rect.fromPoints(item.points.first, item.points.last);
        canvas.drawOval(rect, paint);
        break;

      case DoodleMode.arrow:
        if (item.points.length < 2) return;
        final p1 = item.points.first;
        final p2 = item.points.last;
        canvas.drawLine(p1, p2, paint);

        // Draw arrowhead at p2
        final dx = p2.dx - p1.dx;
        final dy = p2.dy - p1.dy;
        final angle = (dx == 0 && dy == 0) ? 0.0 : (Offset(dx, dy).direction);

        const arrowSize = 16.0;
        const arrowAngle = 0.45; // ~25 deg

        final arrowPaint = Paint()
          ..color = item.color
          ..style = PaintingStyle.fill;

        final path = Path();
        path.moveTo(p2.dx, p2.dy);
        path.lineTo(
          p2.dx - arrowSize * (ui.lerpDouble(1, 1, 0)! * (Offset.fromDirection(angle - arrowAngle).dx)),
          p2.dy - arrowSize * (ui.lerpDouble(1, 1, 0)! * (Offset.fromDirection(angle - arrowAngle).dy)),
        );
        path.lineTo(
          p2.dx - arrowSize * (ui.lerpDouble(1, 1, 0)! * (Offset.fromDirection(angle + arrowAngle).dx)),
          p2.dy - arrowSize * (ui.lerpDouble(1, 1, 0)! * (Offset.fromDirection(angle + arrowAngle).dy)),
        );
        path.close();
        canvas.drawPath(path, arrowPaint);
        break;
    }
  }

  @override
  bool shouldRepaint(covariant _DoodlePainter oldDelegate) => true;
}
