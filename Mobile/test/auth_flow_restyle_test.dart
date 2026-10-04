// The code screens, awaiting approval and the unavailable tab on the new style:
// the shared title header, a 600dp column, 48dp actions, and a status box where
// the screen is reporting a state. Auth stays English; the two in-app screens
// are checked in Filipino too.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/screens/auth/verify_login_screen.dart';
import 'package:serbis/screens/auth/verify_phone_screen.dart';
import 'package:serbis/screens/awaiting_approval_screen.dart';
import 'package:serbis/screens/unavailable_tab_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/auth_layout.dart';
import 'package:serbis/widgets/borrow_request_widgets.dart' show StatusBox;
import 'package:serbis/widgets/shared_widgets.dart';

class _Api extends ApiService {}

void _size(WidgetTester tester, Size size) {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

Future<void> _pumpVerifyPhone(WidgetTester tester, {Size size = const Size(390, 1400)}) async {
  _size(tester, size);
  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: VerifyPhoneScreen(
      userStore: UserStore(_Api()),
      phone: '09171234567',
      delivery: null,
      onVerified: (_) {},
      onGoToLogin: () {},
    ),
  ));
  await tester.pump();
}

Future<void> _pumpVerifyLogin(WidgetTester tester, {Size size = const Size(390, 1400)}) async {
  _size(tester, size);
  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: VerifyLoginScreen(
      userStore: UserStore(_Api()),
      phone: '09171234567',
      challengeId: 'chal-1',
      delivery: null,
      onVerified: (_) {},
      onGoToLogin: () {},
    ),
  ));
  await tester.pump();
}

Future<void> _pumpAwaiting(WidgetTester tester, {bool filipino = false, Size size = const Size(390, 1400)}) async {
  _size(tester, size);
  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: AwaitingApprovalScreen(
        user: const AppUser(id: '1', firstName: 'Ian', lastName: 'Uy', address: 'San Fabian', organizationName: 'ISU', accountType: 'organization'),
        filipino: filipino,
        onCheckAgain: () async => true,
        onOpenNotifications: () {},
        onOpenProfile: () {},
      ),
    ),
  ));
}

Future<void> _pumpUnavailable(WidgetTester tester, {bool filipino = false, Size size = const Size(390, 1400)}) async {
  _size(tester, size);
  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: UnavailableTabScreen(
        icon: Icons.medical_services_outlined,
        title: filipino ? 'Ambulansya' : 'Ambulance',
        message: filipino ? 'Hindi maikarga ang listahan ng serbisyo.' : 'The service list could not be loaded.',
        filipino: filipino,
        onOpenNotifications: () {},
        onOpenProfile: () {},
        retryLabel: filipino ? 'Subukang muli' : 'Try again',
        onRetry: () {},
      ),
    ),
  ));
}

void main() {
  for (final (name, pump) in [('verify phone', _pumpVerifyPhone), ('verify login', _pumpVerifyLogin)]) {
    group('the $name screen', () {
      testWidgets('sits under the shared header, with the code on a 16px field', (tester) async {
        await pump(tester);

        expect(find.byType(TabHeaderBar), findsOneWidget);
        expect(find.text('Check your messages'), findsOneWidget);
        final field = tester.widget<TextField>(find.byType(TextField));
        expect(field.style!.fontSize, greaterThanOrEqualTo(16));
      });

      testWidgets('Verify is a 48dp button and the two links are 48dp targets', (tester) async {
        await pump(tester);

        expect(tester.getSize(find.widgetWithText(AppButton, 'Verify')).height, greaterThanOrEqualTo(48));
        for (final label in ['Resend code in 0s', 'Send a new code', 'Back to log in']) {
          final link = find.ancestor(of: find.text(label), matching: find.byType(TextButton));
          if (link.evaluate().isEmpty) continue;
          expect(tester.getSize(link.first).height, greaterThanOrEqualTo(48), reason: label);
        }
        expect(find.byType(AuthLink), findsWidgets);
      });

      testWidgets('keeps its column to 600dp on a wide screen', (tester) async {
        await pump(tester, size: const Size(1400, 1200));

        expect(tester.getSize(find.byType(TextField)).width, lessThanOrEqualTo(600));
      });

      testWidgets('fits 320px without overflow', (tester) async {
        await pump(tester, size: const Size(320, 1400));

        expect(tester.takeException(), isNull);
      });
    });
  }

  group('the awaiting approval screen', () {
    testWidgets('says it in an amber status box under the shared header', (tester) async {
      await _pumpAwaiting(tester);

      expect(find.byType(TabHeaderBar), findsOneWidget);
      expect(find.text('Your account'), findsOneWidget);
      expect(find.byType(StatusBox), findsOneWidget);
      expect(find.text('Awaiting MDRRMO approval'), findsOneWidget);
      expect(tester.getSize(find.widgetWithText(AppButton, 'Check again')).height, greaterThanOrEqualTo(48));
    });

    testWidgets('keeps its content to 600dp on a wide screen', (tester) async {
      await _pumpAwaiting(tester, size: const Size(1400, 1000));

      expect(tester.getSize(find.byType(StatusBox)).width, lessThanOrEqualTo(600));
    });

    testWidgets('fits 320px in Filipino without overflow', (tester) async {
      await _pumpAwaiting(tester, filipino: true, size: const Size(320, 1400));

      expect(find.text('Iyong account'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('the unavailable tab', () {
    testWidgets('says Not available in a status box with the reason, and a 48dp retry', (tester) async {
      await _pumpUnavailable(tester);

      expect(find.byType(TabHeaderBar), findsOneWidget);
      expect(find.text('Ambulance'), findsOneWidget);
      expect(find.byType(StatusBox), findsOneWidget);
      expect(find.text('Not available'), findsOneWidget);
      expect(find.text('The service list could not be loaded.'), findsOneWidget);
      expect(tester.getSize(find.widgetWithText(AppButton, 'Try again')).height, greaterThanOrEqualTo(48));
    });

    testWidgets('fits 320px in Filipino without overflow', (tester) async {
      await _pumpUnavailable(tester, filipino: true, size: const Size(320, 1400));

      expect(find.text('Hindi available'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}
