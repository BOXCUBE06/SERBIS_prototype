import 'dart:convert';
import 'dart:io';

import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../models/info_material.dart';
import 'app_log.dart';
import 'material_cache.dart';

MaterialCache createMaterialCache({
  required Future<List<int>> Function(String url) download,
  Future<String> Function()? documentsDirectory,
}) =>
    IoMaterialCache(
      download: download,
      documentsDirectory: documentsDirectory ??
          () async => (await getApplicationDocumentsDirectory()).path,
    );

/// Device implementation of [MaterialCache]. Files live in
/// `<app documents>/materials/<id>.<ext>`; the index lives in
/// `SharedPreferences` under one JSON key.
class IoMaterialCache implements MaterialCache {
  static const String _logArea = 'materials';
  static const String _indexKey = 'serbis_materials_index_v1';
  static const String _folder = 'materials';

  final Future<List<int>> Function(String url) _download;
  final Future<String> Function() _documentsDirectory;

  IoMaterialCache({
    required Future<List<int>> Function(String url) download,
    required Future<String> Function() documentsDirectory,
  })  : _download = download,
        _documentsDirectory = documentsDirectory;

  @override
  bool get isSupported => true;

  @override
  Future<Map<int, CachedMaterial>> loadIndex() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_indexKey);
    if (raw == null || raw.isEmpty) return <int, CachedMaterial>{};

    List<dynamic> decoded;
    try {
      final value = jsonDecode(raw);
      decoded = value is List ? value : <dynamic>[];
    } catch (_) {
      // The exception is not passed on: a FormatException carries the text it
      // could not parse, which here is the index itself.
      AppLog.error(_logArea, 'read offline index',
          reason: 'unreadable, discarded (${raw.length} chars)');
      await prefs.remove(_indexKey);
      return <int, CachedMaterial>{};
    }

    final index = <int, CachedMaterial>{};
    var pruned = false;

    for (final item in decoded) {
      final entry = CachedMaterial.fromJson(item);
      if (entry == null) {
        pruned = true;
        continue;
      }

      if (await File(entry.path).exists()) {
        index[entry.id] = entry;
      } else {
        // The file is gone but the index still claimed it. Saying "Saved" over
        // nothing is exactly the failure this whole task exists to remove.
        pruned = true;
      }
    }

    if (pruned) {
      // Files vanish for reasons outside the app — a storage cleaner, an OS
      // clearing app data under pressure. Recording it is what separates "the
      // download never worked" from "it worked and something removed it",
      // which are different bugs with different owners.
      AppLog.warn(_logArea, 'read offline index',
          reason: 'pruned entries with no file, ${index.length} remain');
      await _writeIndex(prefs, index);
    }

    return index;
  }

  @override
  Future<CachedMaterial?> save(InfoMaterial material) async {
    if (material.url.isEmpty) return null;

    final List<int> bytes;
    try {
      bytes = await _download(material.url);
    } catch (_) {
      // Already logged with its status by ApiService.downloadFile. Only the
      // outcome is added here: the caller returns false and the pill stays
      // un-saved, which is the behaviour a resident would report.
      AppLog.warn(_logArea, 'save material ${material.id}',
          reason: 'download failed, nothing written');
      return null;
    }

    if (bytes.isEmpty) {
      AppLog.warn(_logArea, 'save material ${material.id}',
          reason: 'server returned an empty file');
      return null;
    }

    try {
      final directory = Directory(
        '${await _documentsDirectory()}${Platform.pathSeparator}$_folder',
      );
      await directory.create(recursive: true);

      final file = File(
        '${directory.path}${Platform.pathSeparator}${_fileNameFor(material)}',
      );

      // Write to a sibling and rename: a download killed halfway would
      // otherwise leave a truncated file that the index swears is complete.
      final partial = File('${file.path}.part');
      await partial.writeAsBytes(bytes, flush: true);
      await partial.rename(file.path);

      final entry = CachedMaterial(
        id: material.id,
        title: material.title,
        fileType: material.fileType,
        sizeBytes: bytes.length,
        path: file.path,
        savedAt: DateTime.now(),
        publishedAt: material.publishedAt,
      );

      final index = await loadIndex();
      index[entry.id] = entry;
      await _writeIndex(await SharedPreferences.getInstance(), index);

      return entry;
    } catch (error) {
      // A full disk lands here, and so does a documents directory the platform
      // will not hand over. Both present to the resident as a Download button
      // that does nothing.
      AppLog.error(_logArea, 'save material ${material.id}', error: error,
          reason: 'write failed, ${bytes.length} bytes discarded');
      return null;
    }
  }

  @override
  Future<void> remove(int id) async {
    final index = await loadIndex();
    final entry = index.remove(id);
    if (entry == null) return;

    try {
      final file = File(entry.path);
      if (await file.exists()) {
        await file.delete();
      }
    } catch (error) {
      // The index entry goes either way: a file we cannot delete must not keep
      // claiming to be saved. That leaves the bytes on disk with nothing
      // pointing at them, so the space is unaccounted for — worth a line, since
      // the Profile screen reports storage used from the index.
      AppLog.error(_logArea, 'remove material $id', error: error,
          reason: 'index entry dropped, file may remain');
    }

    await _writeIndex(await SharedPreferences.getInstance(), index);
  }

  Future<void> _writeIndex(
    SharedPreferences prefs,
    Map<int, CachedMaterial> index,
  ) async {
    final entries = index.values.map((entry) => entry.toJson()).toList();
    await prefs.setString(_indexKey, jsonEncode(entries));
  }

  /// Keyed on the id, so re-saving overwrites the material's own copy instead of
  /// accumulating one file per download. The title is deliberately not in the
  /// name: it is editable in the admin panel, and a rename would orphan the old
  /// file while the index kept pointing at it.
  String _fileNameFor(InfoMaterial material) {
    final extension = material.fileType.replaceAll(RegExp('[^a-z0-9]'), '');
    return extension.isEmpty ? '${material.id}' : '${material.id}.$extension';
  }
}
