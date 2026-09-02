import 'package:flutter/material.dart';
import '../../core/theme/app_theme.dart';

class PaginationFooter extends StatelessWidget {
  final bool isLoading;
  final bool hasMore;

  const PaginationFooter({super.key, this.isLoading = false, this.hasMore = true});

  @override
  Widget build(BuildContext context) {
    if (isLoading) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 20),
        child: Center(
          child: SizedBox(
            width: 22,
            height: 22,
            child: CircularProgressIndicator(
              strokeWidth: 2.5,
              color: AppTheme.oceanWater,
            ),
          ),
        ),
      );
    }
    if (!hasMore) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 16),
        child: Center(
          child: Text(
            'Semua data sudah dimuat',
            style: TextStyle(fontSize: 12, color: Colors.grey.shade400),
          ),
        ),
      );
    }
    return const SizedBox.shrink();
  }
}
