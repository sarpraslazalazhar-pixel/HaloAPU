// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'ticket_model.dart';

// **************************************************************************
// JsonSerializableGenerator
// **************************************************************************

_TicketModel _$TicketModelFromJson(Map<String, dynamic> json) => _TicketModel(
  id: json['id'] as String,
  title: json['title'] as String,
  description: json['description'] as String,
  category: json['category'] as String,
  status: $enumDecode(_$TicketStatusEnumMap, json['status']),
  createdAt: DateTime.parse(json['createdAt'] as String),
  requesterName: json['requesterName'] as String,
  assignedTo: json['assignedTo'] as String?,
  attachmentUrl: json['attachmentUrl'] as String?,
  logs: (json['logs'] as List<dynamic>?)
      ?.map((e) => TicketReply.fromJson(e as Map<String, dynamic>))
      .toList(),
  operators: (json['operators'] as List<dynamic>?)
      ?.map((e) => e as Map<String, dynamic>)
      .toList(),
  csat: json['csat'] as Map<String, dynamic>?,
);

Map<String, dynamic> _$TicketModelToJson(_TicketModel instance) =>
    <String, dynamic>{
      'id': instance.id,
      'title': instance.title,
      'description': instance.description,
      'category': instance.category,
      'status': _$TicketStatusEnumMap[instance.status]!,
      'createdAt': instance.createdAt.toIso8601String(),
      'requesterName': instance.requesterName,
      'assignedTo': instance.assignedTo,
      'attachmentUrl': instance.attachmentUrl,
      'logs': instance.logs,
      'operators': instance.operators,
      'csat': instance.csat,
    };

const _$TicketStatusEnumMap = {
  TicketStatus.open: 'open',
  TicketStatus.processing: 'on_proses',
  TicketStatus.solved: 'solve',
  TicketStatus.rejected: 'reject',
  TicketStatus.cancelled: 'dibatalkan',
  TicketStatus.pending: 'pending',
  TicketStatus.needRevision: 'need_revision',
};

_TicketReply _$TicketReplyFromJson(Map<String, dynamic> json) => _TicketReply(
  id: (json['id'] as num).toInt(),
  action: json['action'] as String,
  note: json['note'] as String,
  createdAt: DateTime.parse(json['createdAt'] as String),
  adminName: json['adminName'] as String?,
  isFromAdmin: json['isFromAdmin'] as bool,
  attachments: (json['attachments'] as List<dynamic>?)
      ?.map((e) => e as Map<String, dynamic>)
      .toList(),
);

Map<String, dynamic> _$TicketReplyToJson(_TicketReply instance) =>
    <String, dynamic>{
      'id': instance.id,
      'action': instance.action,
      'note': instance.note,
      'createdAt': instance.createdAt.toIso8601String(),
      'adminName': instance.adminName,
      'isFromAdmin': instance.isFromAdmin,
      'attachments': instance.attachments,
    };
