// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'rating_model.dart';

// **************************************************************************
// JsonSerializableGenerator
// **************************************************************************

_RatingModel _$RatingModelFromJson(Map<String, dynamic> json) => _RatingModel(
  id: json['id'] as String,
  ticketId: json['ticketId'] as String,
  ticketTitle: json['ticketTitle'] as String,
  category: json['category'] as String,
  score: (json['score'] as num).toInt(),
  comment: json['comment'] as String?,
  createdAt: DateTime.parse(json['createdAt'] as String),
);

Map<String, dynamic> _$RatingModelToJson(_RatingModel instance) =>
    <String, dynamic>{
      'id': instance.id,
      'ticketId': instance.ticketId,
      'ticketTitle': instance.ticketTitle,
      'category': instance.category,
      'score': instance.score,
      'comment': instance.comment,
      'createdAt': instance.createdAt.toIso8601String(),
    };
