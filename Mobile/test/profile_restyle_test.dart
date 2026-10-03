// Profile and everything it opens on the new style: shared header, a 600dp
// column, 72dp settings rows, sheets in one frame, the language choice drawn
// open, the shared confirm dialog, and a two-step number change that says so.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/screens/change_phone_sheet.dart';
import 'package:serbis/screens/profile_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/form_inputs.dart';
import 'package:serbis/widgets/form_steps.dart';
import 'package:serbis/widgets/request_summary.dart' show SummaryCard;
import 'package:serbis/widgets/shared_widgets.dart';

class _Api extends ApiService {
  @override
  Future<List<Map<String, dynamic>>> getRequests() async => [];

  @override
  Future<List<int>?> fetchProfilePhoto(String residentId) async => null;
}

const _resident = AppUser(
  id: '1',
  firstName: 'Maria',
  lastName: 'Santos',
  phone: '+639171111111',
  address: 'San Fabian',
  streetAddress: 'Purok 3',
);

Future<(AppState, List<String>)> _pump(
  WidgetTester tester, {
  bool filipino = false,
  Size size = const Size(390, 2000),
  VoidCallback? onBack,
  VoidCallback? onLogout,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);

  final state = AppState(_Api());
  if (filipino) state.setLanguage(AppLanguage.filipino);
  final events = <String>[];
  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: ListenableBuilder(
      listenable: state,
      builder: (_, __) => Scaffold(
        body: ProfileScreen(
          appState: state,
          userStore: UserStore(_Api()),
          user: _resident,
          onUserChanged: (_) {},
          onLogout: onLogout ?? () => events.add('logout'),
          onOpenNotifications: () {},
          onOpenProfile: () {},
          onBack: onBack,
        ),
      ),
    ),
  ));
  await tester.pumpAndSettle();
  return (state, events);
}

void main() {
  group('the profile page', () {
    testWidgets('sits under the shared header with a back arrow', (tester) async {
      var back = 0;
      await _pump(tester, onBack: () => back++);

      expect(find.text('My profile'), findsOneWidget);
      expect(find.text('Your account and settings'), findsOneWidget);
      await tester.tap(find.byIcon(Icons.arrow_back_rounded));
      expect(back, 1);
    });

    testWidgets('every settings row is at least 72dp tall', (tester) async {
      await _pump(tester);

      for (final title in ['MDRRMO text alerts', 'Language', 'Offline materials', 'Log out']) {
        final row = find.ancestor(of: find.text(title), matching: find.byType(InkWell)).first;
        expect(tester.getSize(row).height, greaterThanOrEqualTo(72), reason: title);
      }
    });

    testWidgets('keeps its content to 600dp on a wide screen', (tester) async {
      await _pump(tester, size: const Size(1400, 2000));

      expect(tester.getSize(find.byType(SummaryCard)).width, lessThanOrEqualTo(600));
    });

    for (final width in [320.0, 390.0]) {
      testWidgets('fits ${width.toInt()}px in Filipino without overflow', (tester) async {
        await _pump(tester, filipino: true, size: Size(width, 2400));

        expect(tester.takeException(), isNull);
        expect(find.text('Aking profile'), findsOneWidget);
        expect(find.text('Mag-log out'), findsOneWidget);
      });
    }
  });

  group('its sheets and dialogs', () {
    testWidgets('the language choice is drawn open and switches the app', (tester) async {
      final (state, _) = await _pump(tester);

      await tester.tap(find.text('Language'));
      await tester.pumpAndSettle();

      expect(find.byType(SheetFrame), findsOneWidget);
      expect(find.byType(ListTile), findsNothing);
      expect(find.byType(AppChoiceList), findsOneWidget);

      await tester.tap(find.text('Filipino'));
      await tester.pumpAndSettle();

      expect(state.language, AppLanguage.filipino);
    });

    testWidgets('the photo sheet offers its actions as 48dp buttons', (tester) async {
      await _pump(tester);

      // The avatar is the only tap target that opens it.
      await tester.tap(find.text('MS'));
      await tester.pumpAndSettle();

      expect(find.text('Choose a photo'), findsOneWidget);
      final button = find.ancestor(of: find.text('Choose a photo'), matching: find.byType(ElevatedButton));
      expect(tester.getSize(button).height, greaterThanOrEqualTo(48));
    });

    testWidgets('log out asks first, in the shared dialog, and only confirming logs out', (tester) async {
      final (_, events) = await _pump(tester);

      await tester.tap(find.text('Log out'));
      await tester.pumpAndSettle();
      expect(find.byType(ConfirmDialog), findsOneWidget);

      await tester.tap(find.text('Stay logged in'));
      await tester.pumpAndSettle();
      expect(events, isEmpty);

      await tester.tap(find.text('Log out'));
      await tester.pumpAndSettle();
      await tester.tap(find.descendant(of: find.byType(ConfirmDialog), matching: find.text('Log out')));
      await tester.pumpAndSettle();
      expect(events, ['logout']);
    });

    testWidgets('account details is one framed sheet with four inputs and the locked fields noted', (tester) async {
      await _pump(tester);

      await tester.tap(find.text('Account details'));
      await tester.pumpAndSettle();

      expect(find.byType(SheetFrame), findsOneWidget);
      expect(find.byType(TextField), findsNWidgets(4));
      expect(find.text('Barangay'), findsOneWidget);
      for (final field in tester.widgetList<TextField>(find.byType(TextField))) {
        expect(field.style!.fontSize, greaterThanOrEqualTo(16));
      }
    });

    testWidgets('the offline page has the shared header and 72dp rows', (tester) async {
      await _pump(tester);

      await tester.tap(find.text('Offline materials'));
      await tester.pumpAndSettle();

      expect(find.byType(TabHeaderBar), findsOneWidget);
      expect(find.text('Available without internet'), findsOneWidget);
      expect(find.text('Included in the app'), findsOneWidget);
      final row = find.ancestor(of: find.byIcon(Icons.chevron_right_rounded), matching: find.byType(InkWell)).first;
      expect(tester.getSize(row).height, greaterThanOrEqualTo(72));

      await tester.tap(find.byIcon(Icons.arrow_back_rounded));
      await tester.pumpAndSettle();
      expect(find.text('My profile'), findsOneWidget);
    });
  });

  group('changing the number', () {
    Future<void> openChangePhone(WidgetTester tester, {bool filipino = false, Size size = const Size(390, 1600)}) async {
      tester.view.physicalSize = size;
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: ChangePhoneSheet(user: _resident, userStore: UserStore(_Api()), filipino: filipino),
        ),
      ));
      await tester.pumpAndSettle();
    }

    testWidgets('says it is step 1 of 2 and draws the progress', (tester) async {
      await openChangePhone(tester);

      expect(find.text('Step 1 of 2'), findsOneWidget);
      expect(find.byType(FormStepProgress), findsOneWidget);
      expect(find.text('Change your mobile number'), findsOneWidget);
      expect(find.byType(SheetFrame), findsOneWidget);
    });

    for (final width in [320.0, 390.0]) {
      testWidgets('fits ${width.toInt()}px in Filipino without overflow', (tester) async {
        await openChangePhone(tester, filipino: true, size: Size(width, 1600));

        expect(tester.takeException(), isNull);
        expect(find.text('Hakbang 1 sa 2'), findsOneWidget);
      });
    }
  });
}
