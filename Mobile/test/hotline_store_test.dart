// Dynamic hotlines (M28): GET /api/hotlines, cached by HotlineCache, with
// kHotlines as the fallback. The rule under test: a failed or empty fetch
// never replaces a working list.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/data/hotlines.dart';
import 'package:serbis/screens/library_screen.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/hotline_cache.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/hotline_list.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _FakeApi extends ApiService {
  List<Map<String, dynamic>> rows = [];
  Object? error;

  @override
  Future<List<Map<String, dynamic>>> getHotlines() async {
    if (error != null) throw error!;
    return rows;
  }

  @override
  Future<List<Map<String, dynamic>>> getInfoMaterials() async => [];
}

Map<String, dynamic> _row(String label, {String number = '0917-000-0000'}) => {
      'label': label,
      'label_fil': null,
      'numbers': [
        {'label': 'Globe', 'number': number},
      ],
    };

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('fromJson skips rows with no name or no number', () {
    expect(Hotline.fromJson({'label': 'X', 'numbers': []}), isNull);
    expect(Hotline.fromJson({'label': '', 'numbers': [{'number': '911'}]}), isNull);
    expect(Hotline.fromJson('junk'), isNull);

    final h = Hotline.fromJson({
      'label': 'Duty',
      'numbers': [{'label': ' ', 'number': ' 911 '}],
    })!;
    expect(h.numbers.single.label, isNull);
    expect(h.numbers.single.number, '911');
    expect(h.labelFil, 'Duty');
  });

  test('cache round-trips and reads corrupt data as empty', () async {
    final cache = HotlineCache();
    await cache.save([Hotline.fromJson(_row('BFP'))!]);

    final loaded = await cache.load();
    expect(loaded!.single.label, 'BFP');
    expect(loaded.single.numbers.single.label, 'Globe');

    SharedPreferences.setMockInitialValues({'serbis.hotlines.cache.v2': '{not json'});
    expect(await HotlineCache().load(), isNull);
  });

  test('an old v1 cache is ignored and deleted on the next save', () async {
    SharedPreferences.setMockInitialValues({
      'serbis.hotlines.cache.v1':
          '[{"label":"Old","numbers":[{"number":"911"}],"icon":"fire","tone":"amber"}]',
    });
    expect(await HotlineCache().load(), isNull);

    await HotlineCache().save([Hotline.fromJson(_row('New'))!]);
    final prefs = await SharedPreferences.getInstance();
    expect(prefs.containsKey('serbis.hotlines.cache.v1'), isFalse);
    expect((await HotlineCache().load())!.single.label, 'New');
  });

  test('built-in list until a fetch lands, then the server list is cached', () async {
    final api = _FakeApi()..rows = [_row('MDRRMO Duty')];
    final state = AppState(api);
    expect(state.hotlines, same(kHotlines));

    await state.loadHotlines();
    expect(state.hotlines.single.label, 'MDRRMO Duty');
    expect((await HotlineCache().load())!.single.label, 'MDRRMO Duty');
  });

  test('a failed or empty fetch keeps the cached list', () async {
    await HotlineCache().save([Hotline.fromJson(_row('Cached'))!]);

    final failing = _FakeApi()..error = Exception('offline');
    final state = AppState(failing);
    await state.loadHotlines();
    expect(state.hotlines.single.label, 'Cached');

    final empty = AppState(_FakeApi());
    await empty.loadHotlines();
    expect(empty.hotlines.single.label, 'Cached');
  });

  test('a failed fetch with no cache keeps the built-in list', () async {
    final state = AppState(_FakeApi()..error = Exception('offline'));
    await state.loadHotlines();
    expect(state.hotlines, same(kHotlines));
  });

  testWidgets('Library shows the server list', (tester) async {
    tester.view.physicalSize = const Size(1080, 4800);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    final state = AppState(_FakeApi()..rows = [_row('MDRRMO Duty', number: '0917-111-2222')]);
    await state.loadHotlines();

    await tester.pumpWidget(MaterialApp(
      theme: buildAppTheme(),
      home: Scaffold(
        body: LibraryScreen(appState: state, onOpenNotifications: () {}, onOpenProfile: () {}),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('MDRRMO Duty'), findsOneWidget);
    expect(find.text('Globe · 0917-111-2222'), findsOneWidget);
    expect(find.text('Echague Rescue Hotline'), findsNothing);
  });

  testWidgets('Library names hotlines in Filipino when that is the language', (tester) async {
    tester.view.physicalSize = const Size(1080, 4800);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    final state = AppState(_FakeApi())..language = AppLanguage.filipino;

    await tester.pumpWidget(MaterialApp(
      theme: buildAppTheme(),
      home: Scaffold(
        body: LibraryScreen(appState: state, onOpenNotifications: () {}, onOpenProfile: () {}),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Pambansang Emerhensiya'), findsOneWidget);
    expect(find.text('National Emergency'), findsNothing);
  });

  testWidgets('HotlinesPage (login link) lists every number', (tester) async {
    await tester.pumpWidget(MaterialApp(
      theme: buildAppTheme(),
      home: HotlinesPage(hotlines: [Hotline.fromJson(_row('BFP', number: '(02) 426-3812'))!]),
    ));

    expect(find.text('Emergency hotlines'), findsOneWidget);
    expect(find.text('BFP'), findsOneWidget);
    expect(find.text('Globe · (02) 426-3812'), findsOneWidget);
  });
}
