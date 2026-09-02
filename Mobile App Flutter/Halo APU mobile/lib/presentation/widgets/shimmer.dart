import 'package:flutter/material.dart';

class Shimmer extends StatefulWidget {
  final Widget child;

  const Shimmer({super.key, required this.child});

  @override
  State<Shimmer> createState() => _ShimmerState();
}

class _ShimmerState extends State<Shimmer> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1300),
  )..repeat();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      child: widget.child,
      builder: (context, child) {
        return ShaderMask(
          blendMode: BlendMode.srcATop,
          shaderCallback: (bounds) {
            return LinearGradient(
              begin: Alignment.centerLeft,
              end: Alignment.centerRight,
              colors: [
                Colors.grey.shade200,
                Colors.grey.shade100,
                Colors.grey.shade200,
              ],
              stops: const [0.25, 0.5, 0.75],
              transform: _SlidingGradientTransform(_controller.value),
            ).createShader(bounds);
          },
          child: child,
        );
      },
    );
  }
}

class _SlidingGradientTransform extends GradientTransform {
  const _SlidingGradientTransform(this.percent);

  final double percent;

  @override
  Matrix4? transform(Rect bounds, {TextDirection? textDirection}) {
    return Matrix4.translationValues(bounds.width * (percent * 2 - 1), 0, 0);
  }
}

class ShimmerBox extends StatelessWidget {
  final double? width;
  final double height;
  final double radius;

  const ShimmerBox({
    super.key,
    this.width,
    required this.height,
    this.radius = 8,
  });

  @override
  Widget build(BuildContext context) {
    return Shimmer(
      child: Container(
        width: width,
        height: height,
        decoration: BoxDecoration(
          color: Colors.grey.shade200,
          borderRadius: BorderRadius.circular(radius),
        ),
      ),
    );
  }
}

/// Skeleon realistis untuk 4 kartu statistik Dashboard (Aktif, Selesai, Diproses, Ditolak)
class StatRowSkeleton extends StatelessWidget {
  const StatRowSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 24.0, vertical: 8.0),
      child: Row(
        children: [
          Expanded(
            child: Column(
              children: [
                _buildSingleStatSkeleton(),
                const SizedBox(height: 12),
                _buildSingleStatSkeleton(),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              children: [
                _buildSingleStatSkeleton(),
                const SizedBox(height: 12),
                _buildSingleStatSkeleton(),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSingleStatSkeleton() {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.02),
            blurRadius: 8,
            offset: const Offset(0, 4),
          )
        ],
      ),
      child: const Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              ShimmerBox(width: 36, height: 36, radius: 10),
              ShimmerBox(width: 32, height: 26, radius: 6),
            ],
          ),
          SizedBox(height: 14),
          ShimmerBox(width: 60, height: 14, radius: 4),
        ],
      ),
    );
  }
}

/// Skeleton untuk satu kartu tiket
class TicketCardSkeleton extends StatelessWidget {
  const TicketCardSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(20),
      margin: const EdgeInsets.only(bottom: 14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: Colors.grey.shade100),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.02),
            blurRadius: 15,
            offset: const Offset(0, 5),
          )
        ],
      ),
      child: const Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              ShimmerBox(width: 64, height: 22, radius: 6),
              ShimmerBox(width: 90, height: 16, radius: 4),
            ],
          ),
          SizedBox(height: 16),
          ShimmerBox(width: double.infinity, height: 18, radius: 4),
          SizedBox(height: 8),
          ShimmerBox(width: 180, height: 18, radius: 4),
          SizedBox(height: 16),
          ShimmerBox(width: 84, height: 26, radius: 20),
        ],
      ),
    );
  }
}

/// Skeleton untuk daftar tiket (Digunakan di Dashboard dan Layar Tiket)
class TicketListSkeleton extends StatelessWidget {
  final int count;
  final bool showHeader;

  const TicketListSkeleton({
    super.key,
    this.count = 3,
    this.showHeader = true,
  });

  @override
  Widget build(BuildContext context) {
    return ListView(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.symmetric(horizontal: 24.0, vertical: 8.0),
      children: [
        if (showHeader) ...[
          const Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              ShimmerBox(width: 120, height: 22, radius: 6),
              ShimmerBox(width: 80, height: 26, radius: 14),
            ],
          ),
          const SizedBox(height: 16),
        ],
        ...List.generate(count, (_) => const TicketCardSkeleton()),
      ],
    );
  }
}
