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
  String get formattedDescription {
    if (description.isEmpty) return '-';
    try {
      final Map<String, dynamic> decoded = jsonDecode(description);
      final List<String> parts = [];
      decoded.forEach((key, value) {
        if (value != null && value.toString().isNotEmpty) {
          parts.add('$value');
        }
      });
      return parts.join(' - ');
    } catch (e) {
      // If it's not valid JSON, just return the raw string
      return description;
    }
  }
}
