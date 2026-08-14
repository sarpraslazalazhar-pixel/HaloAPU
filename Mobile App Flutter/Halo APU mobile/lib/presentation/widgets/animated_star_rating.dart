import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';

class AnimatedStarRating extends StatefulWidget {
  final int rating;
  final double size;

  const AnimatedStarRating({
    super.key,
    required this.rating,
    this.size = 20,
  });

  @override
  State<AnimatedStarRating> createState() => _AnimatedStarRatingState();
}

class _AnimatedStarRatingState extends State<AnimatedStarRating>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 700),
  )..forward();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: List.generate(5, (index) {
        final starAnim = CurvedAnimation(
          parent: _controller,
          curve: Interval(
            index * 0.12,
            (0.55 + index * 0.12).clamp(0.0, 1.0),
            curve: Curves.easeOutBack,
          ),
        );
        return AnimatedBuilder(
          animation: starAnim,
          builder: (context, child) {
            return Transform.scale(
              scale: 0.2 + (starAnim.value * 0.8),
              child: Opacity(
                opacity: starAnim.value.clamp(0.0, 1.0),
                child: child,
              ),
            );
          },
          child: Padding(
            padding: const EdgeInsets.only(right: 3),
            child: Icon(
              index < widget.rating ? Icons.star_rounded : Icons.star_outline_rounded,
              size: widget.size,
              color: index < widget.rating
                  ? AppTheme.warning
                  : Colors.grey.shade300,
            ),
          ),
        );
      }),
    );
  }
}
