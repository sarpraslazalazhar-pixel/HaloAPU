// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'notification_model.dart';

// **************************************************************************
// JsonSerializableGenerator
// **************************************************************************

_NotificationModel _$NotificationModelFromJson(Map<String, dynamic> json) =>
    _NotificationModel(
      id: json['id'] as String,
      title: json['title'] as String,
      body: json['body'] as String,
      type: $enumDecode(_$NotificationTypeEnumMap, json['type']),
      createdAt: DateTime.parse(json['createdAt'] as String),
      isRead: json['isRead'] as bool,
      ticketId: json['ticketId'] as String?,
    );

Map<String, dynamic> _$NotificationModelToJson(_NotificationModel instance) =>
    <String, dynamic>{
      'id': instance.id,
      'title': instance.title,
      'body': instance.body,
      'type': _$NotificationTypeEnumMap[instance.type]!,
      'createdAt': instance.createdAt.toIso8601String(),
      'isRead': instance.isRead,
      'ticketId': instance.ticketId,
    };

const _$NotificationTypeEnumMap = {
  NotificationType.ticket: 'ticket',
  NotificationType.reply: 'reply',
  NotificationType.solved: 'solved',
  NotificationType.revision: 'revision',
  NotificationType.rejected: 'rejected',
  NotificationType.csat: 'csat',
  NotificationType.sla: 'sla',
};
