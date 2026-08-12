// M18: `sos_button.dart` was 48.2% covered. `hotlines_test.dart` renders
// `SosSheet` directly and reads the numbers off it, so the list itself was
// already checked — what nothing touched was the whole of `SosFab`, the
// `SosSheet.show` route that puts the sheet on screen, and the two ways the
// sheet closes.
//
// The SOS button is the one control on the app that a resident presses while
// something is on fire, so the paths worth pinning are: it opens, it closes
// both ways, and the pulse that says it is live keeps moving.
//
// **These tests assert the placeholder behaviour, not a phone call.** M1 is
// still open: tapping a hotline row announces "Calling …" and dials nothing.
// The test named for it says so, and is the test that fails when `url_launcher`
// is finally wired in — which is the point at which it should be rewritten.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/data/hotlines.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/sos_button.dart';

/// `SosFab` runs a `repeat()`ing controller, so `pumpAndSettle` never returns —
/// every wait in this file is an explicit `pump(duration)`. A settle call here
/// is a ten-minute timeout, not a hang, but it is still a failure.
Future<void> _pumpFab(WidgetTester tester) async {
  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: const Scaffold(body: Center(child: SosFab())),
  ));
  await tester.pump();
}

/// Long enough for the modal route's entrance transition to finish.
Future<void> _settleSheet(WidgetTester tester) async {
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 400));
}

Future<void> _openSheet(WidgetTester tester) async {
  await tester.tap(find.byType(SosFab));
  await _settleSheet(tester);
}

void main() {
  group('the SOS button', () {
    testWidgets('carries a visible label and an invisible copy that sizes the pulse',
        (tester) async {
      await _pumpFab(tester);

      // Two, not one. The ring is drawn around a zero-opacity copy of the same
      // label rather than a hardcoded size, which is what keeps the ring
      // matched to the button when the label or its font changes. Replacing
      // that copy with a fixed box fails here.
      expect(find.text('SOS'), findsNWidgets(2));
      expect(find.byIcon(Icons.call_rounded), findsNWidgets(2));
    });

    testWidgets('pulses — the ring fades and grows as the controller runs',
        (tester) async {
      await _pumpFab(tester);

      double ringOpacity() =>
          tester.widget<Opacity>(find.byType(Opacity).first).opacity;
      double ringScale() =>
          tester.widget<Transform>(find.byType(Transform).first).transform.getMaxScaleOnAxis();

      final opacityAtRest = ringOpacity();
      final scaleAtRest = ringScale();

      // A quarter of the 2200ms cycle: far enough in to be unambiguous, short
      // enough not to wrap around to the start.
      await tester.pump(const Duration(milliseconds: 550));

      expect(ringOpacity(), lessThan(opacityAtRest));
      expect(ringScale(), greaterThan(scaleAtRest));
    });

    testWidgets('opens the hotline sheet when tapped', (tester) async {
      await _pumpFab(tester);
      expect(find.byType(SosSheet), findsNothing);

      await _openSheet(tester);

      expect(find.byType(SosSheet), findsOneWidget);
      expect(find.text('Emergency hotlines'), findsOneWidget);
      // The instruction that makes the sheet honest about what it is: the app
      // is not the fastest way to reach anyone.
      expect(
        find.textContaining("call directly"),
        findsOneWidget,
      );
    });

    testWidgets('releases its animation controller when it leaves the tree',
        (tester) async {
      await _pumpFab(tester);

      await tester.pumpWidget(const MaterialApp(home: Scaffold(body: SizedBox())));
      await tester.pump();

      // A controller still ticking after its State is gone throws on the next
      // frame, not at dispose time — so the assertion is the frame above plus
      // this.
      expect(tester.takeException(), isNull);
      expect(find.byType(SosFab), findsNothing);
    });
  });

  group('the hotline sheet', () {
    testWidgets('announces a call and closes — M1: it does not dial',
        (tester) async {
      await _pumpFab(tester);
      await _openSheet(tester);

      final mdrrmo = kHotlines.first;
      await tester.tap(find.text(mdrrmo.label));
      await _settleSheet(tester);

      expect(
        find.text('Calling ${mdrrmo.label} · ${mdrrmo.numbersLine}'),
        findsOneWidget,
        reason: 'the row reports what it would call; wiring url_launcher '
            'replaces this and should replace this test with it',
      );
      expect(find.byType(SosSheet), findsNothing);
    });

    testWidgets('every row is tappable, not just the first', (tester) async {
      for (final hotline in kHotlines) {
        await _pumpFab(tester);
        await _openSheet(tester);

        await tester.tap(find.text(hotline.label));
        await _settleSheet(tester);

        expect(
          find.text('Calling ${hotline.label} · ${hotline.numbersLine}'),
          findsOneWidget,
          reason: '${hotline.label} row did not report its own numbers',
        );
      }
    });

    testWidgets('the close button dismisses it without announcing a call',
        (tester) async {
      await _pumpFab(tester);
      await _openSheet(tester);

      await tester.tap(find.descendant(
        of: find.byType(SosSheet),
        matching: find.byIcon(Icons.close_rounded),
      ));
      await _settleSheet(tester);

      expect(find.byType(SosSheet), findsNothing);
      // Closing the sheet is a resident changing their mind. A "Calling …"
      // snackbar left behind by the dismissal would tell them a call went out.
      expect(find.textContaining('Calling '), findsNothing);
    });
  });
}
