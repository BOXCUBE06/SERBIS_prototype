import '../models/info_material.dart';
import 'material_cache.dart';

MaterialCache createMaterialCache({
  required Future<List<int>> Function(String url) download,
  Future<String> Function()? documentsDirectory,
}) =>
    const UnsupportedMaterialCache();

/// Web build. There is no application documents directory to write to and
/// `path_provider` has no web implementation, so nothing here pretends to save.
/// Callers check [isSupported] and hide the download affordance entirely.
class UnsupportedMaterialCache implements MaterialCache {
  const UnsupportedMaterialCache();

  @override
  bool get isSupported => false;

  @override
  Future<Map<int, CachedMaterial>> loadIndex() async =>
      <int, CachedMaterial>{};

  @override
  Future<CachedMaterial?> save(InfoMaterial material) async => null;

  @override
  Future<void> remove(int id) async {}
}
