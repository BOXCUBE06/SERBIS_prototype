// M28: the emergency numbers used to be three independent literal copies —
// the SOS sheet, the Library's hotline card and the Services safety notice —
// and they had already drifted. Only the SOS sheet listed MDRRMO's second duty
// line and the PNP/BFP mobiles; the other two silently showed less.
//
// These tests assert the same thing three times on purpose: every surface
// renders every number in `kHotlines`. Re-hardcoding a subset anywhere fails
// the surface that did it, which is the regression that actually happened.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/data/hotlines.dart';
import 'package:serbis/screens/library_screen.dart';
import 'package:serbis/screens/services_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/sos_button.dart';

class _FakeApi extends ApiService {
  @override
  Future<List<Map<String, dynamic>>> getRequests() async =>
      <Map<String, dynamic>>[];

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async =>
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
      expect(hotline.numbersLine, contains(hotline.numbers.first));
    }
  });

  test('MDRRMO keeps both duty lines', () {
    // The one contact whose second number existed on a single surface. Losing
    // it again is the exact drift this task was filed for.
    final mdrrmo = kHotlines.firstWhere((h) => h.label == 'MDRRMO');
    expect(mdrrmo.numbers.length, greaterThanOrEqualTo(2));
  });

  testWidgets('the SOS sheet lists every hotline in full', (tester) async {
    await _pump(tester, const SosSheet());

    for (final hotline in kHotlines) {
      expect(find.text(hotline.label), findsOneWidget);
      expect(find.text(hotline.numbersLine), findsOneWidget);
    }
  });

  testWidgets('the Library hotline card lists every hotline in full',
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
      expect(find.text(hotline.labelFor(filipino: false)), findsOneWidget);
      expect(find.text(hotline.numbersLine), findsOneWidget);
    }
  });

  testWidgets('the Services safety notice lists every hotline in full',
      (tester) async {
    await _pump(
      tester,
      ServicesScreen(
        appState: AppState(_FakeApi()),
        // This test is about the hotline notice, not the forms; the resident
        // only has to exist.
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
      expect(
        find.text('${hotline.labelFor(filipino: false)} — ${hotline.numbersLine}'),
        findsOneWidget,
      );
    }
  });
}
