import 'package:flutter/foundation.dart';

import '../models/info_material.dart';
import 'material_cache_unsupported.dart'
    if (dart.library.io) 'material_cache_io.dart';

/// One material stored on this device, plus the metadata needed to list it
/// while the network is unreachable.
@immutable
class CachedMaterial {
  final int id;
  final String title;
  final String fileType;
  final int sizeBytes;
  final String path;
  final DateTime savedAt;

  /// The material's publication date, carried into the index so an offline
  /// Home screen dates its announcements from when MDRRMO published them and
  /// not from when this device happened to download them.
  final DateTime? publishedAt;

  /// Carried so a saved material keeps its badge with the network down.
  /// Absent from indexes written before it existed, which reads as false.
  final bool verified;

  /// Who verified it, kept for the same reason as [verified]. Absent from
  /// indexes written before they were carried, which reads as unnamed.
  final String? verifiedByName;
  final String? verifiedByRole;

  const CachedMaterial({
    required this.id,
    required this.title,
    required this.fileType,
    required this.sizeBytes,
    required this.path,
    required this.savedAt,
    this.publishedAt,
    this.verified = false,
    this.verifiedByName,
    this.verifiedByRole,
  });

  Map<String, dynamic> toJson() => <String, dynamic>{
        'id': id,
        'title': title,
        'file_type': fileType,
        'size': sizeBytes,
        'path': path,
        'saved_at': savedAt.toIso8601String(),
        if (publishedAt != null) 'published_at': publishedAt!.toIso8601String(),
        'verified': verified,
        if (verifiedByName != null) 'verified_by_name': verifiedByName,
        if (verifiedByRole != null) 'verified_by_role': verifiedByRole,
      };

  static CachedMaterial? fromJson(Object? json) {
    if (json is! Map) return null;
    final id = json['id'];
    final path = json['path'];
    if (id is! int || path is! String || path.isEmpty) return null;

    return CachedMaterial(
      id: id,
      title: json['title'] as String? ?? 'Untitled',
      fileType: json['file_type'] as String? ?? '',
      sizeBytes: json['size'] as int? ?? 0,
      path: path,
      savedAt:
          DateTime.tryParse(json['saved_at'] as String? ?? '') ?? DateTime(1970),
      // Absent from indexes written before publication dates were carried, so
      // an upgrade must not drop those entries — it just has no date to show.
      publishedAt: DateTime.tryParse(json['published_at'] as String? ?? ''),
      verified: json['verified'] == true,
      verifiedByName: json['verified_by_name'] as String?,
      verifiedByRole: json['verified_by_role'] as String?,
    );
  }

  /// What the Library renders when the server cannot be reached: the saved copy
  /// is real content, so it must survive a dead network with its title intact.
  InfoMaterial toMaterial() => InfoMaterial(
        id: id,
        title: title,
        fileType: fileType,
        sizeBytes: sizeBytes,
        url: '',
        publishedAt: publishedAt,
        verified: verified,
        verifiedByName: verifiedByName,
        verifiedByRole: verifiedByRole,
      );
}

/// Downloads published materials to the device and keeps an index of what is
/// actually on disk.
///
/// The index is the source of truth for the "Saved" state. Before this existed
/// the pill flipped a local bool after a 700 ms delay and wrote nothing, so
/// offline access — scope item #4 — was an animation.
///
/// [createMaterialCache] resolves to the `dart:io` implementation on device and
/// to a no-op on web, which has no application documents directory. The import
/// is conditional because importing `dart:io` at all breaks the web build.
abstract class MaterialCache {
  /// [download] is injected so the cache can be tested with a canned response,
  /// no network and no plugins. [documentsDirectory] likewise points at a temp
  /// directory under test; it is ignored on web.
  factory MaterialCache({
    required Future<List<int>> Function(String url) download,
    Future<String> Function()? documentsDirectory,
  }) =>
      createMaterialCache(
        download: download,
        documentsDirectory: documentsDirectory,
      );

  /// False on web: `path_provider` has no web implementation and a browser has
  /// no directory to write to. The Library still lists materials there, it just
  /// does not offer to save them — the honest state, rather than a button that
  /// always fails.
  bool get isSupported;

  /// Entries whose file is still present. An entry whose file has gone — an OS
  /// cache wipe, a "clear storage", a restore that skipped app data — is dropped
  /// and the pruned index written back, so "Saved" can never point at nothing.
  Future<Map<int, CachedMaterial>> loadIndex();

  /// Downloads [material] and records it. Returns null when the download or the
  /// write failed; the caller must not claim the material is saved on a null.
  Future<CachedMaterial?> save(InfoMaterial material);

  Future<void> remove(int id);
}
