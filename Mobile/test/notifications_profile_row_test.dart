// The "Profile, notifications, account details" row of the design canvas:
// the bell sheet's 30-day New/Earlier list that opens Track, and the profile's
// text-alerts note and Contact MDRRMO row.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/screens/profile_screen.dart';
import 'package:serbis/screens/track_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/borrow_request_widgets.dart' show StatusBox;
import 'package:serbis/widgets/shared_widgets.dart';
import 'package:shared_preferences/shared_preferences.dart';

final _now = DateTime.utc(2026, 10, 3, 12);

ServiceRequest _request(int id, String name, DateTime updatedAt, {String status = 'Pending'}) =>
    ServiceRequest.fromJson(<String, dynamic>{
      'request_id': id,
      'service_id': 1,
      'status': status,
      'service': {'service_name': name, 'code': 'x-$id'},
      'created_at': updatedAt.subtract(const Duration(hours: 1)).toIso8601String(),
      'updated_at': updatedAt.toIso8601String(),
    });

Future<void> _pumpSheet(
  WidgetTester tester, {
  List<ServiceRequest> requests = const [],
  DateTime? seenAt,
  ValueChanged<ServiceRequest>? onOpenRequest,
}) async {
  tester.view.physicalSize = const Size(390, 1400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: NotificationsSheet(requests: requests, seenAt: seenAt, onOpenRequest: onOpenRequest, now: _now),
    ),
  ));
}

class _Api extends ApiService {
  List<Map<String, dynamic>> hotlines = [];

  @override
  Future<List<Map<String, dynamic>>> getRequests() async => [];

  @override
  Future<List<int>?> fetchProfilePhoto(String residentId) async => null;

  @override
  Future<List<Map<String, dynamic>>> getHotlines() async => hotlines;
}

