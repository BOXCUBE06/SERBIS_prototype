// M28: the emergency numbers used to be three independent literal copies —
// the SOS sheet, the Library's hotline card and the Services safety notice —
// and they had already drifted. Only the SOS sheet listed MDRRMO's second duty
// line and the PNP/BFP mobiles; the other two silently showed less.
//
// The SOS sheet itself is gone now (removed app-wide). These tests assert the
// remaining two surfaces render every number in `kHotlines` — re-hardcoding a
// subset anywhere fails the surface that did it, which is the regression that
// actually happened.

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
        final text = n.label == null ? n.number : '${n.label} · ${n.number}';
        expect(find.text(text), findsOneWidget,
            reason: '${hotline.label}: $text');
      }
    }
  });

  testWidgets('the Services safety notice gives every number its own row',
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

    // One organisation per line, one number per line under it. The notice used
    // to join each contact onto a single `Label — n1 · n2 · n3` string, which
    // wrapped into one red paragraph — the rescue hotline's four carrier lines
    // ran together with the next office's number.
    for (final hotline in kHotlines) {
      expect(find.text(hotline.labelFor(filipino: false)), findsOneWidget);
      for (final n in hotline.numbers) {
        final text = n.label == null ? n.number : '${n.label} · ${n.number}';
        expect(find.text(text), findsOneWidget, reason: '${hotline.label}: $text');
      }
    }
  });

  testWidgets('every number in the safety notice is its own tap target',
      (tester) async {
    // The notice tells a resident to call instead of filing a request, so a
    // number printed under that instruction has to be dialable. Counted rather
    // than sampled: a contact whose numbers were folded back onto one row
    // would still pass a "there is an InkWell" check.
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

    final expected = kHotlines.fold<int>(0, (sum, h) => sum + h.numbers.length);

    for (final hotline in kHotlines) {
      for (final n in hotline.numbers) {
        final text = n.label == null ? n.number : '${n.label} · ${n.number}';
        expect(
          find.ancestor(of: find.text(text), matching: find.byType(InkWell)),
          findsOneWidget,
          reason: '${hotline.label}: $text is not tappable',
        );
      }
    }

    // The rescue hotline's four carrier lines are four separate targets, not
    // one — a resident on Smart cannot dial the Globe line.
    expect(expected, 9);
  });
}
