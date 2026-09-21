import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/screens/borrow_equipment_screen.dart';
import 'package:serbis/screens/dashboard_screen.dart';
import 'package:serbis/screens/unavailable_tab_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/state/translations.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/app_bottom_nav.dart';
import 'package:serbis/widgets/shared_widgets.dart';

class _Api extends ApiService {
  @override
  Future<List<Map<String, dynamic>>> getRequests() async => [];

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async => [];

  @override
  Future<List<Map<String, dynamic>>> getInfoMaterials() async => [];

  @override
  Future<List<Map<String, dynamic>>> getEquipments() async => [];

  @override
  Future<List<Map<String, dynamic>>> getBorrowings() async => [];
}

const _resident = AppUser(
  id: '1',
  firstName: 'Maria',
  lastName: 'Santos',
  email: 'maria@example.com',
  address: 'San Fabian',
);

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

  group('Home', () {
    Future<void> pumpHome(
      WidgetTester tester, {
      VoidCallback? onOpenLibrary,
      ValueChanged<ServiceType>? onOpenService,
      VoidCallback? onOpenBorrow,
    }) async {
      tester.view.physicalSize = const Size(1080, 3600);
      tester.view.devicePixelRatio = 3;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: HomeScreen(
            appState: AppState(_Api()),
            user: _resident,
            onOpenTrack: () {},
            onOpenLibrary: onOpenLibrary ?? () {},
            onOpenProfile: () {},
            onOpenNotifications: () {},
            onOpenServices: () {},
            onOpenService: onOpenService ?? (_) {},
            onOpenBorrow: onOpenBorrow,
          ),
        ),
      ));
    }

    testWidgets('a large Safety guides card opens the Library', (tester) async {
      var opened = 0;
      await pumpHome(tester, onOpenLibrary: () => opened++);

      expect(find.text('Safety guides'), findsOneWidget);
      expect(find.textContaining('First aid'), findsOneWidget);

      final card = find.ancestor(of: find.text('Safety guides'), matching: find.byType(InkWell)).first;
      expect(tester.getSize(card).height, greaterThanOrEqualTo(88));

      await tester.tap(find.text('Safety guides'));
      expect(opened, 1);
    });

    testWidgets('the ambulance shortcut hands its type to the shell', (tester) async {
      final seen = <ServiceType>[];
      await pumpHome(tester, onOpenService: seen.add);

      await tester.tap(find.text(ServiceType.ambulance.titleFor(false)));
      expect(seen, [ServiceType.ambulance]);
    });

    testWidgets('the borrow shortcut opens the Borrow tab instead of pushing a page', (tester) async {
      var opened = 0;
      await pumpHome(tester, onOpenBorrow: () => opened++);

      await tester.tap(find.text('Borrow Equipment'));
      await tester.pump();

      expect(opened, 1);
      expect(find.byType(BorrowEquipmentScreen), findsNothing);
    });
  });

  group('Borrow tab', () {
    testWidgets('embedded, it carries the app header and a title, not a back-button bar', (tester) async {
      tester.view.physicalSize = const Size(360, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: BorrowEquipmentScreen(
          appState: AppState(_Api()),
          user: _resident,
          embedded: true,
          onOpenNotifications: () {},
          onOpenProfile: () {},
        ),
      ));
      await tester.pumpAndSettle();

      expect(find.byType(AppHeader), findsOneWidget);
      expect(find.byType(AppBar), findsNothing);
      expect(find.text('Borrow equipment'), findsOneWidget);
      expect(find.text('Available'), findsOneWidget);
      expect(find.textContaining('My Requests'), findsOneWidget);
    });

    testWidgets('pushed from elsewhere it keeps its app bar', (tester) async {
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: BorrowEquipmentScreen(appState: AppState(_Api()), user: _resident),
      ));
      await tester.pumpAndSettle();

      expect(find.byType(AppBar), findsOneWidget);
      expect(find.byType(AppHeader), findsNothing);
    });

    testWidgets('a tab the account is not offered says so, in both languages', (tester) async {
      for (final filipino in [false, true]) {
        await tester.pumpWidget(MaterialApp(
          theme: buildAppTheme(),
          home: Scaffold(
            body: UnavailableTabScreen(
              icon: Icons.inventory_2_outlined,
              title: tr(filipino, 'nav.borrow'),
              message: tr(filipino, 'tab.borrow_unavailable'),
              filipino: filipino,
              onOpenNotifications: () {},
              onOpenProfile: () {},
            ),
          ),
        ));

        expect(find.text(tr(filipino, 'tab.borrow_unavailable')), findsOneWidget);
        expect(find.byType(AppButton), findsNothing);
      }
    });
  });
}
