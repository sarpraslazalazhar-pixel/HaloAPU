import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

class UserBottomNav extends StatelessWidget {
  final int currentIndex;

  const UserBottomNav({super.key, required this.currentIndex});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: const BorderRadius.only(
          topLeft: Radius.circular(30),
          topRight: Radius.circular(30),
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.05),
            blurRadius: 20,
            offset: const Offset(0, -5),
          ),
        ],
      ),
      child: BottomAppBar(
        color: Colors.transparent,
        elevation: 0,
        shape: const CircularNotchedRectangle(),
        notchMargin: 12,
        child: SizedBox(
          height: 85,
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: [
              _buildNavItem(Icons.home_filled, 'Beranda', currentIndex == 0, onTap: () {
                if (currentIndex != 0) context.push('/dashboard/user');
              }),
              _buildNavItem(Icons.confirmation_number_outlined, 'Tiket', currentIndex == 1, onTap: () {
                if (currentIndex != 1) context.push('/tickets/user');
              }),
              const SizedBox(width: 48), // Space for FAB
              _buildNavItem(
                Icons.star_outline_rounded,
                'Rating',
                currentIndex == 2,
                activeIcon: Icons.star_rounded,
                onTap: () {
                  if (currentIndex != 2) context.push('/ratings/history');
                },
              ),
              _buildNavItem(Icons.person_outline, 'Profil', currentIndex == 3, onTap: () {
                if (currentIndex != 3) context.push('/profile');
              }),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildNavItem(
    IconData icon,
    String label,
    bool isActive, {
    IconData? activeIcon,
    bool showDot = false,
    VoidCallback? onTap,
  }) {
    return InkWell(
      onTap: onTap,
      splashColor: Colors.transparent,
      highlightColor: Colors.transparent,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          AnimatedContainer(
            duration: const Duration(milliseconds: 200),
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
            decoration: BoxDecoration(
              color: isActive ? const Color(0xFFD6F3F9) : Colors.transparent, // Very light cyan
              borderRadius: BorderRadius.circular(14),
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Stack(
                  children: [
                    AnimatedSwitcher(
                      duration: const Duration(milliseconds: 250),
                      transitionBuilder: (child, animation) =>
                          ScaleTransition(scale: animation, child: child),
                      child: Icon(
                        isActive ? (activeIcon ?? icon) : icon,
                        key: ValueKey(isActive ? (activeIcon ?? icon) : icon),
                        color: isActive ? const Color(0xFF00768C) : Colors.grey.shade500,
                        size: 24,
                      ),
                    ),
                    if (showDot)
                      Positioned(
                        right: 0,
                        top: 0,
                        child: Container(
                          width: 8,
                          height: 8,
                          decoration: BoxDecoration(
                            color: Colors.red,
                            shape: BoxShape.circle,
                            border: Border.all(color: Colors.white, width: 1.5),
                          ),
                        ),
                      )
                  ],
                ),
                const SizedBox(height: 4),
                Text(
                  label,
                  style: TextStyle(
                    fontSize: 10,
                    color: isActive ? const Color(0xFF00768C) : Colors.grey.shade500,
                    fontWeight: isActive ? FontWeight.bold : FontWeight.w600,
                  ),
                )
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class AdminBottomNav extends StatelessWidget {
  final int currentIndex;

  const AdminBottomNav({super.key, required this.currentIndex});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: const BorderRadius.only(
          topLeft: Radius.circular(30),
          topRight: Radius.circular(30),
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.05),
            blurRadius: 20,
            offset: const Offset(0, -5),
          ),
        ],
      ),
      child: BottomAppBar(
        color: Colors.transparent,
        elevation: 0,
        shape: const CircularNotchedRectangle(),
        notchMargin: 12,
        child: SizedBox(
          height: 85,
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: [
              _buildNavItem(Icons.dashboard, 'Dasbor', currentIndex == 0, onTap: () {
                if (currentIndex != 0) context.push('/dashboard/admin');
              }),
              _buildNavItem(Icons.view_list, 'Antrean', currentIndex == 1, onTap: () {
                if (currentIndex != 1) context.push('/tickets/admin');
              }),
              const SizedBox(width: 48), // Space for FAB
              _buildNavItem(
                Icons.star_outline_rounded,
                'Rating',
                currentIndex == 2,
                onTap: () {
                  if (currentIndex != 2) context.push('/ratings/admin');
                },
              ),
              _buildNavItem(Icons.settings_outlined, 'Pengaturan', currentIndex == 3, onTap: () {
                if (currentIndex != 3) context.push('/settings/admin');
              }),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildNavItem(IconData icon, String label, bool isActive, {bool showDot = false, VoidCallback? onTap}) {
    return InkWell(
      onTap: onTap,
      splashColor: Colors.transparent,
      highlightColor: Colors.transparent,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          AnimatedContainer(
            duration: const Duration(milliseconds: 200),
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
            decoration: BoxDecoration(
              color: isActive ? const Color(0xFFD6F3F9) : Colors.transparent,
              borderRadius: BorderRadius.circular(14),
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Stack(
                  children: [
                    Icon(
                      icon,
                      color: isActive ? const Color(0xFF00768C) : Colors.grey.shade500,
                      size: 24,
                    ),
                    if (showDot)
                      Positioned(
                        right: 0,
                        top: 0,
                        child: Container(
                          width: 8,
                          height: 8,
                          decoration: BoxDecoration(
                            color: Colors.red,
                            shape: BoxShape.circle,
                            border: Border.all(color: Colors.white, width: 1.5),
                          ),
                        ),
                      )
                  ],
                ),
                const SizedBox(height: 4),
                Text(
                  label,
                  style: TextStyle(
                    fontSize: 10,
                    color: isActive ? const Color(0xFF00768C) : Colors.grey.shade500,
                    fontWeight: isActive ? FontWeight.bold : FontWeight.w600,
                  ),
                )
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class CustomFloatingActionButton extends StatelessWidget {
  final VoidCallback onPressed;
  final IconData icon;

  const CustomFloatingActionButton({
    super.key,
    required this.onPressed,
    required this.icon,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(top: 32),
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: const LinearGradient(
          colors: [Color(0xFF00D2FF), Color(0xFF007BFF)], // Cyan to Blue
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF007BFF).withValues(alpha: 0.3),
            blurRadius: 12,
            offset: const Offset(0, 4),
          )
        ],
      ),
      child: FloatingActionButton(
        onPressed: onPressed,
        backgroundColor: Colors.transparent,
        elevation: 0,
        child: Icon(icon, color: Colors.white, size: 28),
      ),
    );
  }
}
