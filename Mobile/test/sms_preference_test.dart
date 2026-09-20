// The SMS preference switch on the profile screen.
//
// Two switches used to sit here that wrote to local bools while the backend
// blasted every Active resident regardless — a resident could turn SMS "off"
// and still receive the paid text. So the assertions that matter are the ones
// about the wire: that the switch starts from the server's value, that flipping
// it actually sends sms_opt_in, and that a refusal leaves the control showing
// what the server still holds rather than what the resident tried to do.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/screens/profile_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';

class _FakeApi extends ApiService {
  final List<Map<String, dynamic>> calls = [];

  /// When set, updateProfile throws it instead of succeeding.
  ApiException? failWith;

  @override
  Future<List<Map<String, dynamic>>> getRequests() async =>
      <Map<String, dynamic>>[];

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async =>
      <Map<String, dynamic>>[];

  @override
  Future<List<int>?> fetchProfilePhoto(String residentId) async => null;

  @override
  Future<Map<String, dynamic>> updateProfile({
    String? firstName,
    String? middleName,
    String? lastName,
    String? phoneNumber,
    String? email,
    String? streetAddress,
    bool? smsOptIn,
    String? currentPassword,
  }) async {
    calls.add({
      'first_name': firstName,
      'middle_name': middleName,
      'last_name': lastName,
      'phone_number': phoneNumber,
      'email_address': email,
      'street_address': streetAddress,
      'sms_opt_in': smsOptIn,
      'current_password': currentPassword,
    });

    final failure = failWith;
    if (failure != null) throw failure;

    // Shaped like the real PATCH /me body: the server answers with the whole
    // resident, and the screen re-reads the preference from that rather than
    // from what it sent.
    return {
      'resident_id': 1,
      'first_name': 'Maria',
      'middle_name': '',
      'last_name': 'Santos',
      'phone_number': '09171111111',
      'email_address': 'maria@example.com',
      'sms_opt_in': smsOptIn ?? true,
      'barangay': {'barangay_name': 'San Fabian'},
    };
  }
}

AppUser _resident({bool smsOptIn = true}) => AppUser(
      id: '1',
      firstName: 'Maria',
      middleName: '',
      lastName: 'Santos',
      email: 'maria@example.com',
      phone: '09171111111',
      address: 'San Fabian',
      smsOptIn: smsOptIn,
    );

/// Holds the profile the way the app shell does, so onUserChanged actually
/// rebuilds the screen. Passing a const user would make every assertion about
/// what the row shows *after* a save meaningless.
class _Host extends StatefulWidget {
  final AppUser initial;
  final _FakeApi api;

  const _Host({required this.initial, required this.api});

  @override
  State<_Host> createState() => _HostState();
}

class _HostState extends State<_Host> {
  late AppUser _user = widget.initial;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      theme: buildAppTheme(),
      home: Scaffold(
        body: ProfileScreen(
          appState: AppState(widget.api),
          userStore: UserStore(widget.api),
          user: _user,
          onUserChanged: (updated) => setState(() => _user = updated),
          onLogout: () {},
          onOpenNotifications: () {},
          onOpenProfile: () {},
        ),
      ),
    );
  }
}

Future<_FakeApi> _open(WidgetTester tester, {bool smsOptIn = true}) async {
  // A phone-shaped viewport. The default 800x600 clips this screen and the
  // settings rows never build, so the switch goes unfound for the wrong reason.
  tester.view.physicalSize = const Size(1080, 3200);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  final api = _FakeApi();

  await tester.pumpWidget(_Host(initial: _resident(smsOptIn: smsOptIn), api: api));
  await tester.pumpAndSettle();

  return api;
}

Switch _switch(WidgetTester tester) => tester.widget<Switch>(find.byType(Switch));

