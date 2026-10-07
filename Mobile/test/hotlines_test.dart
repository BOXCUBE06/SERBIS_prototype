// M28: the emergency numbers used to be three independent literal copies —
// the SOS sheet, the Library's hotline card and the Services safety notice —
// and they had already drifted. Only the SOS sheet listed MDRRMO's second duty
// line and the PNP/BFP mobiles; the other two silently showed less.
//
// The SOS sheet is gone (removed app-wide) and the Services notice no longer
// lists numbers: the Library is the one place. These tests assert it renders
// every number in `kHotlines`, and that Services does not grow a copy again.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/data/hotlines.dart';
import 'package:serbis/screens/library_screen.dart';
import 'package:serbis/screens/services_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';

class _FakeApi extends ApiService {
  @override
  Future<List<Map<String, dynamic>>> getRequests() async =>
      <Map<String, dynamic>>[];

  @override
  Future<List<Map<String, dynamic>>> getServices(
          {String locale = 'en'}) async =>
      <Map<String, dynamic>>[];

  @override
  Future<List<Map<String, dynamic>>> getInfoMaterials() async =>
      <Map<String, dynamic>>[];
}

/// A narrow phone, not the 800x600 test default. The numbers line is nearly
/// twice as wide as the single number these rows used to carry, so a layout
/// that overflows only on a small screen has to be able to fail here — a
/// RenderFlex overflow is a test failure, not a red stripe, under `flutter
/// test`.
Future<void> _pump(WidgetTester tester, Widget child) async {
  tester.view.physicalSize = const Size(1080, 4800);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(body: child),
  ));
  await tester.pumpAndSettle();
}

void main() {
  test('every hotline carries at least one number', () {
    expect(kHotlines, isNotEmpty);
    for (final hotline in kHotlines) {
      expect(hotline.numbers, isNotEmpty, reason: '${hotline.label} has none');
      for (final n in hotline.numbers) {
        expect(n.number.trim(), isNotEmpty, reason: '${hotline.label} has a blank number');
      }
    }
  });

  test('the Echague Rescue Hotline keeps all four carrier lines', () {
    // The one contact whose numbers cannot be dialed interchangeably — a
    // resident on Smart cannot use the Globe line. Losing one silently is
    // the exact drift this file was written to catch.
    final rescue =
        kHotlines.firstWhere((h) => h.label == 'Echague Rescue Hotline');
    expect(rescue.numbers.length, 4);
    expect(rescue.numbers.map((n) => n.label),
        containsAll(['Landline', 'Globe', 'Smart', 'Sun']));
  });

  test('PDRRMO keeps both numbers', () {
    final pdrrmo = kHotlines.firstWhere((h) => h.label == 'PDRRMO');
    expect(pdrrmo.numbers.length, 2);
  });

  testWidgets('the Library hotline card lists every number as its own row',
      (tester) async {
    await _pump(
      tester,
      LibraryScreen(
        appState: AppState(_FakeApi()),
        onOpenNotifications: () {},
        onOpenProfile: () {},
      ),
    );

    for (final hotline in kHotlines) {
      expect(find.text(hotline.label), findsOneWidget);
      for (final n in hotline.numbers) {
        // The line's kind sits over its number, each its own text.
        expect(find.text(n.number), findsOneWidget, reason: '${hotline.label}: ${n.number}');
      }
    }
  });

  testWidgets('the Services tab shows no hotline numbers: they live in the Library',
      (tester) async {
    await _pump(
      tester,
      ServicesScreen(
        appState: AppState(_FakeApi()),
        user: const AppUser(
          id: '1',
          firstName: 'Test',
          lastName: 'Resident',
          email: 'test@example.com',
          address: '',
        ),
        onSubmitted: () {},
        onOpenNotifications: () {},
        onOpenProfile: () {},
      ),
    );

    for (final hotline in kHotlines) {
      for (final n in hotline.numbers) {
        expect(find.textContaining(n.number), findsNothing, reason: '${hotline.label} is back on Services');
      }
    }
  });
}
