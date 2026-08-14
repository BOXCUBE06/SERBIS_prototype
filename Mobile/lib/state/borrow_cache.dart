import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/borrow_models.dart';
import 'app_log.dart';

/// What was on the borrow requests list the last time the server answered.
class CachedBorrowRequests {
  final List<BorrowRequest> requests;

  /// When the list was fetched, not when it was written.
  final DateTime fetchedAt;

  const CachedBorrowRequests({required this.requests, required this.fetchedAt});
}

/// Persists the resident's borrow requests so an offline launch shows what
/// MDRRMO last said instead of an empty list. Mirrors `RequestCache` exactly,
/// including the reasons behind each choice — see that file for the long
/// version. Kept as a separate store rather than folded into it: the two lists
/// are unrelated records with different shapes, and a shared key would mean a
/// version that reads one has to be able to read both.
class BorrowCache {
  static const _logArea = 'cache';
  static const _rowsKey = 'serbis.borrowings.cache.v1';
  static const _fetchedAtKey = 'serbis.borrowings.cache.fetched_at.v1';

  final Future<SharedPreferences> Function() _prefs;

  BorrowCache({Future<SharedPreferences> Function()? preferences})
      : _prefs = preferences ?? SharedPreferences.getInstance;

  Future<void> save(List<BorrowRequest> requests, DateTime fetchedAt) async {
    // Rows with no server id were never filed.
    final rows = requests
        .where((request) => request.id != null)
        .map((request) => request.toCacheJson())
        .toList();

    try {
      final prefs = await _prefs();
      await prefs.setString(_rowsKey, jsonEncode(rows));
      await prefs.setString(_fetchedAtKey, fetchedAt.toIso8601String());
    } catch (error) {
      AppLog.error(_logArea, 'write borrowing cache', error: error,
          reason: '${rows.length} rows not persisted');
    }
  }

  Future<CachedBorrowRequests?> load() async {
    try {
      final prefs = await _prefs();
      final raw = prefs.getString(_rowsKey);
      if (raw == null || raw.isEmpty) return null;

      final decoded = jsonDecode(raw);
      if (decoded is! List) return null;

      final requests = decoded
          .map(BorrowRequestCache.fromCacheJson)
          .whereType<BorrowRequest>()
          .toList();
      if (requests.isEmpty) return null;

      final fetchedAt = DateTime.tryParse(prefs.getString(_fetchedAtKey) ?? '');
      if (fetchedAt == null) return null;

      return CachedBorrowRequests(requests: requests, fetchedAt: fetchedAt);
    } catch (error) {
      AppLog.error(_logArea, 'read borrowing cache', error: error,
          reason: 'treated as empty');
      return null;
    }
  }

  Future<void> clear() async {
    try {
      final prefs = await _prefs();
      await prefs.remove(_rowsKey);
      await prefs.remove(_fetchedAtKey);
    } catch (error) {
      AppLog.error(_logArea, 'clear borrowing cache', error: error,
          reason: 'previous rows may remain on device');
    }
  }
}
