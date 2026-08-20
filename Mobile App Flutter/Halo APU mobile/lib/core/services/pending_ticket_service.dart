import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:image_picker/image_picker.dart';
import 'package:halo_apu_mobile/core/services/ticket_service.dart';
import 'package:halo_apu_mobile/core/services/push_notification_service.dart';

class PendingTicketService {
  static const String boxName = 'pending_tickets';
  static bool _isProcessing = false;

  static Future<void> init() async {
    await Hive.openBox(boxName);
  }

  /// Memasukkan tiket ke antrian lokal saat offline
  Future<void> enqueue(Map<String, dynamic> ticketPayload) async {
    final box = Hive.box(boxName);
    final id = DateTime.now().millisecondsSinceEpoch.toString();
    await box.put(id, ticketPayload);
    debugPrint('Offline ticket enqueued with ID: $id. Total queue: ${box.length}');
  }

  /// Memproses semua tiket dalam antrian dan mengirimkan ke server
  Future<int> processQueue() async {
    if (_isProcessing) return 0;
    _isProcessing = true;

    int syncedCount = 0;
    try {
      final box = Hive.box(boxName);
      if (box.isEmpty) {
        _isProcessing = false;
        return 0;
      }

      final keys = box.keys.toList();
      final ticketService = TicketService();

      for (var key in keys) {
        final rawData = box.get(key);
        if (rawData == null) continue;

        final Map<String, dynamic> payload = Map<String, dynamic>.from(rawData as Map);
        final int subUnitId = int.tryParse(payload['sub_unit_id']?.toString() ?? '') ?? 0;
        if (subUnitId == 0) {
          // Payload tidak valid, hapus dari antrian
          await box.delete(key);
          continue;
        }

        final Map<String, dynamic> formData = payload['form_data'] != null
            ? Map<String, dynamic>.from(payload['form_data'] as Map)
            : {};
        final String priority = payload['priority']?.toString() ?? 'normal';

        // Rekonstruksi lampiran file dari path lokal perangkat
        final Map<String, List<XFile>> attachments = {};
        if (payload['attachment_files'] != null) {
          final rawAttachments = Map<String, dynamic>.from(payload['attachment_files'] as Map);
          rawAttachments.forEach((fieldKey, filePaths) {
            if (filePaths is List) {
              final List<XFile> validFiles = [];
              for (var p in filePaths) {
                final pathStr = p.toString();
                if (pathStr.isNotEmpty && File(pathStr).existsSync()) {
                  validFiles.add(XFile(pathStr));
                }
              }
              if (validFiles.isNotEmpty) {
                attachments[fieldKey] = validFiles;
              }
            }
          });
        }

        debugPrint('Syncing offline ticket key $key to server...');
        final result = await ticketService.createTicket(
          subUnitId: subUnitId,
          formData: formData,
          priority: priority,
          attachments: attachments.isNotEmpty ? attachments : null,
        );

        if (result['success'] == true) {
          await box.delete(key);
          syncedCount++;
          debugPrint('Successfully synced offline ticket key $key to server');

          // Munculkan notifikasi banner keberhasilan
          final serviceName = payload['sub_unit_name'] ?? 'Layanan';
          await PushNotificationService.showNotificationAlert(
            title: 'Tiket Berhasil Terkirim',
            body: 'Tiket "$serviceName" yang dibuat saat offline telah berhasil terkirim ke server.',
            data: {'id': result['data']?['id']},
          );
        } else {
          debugPrint('Failed to sync ticket key $key: ${result['message']}');
          // Jika ditolak karena validasi server (bukan error jaringan), hapus agar tidak stuck
          final msg = result['message']?.toString().toLowerCase() ?? '';
          if (msg.contains('tidak valid') || msg.contains('unauthenticated')) {
            await box.delete(key);
          }
        }
      }
    } catch (e) {
      debugPrint('Error processing pending ticket queue: $e');
    } finally {
      _isProcessing = false;
    }

    return syncedCount;
  }

  int get pendingCount {
    if (!Hive.isBoxOpen(boxName)) return 0;
    final box = Hive.box(boxName);
    return box.length;
  }
}
