// Opening a published material. M10/M11 made the download real; until M33 the
// saved bytes had no way to reach a resident's screen.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/info_material.dart';
import 'package:serbis/screens/library_screen.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/file_opener.dart';
import 'package:serbis/state/material_cache.dart';
import 'package:serbis/state/request_store.dart';

class _FakeOpener implements FileOpener {
  _FakeOpener({this.fileOpens = true, this.urlOpens = true});

  final bool fileOpens;
  final bool urlOpens;
  final List<String> openedFiles = <String>[];
  final List<String> openedUrls = <String>[];

  @override
  Future<bool> openFile(String path) async {
    openedFiles.add(path);
    return fileOpens;
  }

  @override
  Future<bool> openUrl(String url) async {
    openedUrls.add(url);
    return urlOpens;
  }
}

CachedMaterial _cached(int id) => CachedMaterial(
      id: id,
      title: 'Evacuation Center Map',
      fileType: 'pdf',
      sizeBytes: 2048,
      path: '/documents/materials/$id.pdf',
      savedAt: DateTime(2026, 7, 29),
    );

InfoMaterial _material({int id = 1, String url = 'https://api.test/f/1.pdf'}) =>
    InfoMaterial(
      id: id,
      title: 'Evacuation Center Map',
      fileType: 'pdf',
      sizeBytes: 2048,
      url: url,
    );

AppState _stateWith(_FakeOpener opener, {CachedMaterial? saved}) {
  final state = AppState(ApiService(), fileOpener: opener);
  if (saved != null) {
    state.savedMaterials = <int, CachedMaterial>{saved.id: saved};
  }
  return state;
}

void main() {
  test('a saved material opens from disk and never touches the network', () async {
    final opener = _FakeOpener();
    final state = _stateWith(opener, saved: _cached(1));

    expect(await state.openMaterial(_material()), MaterialOpenResult.openedSaved);
    expect(opener.openedFiles, ['/documents/materials/1.pdf']);
    // The point of saving it was to not need the server during a storm.
    expect(opener.openedUrls, isEmpty);
  });

  test('an unsaved material opens the server copy', () async {
    final opener = _FakeOpener();
    final state = _stateWith(opener);

    expect(await state.openMaterial(_material()), MaterialOpenResult.openedOnline);
    expect(opener.openedFiles, isEmpty);
    expect(opener.openedUrls, ['https://api.test/f/1.pdf']);
  });

  test('a saved copy no viewer can display still falls back to the server', () async {
    final opener = _FakeOpener(fileOpens: false);
    final state = _stateWith(opener, saved: _cached(1));

    expect(await state.openMaterial(_material()), MaterialOpenResult.openedOnline);
    expect(opener.openedFiles, isNotEmpty);
    expect(opener.openedUrls, isNotEmpty);
  });

  test('both routes failing on a saved material reports the missing viewer', () async {
    final opener = _FakeOpener(fileOpens: false, urlOpens: false);
    final state = _stateWith(opener, saved: _cached(1));

    expect(await state.openMaterial(_material()), MaterialOpenResult.noViewer);
  });

  test('a cache-listed material with no URL and no saved copy is unavailable', () async {
    // What the Library renders when the server was unreachable: the row exists
    // because of the index, and `toMaterial()` leaves `url` empty.
    final opener = _FakeOpener();
    final state = _stateWith(opener);

    expect(
      await state.openMaterial(_material(url: '')),
      MaterialOpenResult.unavailable,
    );
    expect(opener.openedUrls, isEmpty);
  });

  testWidgets('tapping the row in the Library is what opens it', (tester) async {
    // The store logic above was already reachable before this task. What was
    // missing is this: the row had no `onTap` at all.
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    final opener = _FakeOpener();
    final state = AppState(ApiService(), fileOpener: opener);
    state.materials.add(_material());

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: LibraryScreen(
            appState: state,
            onOpenNotifications: () {},
            onOpenProfile: () {},
          ),
        ),
      ),
    );

    // The MDRRMO Documents section sits below the hotlines and the eight
    // built-in articles, so the row is not built until it is scrolled to.
    final row = find.text('Evacuation Center Map');
    await tester.scrollUntilVisible(row, 300);
    await tester.tap(row);
    await tester.pumpAndSettle();

    expect(opener.openedUrls, ['https://api.test/f/1.pdf']);
  });
}
