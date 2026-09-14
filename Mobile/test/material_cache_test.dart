// The offline cache is scope item #4 of the five things this app is meant to
// do, and the feature it replaces was a 700 ms animation that wrote nothing.
// These tests assert against the filesystem, not against a flag.

import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/info_material.dart';
import 'package:serbis/state/material_cache.dart';
import 'package:shared_preferences/shared_preferences.dart';

const InfoMaterial _material = InfoMaterial(
  id: 7,
  title: 'Flood Preparedness Checklist',
  fileType: 'pdf',
  sizeBytes: 12,
  url: 'http://example.test/storage/info_materials/flood.pdf',
);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late Directory root;

  setUp(() async {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    root = await Directory.systemTemp.createTemp('serbis_materials_test');
  });

  tearDown(() async {
    if (await root.exists()) {
      await root.delete(recursive: true);
    }
  });

  MaterialCache cacheWith(Future<List<int>> Function(String url) download) {
    return MaterialCache(
      download: download,
      documentsDirectory: () async => root.path,
    );
  }

  test('save writes the downloaded bytes and indexes them', () async {
    final cache = cacheWith((_) async => <int>[1, 2, 3, 4]);

    final entry = await cache.save(_material);

    expect(entry, isNotNull);
    expect(await File(entry!.path).readAsBytes(), <int>[1, 2, 3, 4]);
    expect(entry.sizeBytes, 4, reason: 'the real byte count, not the metadata');

    final index = await cache.loadIndex();
    expect(index.keys, <int>[7]);
    expect(index[7]!.title, 'Flood Preparedness Checklist');
  });

  // #13. The badge is read off the saved copy when the network is down, so a
  // verified material that loses the flag on the way through the index would
  // read as unreviewed exactly when the resident cannot check.
  test('verified survives the index round trip', () async {
    const verified = InfoMaterial(
      id: 8,
      title: 'Evacuation Routes',
      fileType: 'pdf',
      sizeBytes: 12,
      url: 'http://example.test/storage/info_materials/routes.pdf',
      verified: true,
    );

    await cacheWith((_) async => <int>[1, 2, 3, 4]).save(verified);

    final index = await cacheWith((_) async => <int>[]).loadIndex();

    expect(index[8]!.verified, isTrue);
    expect(index[8]!.toMaterial().verified, isTrue);
  });

  // An index written before the key existed must keep working, and must not
  // invent a review nobody did.
  test('an entry with no verified key reads as unverified', () async {
    await cacheWith((_) async => <int>[1, 2, 3, 4]).save(_material);

    final index = await cacheWith((_) async => <int>[]).loadIndex();

    expect(index[7]!.verified, isFalse);
  });

  test('the index survives a new cache instance', () async {
    await cacheWith((_) async => <int>[1, 2, 3, 4]).save(_material);

    final index = await cacheWith((_) async => <int>[]).loadIndex();

    expect(index.containsKey(7), isTrue);
  });

  test('a failed download saves nothing and leaves no partial file', () async {
    final cache = cacheWith((_) async => throw Exception('no network'));

    final entry = await cache.save(_material);

    expect(entry, isNull);
    expect(await cache.loadIndex(), isEmpty);

    final folder = Directory('${root.path}${Platform.pathSeparator}materials');
    if (await folder.exists()) {
      expect(await folder.list().toList(), isEmpty);
    }
  });

  test('an empty response is treated as a failure', () async {
    final cache = cacheWith((_) async => <int>[]);

    expect(await cache.save(_material), isNull);
    expect(await cache.loadIndex(), isEmpty);
  });

  test('loadIndex drops an entry whose file has gone', () async {
    final cache = cacheWith((_) async => <int>[1, 2, 3, 4]);
    final entry = await cache.save(_material);

    // An OS cache wipe, a "clear storage", a restore that skipped app data.
    await File(entry!.path).delete();

    expect(await cache.loadIndex(), isEmpty);

    // And the pruning is persisted, not recomputed on every read.
    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getString('serbis_materials_index_v1'), '[]');
  });

  test('re-saving overwrites the same file instead of accumulating', () async {
    final cache = cacheWith((_) async => <int>[1, 2, 3, 4]);
    await cache.save(_material);
    await cacheWith((_) async => <int>[9, 9]).save(_material);

    final folder = Directory('${root.path}${Platform.pathSeparator}materials');
    final files = await folder.list().toList();

    expect(files.length, 1);
    expect(await File(files.single.path).readAsBytes(), <int>[9, 9]);
  });

  test('remove deletes both the file and the index entry', () async {
    final cache = cacheWith((_) async => <int>[1, 2, 3, 4]);
    final entry = await cache.save(_material);

    await cache.remove(7);

    expect(await File(entry!.path).exists(), isFalse);
    expect(await cache.loadIndex(), isEmpty);
  });

  test('a corrupt index is discarded rather than thrown', () async {
    SharedPreferences.setMockInitialValues(<String, Object>{
      'serbis_materials_index_v1': 'not json',
    });

    expect(await cacheWith((_) async => <int>[]).loadIndex(), isEmpty);
  });
}
