import 'package:hive_flutter/hive_flutter.dart';
import 'package:halo_apu_mobile/data/api/api_client.dart';

class PendingTicketService {
  final ApiClient _apiClient = ApiClient();
  static const String boxName = 'pending_tickets';

  static Future<void> init() async {
    await Hive.openBox(boxName);
  }

  Future<void> enqueue(Map<String, dynamic> ticketPayload) async {
    final box = Hive.box(boxName);
    final id = DateTime.now().millisecondsSinceEpoch.toString();
    await box.put(id, ticketPayload);
  }

  Future<void> processQueue() async {
    final box = Hive.box(boxName);
    if (box.isEmpty) return;

    final keys = box.keys.toList();
    for (var key in keys) {
      final Map<String, dynamic>? payload = box.get(key) != null
          ? Map<String, dynamic>.from(box.get(key) as Map)
          : null;
      if (payload == null) continue;
      try {
        final res = await _apiClient.dio.post('/tickets', data: payload);
        if (res.statusCode == 200 || res.statusCode == 201) {
          await box.delete(key);
        }
      } catch (_) {
        // Biarkan di antrian untuk retry berikutnya
      }
    }
  }

  int get pendingCount {
    final box = Hive.box(boxName);
    return box.length;
  }
}
