class MonitorAssetModel {
  final String namaAset;
  final String tipe;
  final String status;
  final String? user;
  final String? waktu;
  final int? bookingId;

  MonitorAssetModel({
    required this.namaAset,
    required this.tipe,
    required this.status,
    this.user,
    this.waktu,
    this.bookingId,
  });

  factory MonitorAssetModel.fromJson(Map<String, dynamic> json) {
    return MonitorAssetModel(
      namaAset: json['nama_aset'] ?? '',
      tipe: json['tipe'] ?? '',
      status: json['status'] ?? 'Tersedia',
      user: json['user'],
      waktu: json['waktu'],
      bookingId: json['booking_id'],
    );
  }
}