Future<void> _pumpProfile(WidgetTester tester, {bool smsOptIn = true, AppState? state}) async {
  tester.view.physicalSize = const Size(390, 2000);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final appState = state ?? AppState(_Api());
  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: ProfileScreen(
        appState: appState,
        userStore: UserStore(_Api()),
        user: AppUser(id: '1', firstName: 'Maria', lastName: 'Santos', phone: '+639171111111', address: 'San Fabian', smsOptIn: smsOptIn),
        onUserChanged: (_) {},
        onLogout: () {},
        onOpenNotifications: () {},
        onOpenProfile: () {},
      ),
    ),
  ));
  await tester.pumpAndSettle();
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('the bell sheet', () {
    testWidgets('lists only requests that moved in the last 30 days', (tester) async {
      await _pumpSheet(tester, requests: [
        _request(1, 'Road clearing', _now.subtract(const Duration(days: 2))),
        _request(2, 'Tree cutting', _now.subtract(const Duration(days: 45))),
      ]);

      expect(find.text('Road clearing'), findsOneWidget);
      expect(find.text('Tree cutting'), findsNothing);
      expect(
        find.text('Requests from the last 30 days. Reminders like equipment due dates come as phone notifications.'),
        findsOneWidget,
      );
    });

    testWidgets('splits at the stored seen time into New, with a dot, and Earlier', (tester) async {
      final seen = _now.subtract(const Duration(days: 1));
      await _pumpSheet(tester, seenAt: seen, requests: [
        _request(1, 'Road clearing', _now.subtract(const Duration(hours: 3))),
        _request(2, 'Relief goods', _now.subtract(const Duration(days: 5)), status: 'Resolved'),
      ]);

      final newTop = tester.getTopLeft(find.text('New')).dy;
      final earlierTop = tester.getTopLeft(find.text('Earlier')).dy;
      expect(tester.getTopLeft(find.text('Road clearing')).dy, inExclusiveRange(newTop, earlierTop));
      expect(tester.getTopLeft(find.text('Relief goods')).dy, greaterThan(earlierTop));
      // One unread marker, on the new row only.
      expect(find.byWidgetPredicate((w) => w is Semantics && w.properties.label == 'Not yet seen'), findsOneWidget);
    });

    testWidgets('no advisories and no recent requests are each one muted line, not a box', (tester) async {
      await _pumpSheet(tester);

      expect(find.byType(StatusBox), findsNothing);
      expect(find.text('No advisories have been sent to you.'), findsOneWidget);
      expect(find.text('No updates on your requests in the last 30 days.'), findsOneWidget);
      expect(find.text('Advisories and updates on your requests'), findsOneWidget);
    });

    testWidgets('a row hands its request to Track', (tester) async {
      ServiceRequest? opened;
      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Builder(
          builder: (context) => Scaffold(
            body: TextButton(
              onPressed: () => showModalBottomSheet<void>(
                context: context,
                isScrollControlled: true,
                builder: (_) => NotificationsSheet(
                  requests: [_request(7, 'Road clearing', _now.subtract(const Duration(days: 1)))],
                  onOpenRequest: (r) => opened = r,
                  now: _now,
                ),
              ),
              child: const Text('open'),
            ),
          ),
        ),
      ));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Road clearing'));
      await tester.pumpAndSettle();

      expect(opened?.id, 7);
      // The sheet closed on the way.
      expect(find.byType(NotificationsSheet), findsNothing);
    });
  });

  group('Track, focused from the bell', () {
    testWidgets('unfolds older requests to reach the one asked for', (tester) async {
      tester.view.physicalSize = const Size(390, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);

      final state = AppState(_Api());
      state.requests.addAll([
        for (var i = 1; i <= 8; i++) _request(i, 'Done $i', _now.subtract(Duration(days: i)), status: 'Resolved'),
      ]);
      final focus = ValueNotifier<int?>(8);
      addTearDown(focus.dispose);

      await tester.pumpWidget(MaterialApp(
        theme: buildAppTheme(),
        home: Scaffold(
          body: TrackScreen(
            appState: state,
            onOpenNotifications: () {},
            onOpenProfile: () {},
            onOpenMyLoans: () {},
            onBrowseServices: () {},
            focusRequest: focus,
          ),
        ),
      ));
      await tester.pumpAndSettle();

      // The oldest one sits past the first five; it is built and on screen.
      expect(find.text('Done 8'), findsOneWidget);
      expect(tester.getTopLeft(find.text('Done 8')).dy, lessThan(900));
      expect(focus.value, isNull);
    });
  });

  group('the profile', () {
    testWidgets('an Off switch carries the amber note; On does not', (tester) async {
      await _pumpProfile(tester, smsOptIn: false);
      expect(find.text("You won't get flood or evacuation texts from MDRRMO."), findsOneWidget);

      await _pumpProfile(tester, smsOptIn: true);
      expect(find.text("You won't get flood or evacuation texts from MDRRMO."), findsNothing);
    });

    testWidgets('Contact MDRRMO shows with a number and hides without one', (tester) async {
      await _pumpProfile(tester);
      expect(find.text('Contact MDRRMO'), findsOneWidget);

      final api = _Api()..hotlines = [
        {
          'label': 'BFP',
          'numbers': [
            {'number': '(02) 426-3812'},
          ],
        },
      ];
      final state = AppState(api);
      await state.loadHotlines();
      await _pumpProfile(tester, state: state);
      expect(find.text('Contact MDRRMO'), findsNothing);
    });

    testWidgets('the card reads number · barangay and the button says Edit my details', (tester) async {
      await _pumpProfile(tester);

      expect(find.text('09171111111 · San Fabian'), findsOneWidget);
      expect(find.text('Edit my details'), findsOneWidget);
      expect(find.text('Account details'), findsNothing);
      final name = tester.widget<Text>(find.text('Maria Santos'));
      expect(name.style!.fontSize, AppTextSize.cardTitle);
    });
  });
}
