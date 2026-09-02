import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:halo_apu_mobile/core/providers/connectivity_provider.dart';
import 'package:halo_apu_mobile/core/services/pending_ticket_service.dart';
import 'package:halo_apu_mobile/core/theme/app_theme.dart';

/// Banner tipis indikator offline & antrian tiket yang belum terkirim.
class OfflineBanner extends ConsumerStatefulWidget {
  const OfflineBanner({super.key});

  @override
  ConsumerState<OfflineBanner> createState() => _OfflineBannerState();
}

class _OfflineBannerState extends ConsumerState<OfflineBanner> {
  bool _sending = false;

  @override
  Widget build(BuildContext context) {
    final isOnline = ref.watch(connectivityProvider);
    int pending;
    try {
      pending = PendingTicketService().pendingCount;
    } catch (_) {
      // Hive belum dibuka (mis. di widget test) — anggap tidak ada antrian
      pending = 0;
    }
    if (isOnline && pending == 0) return const SizedBox.shrink();

    final offline = !isOnline;
    final text = offline
        ? (pending > 0 ? 'Koneksi terputus — $pending tiket menunggu dikirim' : 'Koneksi terputus')
        : '$pending tiket menunggu dikirim otomatis';

    return Material(
      color: offline ? AppTheme.warning : AppTheme.oceanWater,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Row(
          children: [
            Icon(offline ? Icons.wifi_off : Icons.sync, size: 18, color: Colors.white),
            const SizedBox(width: 8),
            Expanded(
              child: Text(text, style: const TextStyle(color: Colors.white, fontSize: 13)),
            ),
            if (!offline)
              TextButton(
                onPressed: _sending ? null : _flushNow,
                style: TextButton.styleFrom(
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  minimumSize: Size.zero,
                ),
                child: Text(_sending ? 'Mengirim…' : 'Kirim sekarang'),
              ),
          ],
        ),
      ),
    );
  }

  Future<void> _flushNow() async {
    setState(() => _sending = true);
    await PendingTicketService().processQueue();
    if (mounted) {
      setState(() => _sending = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Mencoba mengirim ulang tiket...'), backgroundColor: AppTheme.success),
      );
    }
  }
}