void main() {
  testWidgets('the switch starts from the value the server sent', (tester) async {
    await _open(tester, smsOptIn: true);

    expect(_switch(tester).value, isTrue);
    expect(find.text('On — MDRRMO text blasts are sent to your number.'),
        findsOneWidget);
  });

  testWidgets('a resident who has opted out sees the switch off',
      (tester) async {
    await _open(tester, smsOptIn: false);

    expect(_switch(tester).value, isFalse);
    // The off copy says the whole truth: no blast at all. There is no exemption
    // in SmsController, so anything softer here would be a promise the backend
    // does not keep.
    expect(find.text('Off — you will not receive any MDRRMO text blast.'),
        findsOneWidget);
  });

  testWidgets('turning it off sends sms_opt_in false and nothing else',
      (tester) async {
    final api = await _open(tester, smsOptIn: true);

    await tester.tap(find.byType(Switch));
    await tester.pumpAndSettle();

    expect(api.calls, hasLength(1));
    expect(api.calls.single['sms_opt_in'], isFalse);
    // PATCH leaves an absent key alone. Re-sending the contact fields from a
    // settings toggle would put the unchanged email through the backend's
    // unique rule against the resident's own row.
    expect(api.calls.single['first_name'], isNull);
    expect(api.calls.single['email_address'], isNull);
    expect(api.calls.single['phone_number'], isNull);
  });

  testWidgets('turning it back on sends true', (tester) async {
    final api = await _open(tester, smsOptIn: false);

    await tester.tap(find.byType(Switch));
    await tester.pumpAndSettle();

    expect(api.calls.single['sms_opt_in'], isTrue);
  });

  testWidgets('the row itself toggles, not only the switch', (tester) async {
    final api = await _open(tester, smsOptIn: true);

    await tester.tap(find.text('MDRRMO text alerts'));
    await tester.pumpAndSettle();

    expect(api.calls.single['sms_opt_in'], isFalse);
  });

  testWidgets('a successful change is read back from the server response',
      (tester) async {
    await _open(tester, smsOptIn: true);

    await tester.tap(find.byType(Switch));
    await tester.pumpAndSettle();

    // The row now shows the refreshed profile the shell was handed, not the
    // value the screen optimistically set — there is no local copy to diverge.
    expect(_switch(tester).value, isFalse);
    expect(find.text('Off — you will not receive any MDRRMO text blast.'),
        findsOneWidget);
  });

  testWidgets('a rejected change shows the reason and leaves the switch alone',
      (tester) async {
    final api = await _open(tester, smsOptIn: true);
    api.failWith = const ApiException(
      'Your session expired. Please log in again.',
      statusCode: 401,
    );

    await tester.tap(find.byType(Switch));
    await tester.pumpAndSettle();

    expect(find.text('Your session expired. Please log in again.'),
        findsOneWidget);
    // Still on, because the server still has it on. Showing "off" after a
    // failed request is the old bug: a resident told they opted out while the
    // blast still reaches them.
    expect(_switch(tester).value, isTrue);
    expect(find.text('On — MDRRMO text blasts are sent to your number.'),
        findsOneWidget);
  });

  testWidgets('a failure leaves the control usable, not stuck spinning',
      (tester) async {
    final api = await _open(tester, smsOptIn: true);
    api.failWith = const ApiException('The server had a problem.', statusCode: 500);

    await tester.tap(find.byType(Switch));
    await tester.pumpAndSettle();

    // The busy flag is cleared in a finally, so the spinner is gone and the
    // switch is back.
    expect(find.byType(CircularProgressIndicator), findsNothing);
    expect(find.byType(Switch), findsOneWidget);

    // And the retry works: a second tap reaches the API rather than being
    // swallowed by a busy flag nobody reset.
    api.failWith = null;
    await tester.tap(find.byType(Switch));
    await tester.pumpAndSettle();

    expect(api.calls, hasLength(2));
    expect(_switch(tester).value, isFalse);
  });

  group('the PATCH /me body itself', () {
    // The widget tests above fake ApiService, which means they override the
    // method that decides the key names. These assert the real thing.
    test('the preference goes out under sms_opt_in, as a JSON boolean', () {
      final body = ApiService.buildProfileUpdateBody(smsOptIn: false);

      expect(body, {'sms_opt_in': false});
      expect(body['sms_opt_in'], isA<bool>());
    });

    test('an omitted preference is absent from the body, not sent as null', () {
      // PATCH: an absent key leaves the column alone. A null would be a
      // 'boolean' rule failure on a request that meant to change nothing.
      final body = ApiService.buildProfileUpdateBody(firstName: 'Maria');

      expect(body.containsKey('sms_opt_in'), isFalse);
      expect(body, {'first_name': 'Maria'});
    });
  });

  group('AppUser.sms_opt_in parsing', () {
    test('an explicit false is read as opted out', () {
      final user = AppUser.fromJson({
        'resident_id': 1,
        'first_name': 'Maria',
        'last_name': 'Santos',
        'sms_opt_in': false,
      });

      expect(user.smsOptIn, isFalse);
    });

    test('a missing key falls back to opted in, matching the column default',
        () {
      // A server that predates the migration, or any response that omits the
      // key. Reading absent as false would show an "off" switch to a resident
      // the blast still reaches.
      final user = AppUser.fromJson({
        'resident_id': 1,
        'first_name': 'Maria',
        'last_name': 'Santos',
      });

      expect(user.smsOptIn, isTrue);
    });
  });
}
