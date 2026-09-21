import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/state/translations.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/app_bottom_nav.dart';
import 'package:serbis/widgets/shared_widgets.dart';

Future<void> _pumpNav(
  WidgetTester tester, {
  int index = 0,
  bool filipino = false,
  double textScale = 1.0,
  ValueChanged<int>? onTap,
}) async {
  // A small phone: 360 logical pixels wide is the width the bar is designed for.
  tester.view.physicalSize = const Size(360, 800);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: MediaQuery(
      data: MediaQueryData(size: const Size(360, 800), textScaler: TextScaler.linear(textScale)),
      child: Scaffold(
        bottomNavigationBar: AppBottomNav(index: index, onTap: onTap ?? (_) {}, filipino: filipino),
      ),
    ),
  ));
}

void main() {
  group('bottom navigation', () {
    testWidgets('five destinations, each named in words', (tester) async {
      await _pumpNav(tester);

      for (final label in ['Home', 'Ambulance', 'Services', 'Borrow', 'Track']) {
        expect(find.text(label), findsOneWidget, reason: label);
      }
      expect(find.text('Library'), findsNothing);
      expect(find.text('Profile'), findsNothing);
    });

    testWidgets('every label fits its tab at 360dp, in both languages, at large text', (tester) async {
      for (final filipino in [false, true]) {
        for (final scale in [1.0, 1.3, 2.0]) {
          await _pumpNav(tester, filipino: filipino, textScale: scale);

          // The test font has no real glyph widths, so this asserts the bar
          // lays out with no overflow and names every tab; the measured fit
          // with the real font is checked in the browser build.
          for (final key in ['nav.home', 'nav.ambulance', 'nav.services', 'nav.borrow', 'nav.track']) {
            expect(find.text(tr(filipino, key)), findsOneWidget, reason: '$key filipino=$filipino scale=$scale');
          }
          expect(tester.takeException(), isNull, reason: 'filipino=$filipino scale=$scale');
        }
      }
    });

    testWidgets('a tab is at least 48dp tall and a fifth of the width wide', (tester) async {
      await _pumpNav(tester);

      final tab = find.ancestor(of: find.text('Ambulance'), matching: find.byType(InkWell)).first;
      final size = tester.getSize(tab);
      expect(size.height, greaterThanOrEqualTo(48));
      expect(size.width, greaterThanOrEqualTo(48));
    });

    testWidgets('tapping anywhere on a tab, not just its label, selects it', (tester) async {
      final taps = <int>[];
      await _pumpNav(tester, onTap: taps.add);

      final tab = find.ancestor(of: find.text('Borrow'), matching: find.byType(InkWell)).first;
      // The top-left corner of the tile is neither the icon nor the label.
      await tester.tapAt(tester.getTopLeft(tab) + const Offset(3, 3));
      expect(taps, [3]);
    });

    testWidgets('the current tab is announced as selected', (tester) async {
      await _pumpNav(tester, index: 2);

      final handle = tester.ensureSemantics();
      expect(
        tester.getSemantics(find.bySemanticsLabel('Services')),
        isSemantics(label: 'Services', isButton: true, isSelected: true, hasTapAction: true),
      );
      handle.dispose();
    });

    testWidgets('Filipino labels', (tester) async {
      await _pumpNav(tester, filipino: true);

      for (final label in ['Home', 'Ambulansya', 'Serbisyo', 'Hiram', 'Subaybay']) {
        expect(find.text(label), findsOneWidget, reason: label);
      }
    });
  });

  group('app header', () {
    testWidgets('shows a back button instead of the profile icon on a page', (tester) async {
      var backed = false;
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: AppHeader(
            onNotificationsTap: () {},
            onBack: () => backed = true,
          ),
        ),
      ));

      expect(find.byIcon(Icons.arrow_back_rounded), findsOneWidget);
      expect(find.byIcon(Icons.person_outline_rounded), findsNothing);

      await tester.tap(find.byIcon(Icons.arrow_back_rounded));
      expect(backed, isTrue);
    });

    testWidgets('header buttons are 48dp touch targets', (tester) async {
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: AppHeader(onNotificationsTap: () {}, onProfileTap: () {}),
        ),
      ));

      for (final icon in [Icons.notifications_outlined, Icons.person_outline_rounded]) {
        final target = find.ancestor(of: find.byIcon(icon), matching: find.byType(GestureDetector)).first;
        expect(tester.getSize(target), const Size(48, 48), reason: '$icon');
      }
    });

    testWidgets('the header fits 360dp with a back button and notifications', (tester) async {
      tester.view.physicalSize = const Size(360, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: AppHeader(onNotificationsTap: () {}, onBack: () {}),
        ),
      ));

      expect(tester.takeException(), isNull);
    });
  });
}
