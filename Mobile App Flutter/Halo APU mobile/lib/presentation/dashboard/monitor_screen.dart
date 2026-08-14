import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:halo_apu_mobile/domain/models/monitor_asset_model.dart';
import 'package:halo_apu_mobile/domain/models/monitor_calendar_model.dart';
import 'package:halo_apu_mobile/presentation/dashboard/providers/monitor_provider.dart';
import 'package:halo_apu_mobile/presentation/widgets/shimmer.dart';
import 'package:halo_apu_mobile/presentation/widgets/entrance_animation.dart';

class MonitorScreen extends ConsumerStatefulWidget {
  const MonitorScreen({super.key});

  @override
  ConsumerState<MonitorScreen> createState() => _MonitorScreenState();
}

class _MonitorScreenState extends ConsumerState<MonitorScreen> {
  int _selectedIndex = 0; // 0 for Aset, 1 for Kalender

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF7FAFC),
      appBar: AppBar(
        title: const Text('Pantauan Langsung', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
        backgroundColor: Colors.white,
        foregroundColor: Colors.black,
        elevation: 0,
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(60),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16.0, vertical: 8.0),
            child: Container(
              decoration: BoxDecoration(
                color: Colors.grey.shade200,
                borderRadius: BorderRadius.circular(25),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: GestureDetector(
                      onTap: () => setState(() => _selectedIndex = 0),
                      child: Container(
                        padding: const EdgeInsets.symmetric(vertical: 10),
                        decoration: BoxDecoration(
                          color: _selectedIndex == 0 ? const Color(0xFF0066FF) : Colors.transparent,
                          borderRadius: BorderRadius.circular(25),
                        ),
                        alignment: Alignment.center,
                        child: Text(
                          'Daftar Aset',
                          style: TextStyle(
                            color: _selectedIndex == 0 ? Colors.white : Colors.grey.shade700,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ),
                    ),
                  ),
                  Expanded(
                    child: GestureDetector(
                      onTap: () => setState(() => _selectedIndex = 1),
                      child: Container(
                        padding: const EdgeInsets.symmetric(vertical: 10),
                        decoration: BoxDecoration(
                          color: _selectedIndex == 1 ? const Color(0xFF00B8D9) : Colors.transparent,
                          borderRadius: BorderRadius.circular(25),
                        ),
                        alignment: Alignment.center,
                        child: Text(
                          'Kalender',
                          style: TextStyle(
                            color: _selectedIndex == 1 ? Colors.white : Colors.grey.shade700,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
      body: _selectedIndex == 0 ? const _AssetListView() : const _CalendarView(),
    );
  }
}

class _AssetListView extends ConsumerWidget {
  const _AssetListView();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(monitorProvider);

    return state.assets.when(
      data: (assets) {
        if (assets.isEmpty) {
          return Center(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(Icons.monitor_heart_outlined, size: 64, color: Colors.grey.shade300),
                const SizedBox(height: 16),
                Text('Belum ada data aset yang dipantau', style: TextStyle(color: Colors.grey.shade600)),
              ],
            ),
          );
        }

        // Group assets by tipe
        final Map<String, List<MonitorAssetModel>> groupedAssets = {};
        for (var asset in assets) {
          final type = asset.tipe.isEmpty ? 'Lainnya' : asset.tipe;
          if (!groupedAssets.containsKey(type)) {
            groupedAssets[type] = [];
          }
          groupedAssets[type]!.add(asset);
        }

        return RefreshIndicator(
          onRefresh: () => ref.read(monitorProvider.notifier).refresh(),
          child: ListView.builder(
            padding: const EdgeInsets.all(16),
            itemCount: groupedAssets.keys.length,
            itemBuilder: (context, index) {
              final type = groupedAssets.keys.elementAt(index);
              final typeAssets = groupedAssets[type]!;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  EntranceAnimation(
                    delay: Duration(milliseconds: 100 * index),
                    child: Padding(
                      padding: EdgeInsets.only(bottom: 12, top: index == 0 ? 0 : 16),
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                        decoration: BoxDecoration(
                          color: const Color(0xFFEFF6FF), // Light blue bg
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          type,
                          style: const TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.bold,
                            color: Color(0xFF3B82F6), // Blue text
                          ),
                        ),
                      ),
                    ),
                  ),
                  ...typeAssets.map((asset) => EntranceAnimation(
                        delay: Duration(milliseconds: 100 * index + 50),
                        child: _AssetCard(asset: asset),
                      )),
                ],
              );
            },
          ),
        );
      },
      loading: () => ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: 4,
        itemBuilder: (context, index) => const Padding(
          padding: EdgeInsets.only(bottom: 16),
          child: ShimmerBox(height: 120, radius: 20),
        ),
      ),
      error: (err, stack) => Center(child: Text('Terjadi kesalahan:\n$err', textAlign: TextAlign.center)),
    );
  }
}

class _AssetCard extends StatelessWidget {
  final MonitorAssetModel asset;
  const _AssetCard({required this.asset});

