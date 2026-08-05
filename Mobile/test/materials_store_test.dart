// AppState's half of the offline story: what the Library renders when the
// server answers, and what it renders when it does not.

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/info_material.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/material_cache.dart';
import 'package:serbis/state/request_store.dart';

class _FakeApi extends ApiService {
  _FakeApi({this.response, this.error});

  final List<Map<String, dynamic>>? response;
  final Object? error;
  int calls = 0;

  @override
  Future<List<Map<String, dynamic>>> getInfoMaterials() async {
    calls++;
    if (error != null) {
      throw error!;
    }
    return response ?? <Map<String, dynamic>>[];
  }
}

class _FakeCache implements MaterialCache {
  _FakeCache({
    Map<int, CachedMaterial>? index,
    this.saveResult,
    this.saveDelay = Duration.zero,
  }) : index = index ?? <int, CachedMaterial>{};

  Map<int, CachedMaterial> index;
  CachedMaterial? saveResult;
  Duration saveDelay;
  int saveCalls = 0;

  @override
  bool get isSupported => true;

  @override
  Future<Map<int, CachedMaterial>> loadIndex() async => index;

  @override
  Future<CachedMaterial?> save(InfoMaterial material) async {
    saveCalls++;
    if (saveDelay > Duration.zero) {
      await Future<void>.delayed(saveDelay);
    }
    return saveResult;
  }

  @override
  Future<void> remove(int id) async {
    index = <int, CachedMaterial>{...index}..remove(id);
  }
}

CachedMaterial _cached(int id, {String title = 'Saved doc'}) => CachedMaterial(
      id: id,
      title: title,
      fileType: 'pdf',
      sizeBytes: 100,
      path: '/tmp/$id.pdf',
      savedAt: DateTime(2026, 7, 28),
    );

const Map<String, dynamic> _serverRow = <String, dynamic>{
  'files_id': 1,
  'title': 'Evacuation Center Map',
  'file_type': 'png',
  'file_size': 116,
  'full_url': 'http://example.test/storage/info_materials/map.png',
};

void main() {
  test('loadMaterials renders the server list', () async {
    final api = _FakeApi(response: <Map<String, dynamic>>[_serverRow]);
    final state = AppState(api, materialCache: _FakeCache());

    await state.loadMaterials();

    expect(state.materials.single.title, 'Evacuation Center Map');
    expect(state.materialsFromCache, isFalse);
    expect(state.materialsError, isNull);
  });

  test('a dead network falls back to the saved copies, flagged as such',
      () async {
    final api = _FakeApi(error: const ApiException('Cannot connect to server.'));
    final state = AppState(
      api,
      materialCache: _FakeCache(
        index: <int, CachedMaterial>{4: _cached(4, title: 'Flood Checklist')},
      ),
    );

    await state.loadMaterials();

    expect(state.materials.single.title, 'Flood Checklist');
    expect(state.materialsFromCache, isTrue);
    expect(state.materialsError, 'Cannot connect to server.');
    expect(state.isSavedOffline(4), isTrue);
  });

  test('a dead network with nothing saved is an empty list, not a cache claim',
      () async {
    final api = _FakeApi(error: const ApiException('Cannot connect to server.'));
    final state = AppState(api, materialCache: _FakeCache());

    await state.loadMaterials();

    expect(state.materials, isEmpty);
    expect(state.materialsFromCache, isFalse);
    expect(state.materialsError, isNotNull);
  });

  test('saveMaterialOffline reports failure instead of claiming success',
      () async {
    final cache = _FakeCache(saveResult: null);
    final state = AppState(_FakeApi(), materialCache: cache);

    final saved = await state.saveMaterialOffline(
      InfoMaterial.fromJson(_serverRow),
    );

    expect(saved, isFalse);
    expect(state.isSavedOffline(1), isFalse);
  });

  test('a successful save marks the material offline', () async {
    final cache = _FakeCache(saveResult: _cached(1));
    final state = AppState(_FakeApi(), materialCache: cache);

    final saved = await state.saveMaterialOffline(
      InfoMaterial.fromJson(_serverRow),
    );

    expect(saved, isTrue);
    expect(state.isSavedOffline(1), isTrue);
    expect(state.isSavingOffline(1), isFalse);
  });

  test('a second tap during a download does not start a second one', () async {
    final cache = _FakeCache(
      saveResult: _cached(1),
      saveDelay: const Duration(milliseconds: 60),
    );
    final state = AppState(_FakeApi(), materialCache: cache);
    final material = InfoMaterial.fromJson(_serverRow);

    final first = state.saveMaterialOffline(material);
    final second = await state.saveMaterialOffline(material);

    expect(second, isFalse);
    expect(await first, isTrue);
    expect(cache.saveCalls, 1);
  });

  test('removing a saved copy clears it from the index', () async {
    final cache = _FakeCache(
      index: <int, CachedMaterial>{1: _cached(1)},
    );
    final state = AppState(
      _FakeApi(response: <Map<String, dynamic>>[_serverRow]),
      materialCache: cache,
    );
    await state.loadMaterials();

    await state.removeMaterialOffline(1);

    expect(state.isSavedOffline(1), isFalse);
    // The server row itself stays: it is still published, just not downloaded.
    expect(state.materials.single.id, 1);
  });

  test('removing the last saved copy while offline drops the row too',
      () async {
    final cache = _FakeCache(
      index: <int, CachedMaterial>{1: _cached(1)},
    );
    final state = AppState(
      _FakeApi(error: const ApiException('Cannot connect to server.')),
      materialCache: cache,
    );
    await state.loadMaterials();

    await state.removeMaterialOffline(1);

    expect(state.materials, isEmpty);
  });
}
