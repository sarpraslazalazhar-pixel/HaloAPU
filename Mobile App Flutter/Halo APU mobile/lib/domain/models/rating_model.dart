import 'package:freezed_annotation/freezed_annotation.dart';

part 'rating_model.freezed.dart';
part 'rating_model.g.dart';

@freezed
sealed class RatingModel with _$RatingModel {
  const factory RatingModel({
    required String id,
    required String ticketId,
    required String ticketTitle,
    required String category,
    required int score,
    String? comment,
    required DateTime createdAt,
  }) = _RatingModel;

  factory RatingModel.fromJson(Map<String, dynamic> json) => _$RatingModelFromJson(json);
}
