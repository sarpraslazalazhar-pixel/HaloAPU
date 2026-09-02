import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:hive/hive.dart';

/// Layanan caching untuk menyimpan dan mengelola cache respons API lokal menggunakan Hive.
class CacheService {
  /// Nama Hive box untuk cache API
  static const String boxName = 'api_cache';

  /// Membuka dan menginisialisasi Hive box untuk cache API.
  static Future<void> init() async {
    try {
      if (!Hive.isBoxOpen(boxName)) {
        await Hive.openBox(boxName);
      }
    } catch (e) {
      debugPrint('CacheService init error: $e');
    }
  }

  /// Mendapatkan instance box Hive yang sudah terbuka.
  static Future<Box> _getBox() async {
    if (Hive.isBoxOpen(boxName)) {
      return Hive.box(boxName);
    }
    return await Hive.openBox(boxName);
  }

  /// Menyimpan data respons ke dalam cache dengan batas waktu TTL (Time To Live).
  ///
  /// [key] adalah kunci unik untuk entri cache.
  /// [data] adalah data respons yang akan di-encode ke format JSON string.
  /// [ttlSeconds] adalah masa berlaku cache dalam satuan detik (default: 300 detik / 5 menit).
  static Future<void> put(
    String key,
    dynamic data, {
    int ttlSeconds = 300,
  }) async {
    try {
      final box = await _getBox();
      final entry = <String, dynamic>{
        'data': jsonEncode(data),
        'timestamp': DateTime.now().millisecondsSinceEpoch,
        'ttl': ttlSeconds,
      };
      await box.put(key, entry);
    } catch (e) {
      debugPrint('CacheService put error for key "$key": $e');
    }
  }

  /// Mengambil data dari cache jika ada dan belum kedaluwarsa.
  ///
  /// Mengembalikan `null` jika data tidak ditemukan, rusak, atau sudah kedaluwarsa.
  static dynamic get(String key) {
    try {
      if (!Hive.isBoxOpen(boxName)) return null;
      final box = Hive.box(boxName);
      final raw = box.get(key);
      if (raw == null) return null;

      final entry = _parseEntry(raw);
      if (entry == null) return null;

      final int timestamp = entry['timestamp'] as int? ?? 0;
      final int ttl = entry['ttl'] as int? ?? 0;
      final int now = DateTime.now().millisecondsSinceEpoch;

      if (now - timestamp > ttl * 1000) {
        return null;
      }

      return _decodeData(entry['data']);
    } catch (e) {
      debugPrint('CacheService get error for key "$key": $e');
      return null;
    }
  }

  /// Mengambil data dari cache meskipun sudah kedaluwarsa (berguna untuk offline fallback).
  ///
  /// Mengembalikan `null` hanya jika data tidak ditemukan atau rusak.
  static dynamic getStale(String key) {
    try {
      if (!Hive.isBoxOpen(boxName)) return null;
      final box = Hive.box(boxName);
      final raw = box.get(key);
      if (raw == null) return null;

      final entry = _parseEntry(raw);
      if (entry == null) return null;

      return _decodeData(entry['data']);
    } catch (e) {
      debugPrint('CacheService getStale error for key "$key": $e');
      return null;
    }
  }

  /// Menghapus entri cache spesifik berdasarkan [key].
  static Future<void> remove(String key) async {
    try {
      final box = await _getBox();
      await box.delete(key);
    } catch (e) {
      debugPrint('CacheService remove error for key "$key": $e');
    }
  }

  /// Menghapus semua entri cache yang memiliki key dengan awalan [prefix].
  ///
  /// Sangat berguna untuk invalidasi semua cache terkait (misal: semua tiket).
  static Future<void> removeByPrefix(String prefix) async {
    try {
      final box = await _getBox();
      final keysToRemove = box.keys
          .where((k) => k.toString().startsWith(prefix))
          .toList();
      if (keysToRemove.isNotEmpty) {
        await box.deleteAll(keysToRemove);
      }
    } catch (e) {
      debugPrint('CacheService removeByPrefix error for prefix "$prefix": $e');
    }
  }

  /// Membersihkan dan menghapus semua entri cache yang sudah kedaluwarsa dari storage.
  static Future<void> cleanup() async {
    try {
      final box = await _getBox();
      final now = DateTime.now().millisecondsSinceEpoch;
      final keysToDelete = <dynamic>[];

      for (var key in box.keys) {
        final raw = box.get(key);
        if (raw == null) {
          keysToDelete.add(key);
          continue;
        }
        final entry = _parseEntry(raw);
        if (entry == null) {
          keysToDelete.add(key);
          continue;
        }
        final int timestamp = entry['timestamp'] as int? ?? 0;
        final int ttl = entry['ttl'] as int? ?? 0;
        if (now - timestamp > ttl * 1000) {
          keysToDelete.add(key);
        }
      }

      if (keysToDelete.isNotEmpty) {
        await box.deleteAll(keysToDelete);
      }
    } catch (e) {
      debugPrint('CacheService cleanup error: $e');
    }
  }

  /// Mengosongkan seluruh isi kotak cache.
  static Future<void> clear() async {
    try {
      final box = await _getBox();
      await box.clear();
    } catch (e) {
      debugPrint('CacheService clear error: $e');
    }
  }

  /// Menghasilkan cache key untuk query daftar tiket berdasarkan filter.
  static String ticketListKey({String? status, String? search, int page = 1}) {
    final cleanStatus = status ?? 'all';
    final cleanSearch = search?.trim() ?? '';
    return 'ticket_list_status_${cleanStatus}_search_${cleanSearch}_page_$page';
  }

  /// Menghasilkan cache key untuk detail tiket berdasarkan [id].
  static String ticketDetailKey(String id) => 'ticket_detail_$id';

  /// Menghasilkan cache key untuk katalog layanan.
  static String servicesKey() => 'services_catalog';

  /// Menghasilkan cache key untuk dynamic form fields berdasarkan [subUnitId].
  static String formFieldsKey(int subUnitId) => 'form_fields_$subUnitId';

  /// Helper untuk memparsing entri cache menjadi `Map<String, dynamic>`.
  static Map<String, dynamic>? _parseEntry(dynamic raw) {
    if (raw is Map) {
      return Map<String, dynamic>.from(raw);
    } else if (raw is String) {
      try {
        final decoded = jsonDecode(raw);
        if (decoded is Map) {
          return Map<String, dynamic>.from(decoded);
        }
      } catch (_) {}
    }
    return null;
  }

  /// Helper untuk men-decode JSON data string menjadi objek aslinya.
  static dynamic _decodeData(dynamic rawData) {
    if (rawData is String) {
      try {
        return jsonDecode(rawData);
      } catch (_) {
        return rawData;
      }
    }
    return rawData;
  }
}