  @override
  Widget build(BuildContext context) {
    Color statusColor;
    Color statusBgColor;

    switch (asset.status) {
      case 'Tersedia':
        statusColor = const Color(0xFF22C55E); // Green
        statusBgColor = const Color(0xFFECFDF5);
        break;
      case 'Sedang Dipakai':
        statusColor = const Color(0xFFEF4444); // Red
        statusBgColor = const Color(0xFFFEF2F2);
        break;
      case 'Dipesan':
      case 'Menunggu Persetujuan':
      default:
        statusColor = const Color(0xFFF59E0B); // Orange
        statusBgColor = const Color(0xFFFFFBEB);
        break;
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.04),
            blurRadius: 18,
            offset: const Offset(0, 4),
          )
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Row(
                    children: [
                      Icon(
                        asset.tipe.toLowerCase().contains('kendaraan') || asset.tipe.toLowerCase().contains('mobil')
                            ? Icons.directions_car
                            : Icons.meeting_room,
                        color: Colors.grey.shade500,
                        size: 20,
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          asset.namaAset,
                          style: const TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.bold,
                            color: Color(0xFF1E293B),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: statusBgColor,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: statusColor.withValues(alpha: 0.2)),
                  ),
                  child: Text(
                    asset.status,
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.bold,
                      color: statusColor,
                    ),
                  ),
                ),
              ],
            ),
            if (asset.user != null) ...[
              const SizedBox(height: 16),
              const Divider(height: 1, color: Color(0xFFF1F5F9)),
              const SizedBox(height: 16),
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF1F5F9),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: const Icon(Icons.person_outline, size: 16, color: Color(0xFF64748B)),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      asset.user!,
                      style: const TextStyle(fontSize: 14, color: Color(0xFF475569)),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF1F5F9),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: const Icon(Icons.access_time, size: 16, color: Color(0xFF64748B)),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      asset.waktu ?? '',
                      style: const TextStyle(fontSize: 14, color: Color(0xFF475569)),
                    ),
                  ),
                ],
              ),
            ]
          ],
        ),
      ),
    );
  }
}

class _CalendarView extends ConsumerWidget {
  const _CalendarView();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(monitorProvider);

    return state.calendar.when(
      data: (calendarData) {
        if (calendarData.isEmpty) {
          return Center(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(Icons.calendar_today_outlined, size: 64, color: Colors.grey.shade300),
                const SizedBox(height: 16),
                Text('Belum ada jadwal booking', style: TextStyle(color: Colors.grey.shade600)),
              ],
            ),
          );
        }

        return RefreshIndicator(
          onRefresh: () => ref.read(monitorProvider.notifier).refresh(),
          child: ListView.builder(
            padding: const EdgeInsets.all(16),
            itemCount: calendarData.length,
            itemBuilder: (context, index) {
              final dateGroup = calendarData[index];
              return EntranceAnimation(
                delay: Duration(milliseconds: 100 * index),
                child: _CalendarDayGroup(dateGroup: dateGroup),
              );
            },
          ),
        );
      },
      loading: () => ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: 3,
        itemBuilder: (context, index) => const Padding(
          padding: EdgeInsets.only(bottom: 24),
          child: ShimmerBox(height: 180, radius: 20),
        ),
      ),
      error: (err, stack) => Center(child: Text('Terjadi kesalahan:\n$err', textAlign: TextAlign.center)),
    );
  }
}

class _CalendarDayGroup extends StatelessWidget {
  final MonitorCalendarModel dateGroup;
  const _CalendarDayGroup({required this.dateGroup});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 4,
                height: 16,
                decoration: BoxDecoration(
                  color: const Color(0xFF00B8D9),
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
              const SizedBox(width: 8),
              Text(
                _formatDate(dateGroup.date),
                style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF334155),
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          ...dateGroup.bookings.map((b) => _CalendarBookingCard(booking: b)),
        ],
      ),
    );
  }

  String _formatDate(String dateString) {
    // Basic formatting for YYYY-MM-DD
    try {
      final date = DateTime.parse(dateString);
      final months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
      return '${date.day} ${months[date.month - 1]} ${date.year}';
    } catch (e) {
      return dateString;
    }
  }
}

class _CalendarBookingCard extends StatelessWidget {
  final MonitorCalendarItemModel booking;
  const _CalendarBookingCard({required this.booking});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFF1F5F9)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: const Color(0xFFEFF6FF),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(
              booking.tipe.toLowerCase().contains('kendaraan') || booking.tipe.toLowerCase().contains('mobil')
                  ? Icons.directions_car 
                  : Icons.meeting_room,
              color: const Color(0xFF3B82F6),
              size: 24,
            ),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  booking.namaAset,
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: Color(0xFF1E293B)),
                ),
                const SizedBox(height: 4),
                Text(
                  '${booking.user} • ${booking.waktu}',
                  style: const TextStyle(fontSize: 13, color: Color(0xFF64748B)),
                ),
                const SizedBox(height: 8),
                Text(
                  booking.status.toUpperCase(),
                  style: TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.bold,
                    color: booking.status == 'open' ? const Color(0xFFF59E0B) : const Color(0xFF0066FF),
                    letterSpacing: 0.5,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
