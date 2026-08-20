import 'dart:convert';
import 'package:freezed_annotation/freezed_annotation.dart';

part 'ticket_model.freezed.dart';
part 'ticket_model.g.dart';

enum TicketStatus {
  @JsonValue('open') open,
  @JsonValue('on_proses') processing,
  @JsonValue('solve') solved,
  @JsonValue('reject') rejected,
  @JsonValue('dibatalkan') cancelled,
  @JsonValue('pending') pending,
  @JsonValue('need_revision') needRevision,
}

@freezed
sealed class TicketModel with _$TicketModel {
  const factory TicketModel({
    required String id,
    required String title,
    required String description,
    required String category,
    required TicketStatus status,
    required DateTime createdAt,
    required String requesterName,
    String? assignedTo,
    String? attachmentUrl,
    List<TicketReply>? logs,
    List<Map<String, dynamic>>? operators,
    Map<String, dynamic>? csat,
  }) = _TicketModel;

  factory TicketModel.fromJson(Map<String, dynamic> json) => _$TicketModelFromJson(json);

  static TicketModel safeFromJson(Map<String, dynamic> json) {
    final map = Map<String, dynamic>.from(json);
    map['description'] ??= '';
    map['requesterName'] ??= 'Pengguna';
    map['category'] ??= 'Umum';

    final rawStatus = map['status']?.toString().toLowerCase() ?? 'open';
    switch (rawStatus) {
      case 'on_proses':
      case 'processing':
      case 'assigned':
        map['status'] = 'on_proses';
        break;
      case 'solve':
      case 'solved':
      case 'selesai':
        map['status'] = 'solve';
        break;
      case 'reject':
      case 'rejected':
        map['status'] = 'reject';
        break;
      case 'dibatalkan':
      case 'cancelled':
        map['status'] = 'dibatalkan';
        break;
      case 'need_revision':
        map['status'] = 'need_revision';
        break;
      case 'pending':
        map['status'] = 'pending';
        break;
      default:
        map['status'] = 'open';
    }
    if (map['operators'] is List) {
      map['operators'] = (map['operators'] as List)
          .map((e) => e is Map ? Map<String, dynamic>.from(e) : null)
          .whereType<Map<String, dynamic>>()
          .toList();
    }
    return TicketModel.fromJson(map);
  }
}

@freezed
sealed class TicketReply with _$TicketReply {
  const factory TicketReply({
    required int id,
    required String action,
    required String note,
    required DateTime createdAt,
    String? adminName,
    required bool isFromAdmin,
    List<Map<String, dynamic>>? attachments,
  }) = _TicketReply;

  factory TicketReply.fromJson(Map<String, dynamic> json) => _$TicketReplyFromJson(json);
}

extension TicketModelX on TicketModel {
  String get statusIndonesianLabel {
    switch (status) {
      case TicketStatus.open:
        return 'Menunggu';
      case TicketStatus.processing:
        return 'Diproses';
      case TicketStatus.solved:
        return 'Selesai';
      case TicketStatus.rejected:
        return 'Ditolak';
      case TicketStatus.needRevision:
        return 'Perlu Revisi';
      case TicketStatus.cancelled:
        return 'Dibatalkan';
      case TicketStatus.pending:
        return 'Tertunda';
    }
  }

  Map<String, dynamic>? get parsedFormData {
    if (description.isEmpty) return null;
    try {
      final decoded = jsonDecode(description);
      if (decoded is Map<String, dynamic>) return decoded;
      if (decoded is Map) return Map<String, dynamic>.from(decoded);
      return null;
    } catch (_) {
      return null;
    }
  }

  /// Format ringkasan form data cerdas untuk kartu tiket
  List<MapEntry<String, String>> get summaryItems {
    final map = parsedFormData;
    if (map == null || map.isEmpty) return [];

    final List<MapEntry<String, String>> items = [];

    map.forEach((rawKey, value) {
      if (value == null) return;
      final valStr = value.toString().trim();
      if (valStr.isEmpty) return;

      // Jangan ulangi field jika nilainya persis sama dengan judul tiket
      if (valStr.toLowerCase() == title.toLowerCase()) return;

      // Jika key murni angka (field ID database seperti '57', '58'), kosongkan agar tidak muncul angka aneh
      String cleanKey = '';
      if (int.tryParse(rawKey.trim()) == null) {
        cleanKey = rawKey
            .replaceAll('_', ' ')
            .split(' ')
            .map((w) => w.isNotEmpty ? '${w[0].toUpperCase()}${w.substring(1)}' : '')
            .join(' ');
      }

      items.add(MapEntry(cleanKey, valStr));
    });

    return items;
  }

  String get formattedDescription {
    if (description.isEmpty) return '-';
    final items = summaryItems;
    if (items.isNotEmpty) {
      return items.map((e) => e.key.isNotEmpty ? '${e.key}: ${e.value}' : e.value).join(' • ');
    }
    return description;
  }
}
