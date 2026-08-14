import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:halo_apu_mobile/core/services/pending_ticket_service.dart';

class ConnectivityNotifier extends StateNotifier<bool> {
  ConnectivityNotifier() : super(true) {
    Connectivity().onConnectivityChanged.listen(
      (results) {
        final online = !results.contains(ConnectivityResult.none);
        final wasOffline = !state;
        state = online;
        if (online && wasOffline) {
          PendingTicketService().processQueue();
        }
      },
      onError: (_) {
        // Platform tanpa konektivitas (mis. widget test) — abaikan
      },
    );
  }
}

final connectivityProvider = StateNotifierProvider<ConnectivityNotifier, bool>(
  (ref) => ConnectivityNotifier(),
);
