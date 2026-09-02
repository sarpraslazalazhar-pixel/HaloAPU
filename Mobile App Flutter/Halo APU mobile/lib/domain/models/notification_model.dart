import 'package:freezed_annotation/freezed_annotation.dart';

part 'notification_model.freezed.dart';
part 'notification_model.g.dart';

enum NotificationType {
  @JsonValue('ticket') ticket,
  @JsonValue('reply') reply,
  @JsonValue('solved') solved,
  @JsonValue('revision') revision,
  @JsonValue('rejected') rejected,
  @JsonValue('csat') csat,
  @JsonValue('sla') sla,
}

@freezed
sealed class NotificationModel with _$NotificationModel {
  const factory NotificationModel({
    required String id,
    required String title,
    required String body,
    required NotificationType type,
    required DateTime createdAt,
    required bool isRead,
    String? ticketId,
  }) = _NotificationModel;

  factory NotificationModel.fromJson(Map<String, dynamic> json) =>
      _$NotificationModelFromJson(json);
}
