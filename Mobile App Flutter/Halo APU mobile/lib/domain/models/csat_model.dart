class CsatModel {
  final String id;
  final String ticketId;
  final String ticketTitle;
  final String category;
  final int score;
  final String? comment;
  final String createdAt;

  CsatModel({
    required this.id,
    required this.ticketId,
    required this.ticketTitle,
    required this.category,
    required this.score,
    this.comment,
    required this.createdAt,
  });

  factory CsatModel.fromJson(Map<String, dynamic> json) {
    return CsatModel(
      id: json['id']?.toString() ?? '',
      ticketId: json['ticketId']?.toString() ?? '',
      ticketTitle: json['ticketTitle']?.toString() ?? '',
      category: json['category']?.toString() ?? '',
      score: json['score'] != null ? int.tryParse(json['score'].toString()) ?? 0 : 0,
      comment: json['comment']?.toString(),
      createdAt: json['createdAt']?.toString() ?? '',
    );
  }
}

class PendingCsatModel {
  final String id;
  final String ticketId;
  final String ticketTitle;
  final String category;
  final String completedAt;

  PendingCsatModel({
    required this.id,
    required this.ticketId,
    required this.ticketTitle,
    required this.category,
    required this.completedAt,
  });

  factory PendingCsatModel.fromJson(Map<String, dynamic> json) {
    return PendingCsatModel(
      id: json['id']?.toString() ?? '',
      ticketId: json['ticketId']?.toString() ?? '',
      ticketTitle: json['ticketTitle']?.toString() ?? '',
      category: json['category']?.toString() ?? '',
      completedAt: json['completedAt']?.toString() ?? '',
    );
  }
}
