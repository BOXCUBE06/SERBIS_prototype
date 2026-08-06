// M13. The profile edit sheet writes to the server now, through PATCH /me.
//
// The bug this replaces was not a missing feature — it was a sheet with three
// TextFields and a "Save changes" button that wrote to local Strings and
// reported success. So the assertions that matter here are about what actually
// leaves the device: which fields are sent, which are never sent, and that a
// rejection is shown rather than swallowed.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/screens/profile_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';

class _FakeApi extends ApiService {
  /// Every updateProfile call, in order, exactly as the screen sent it.
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
    bool? smsOptIn,
  }) async {
    calls.add({
      'first_name': firstName,
      'middle_name': middleName,
      'last_name': lastName,
      'phone_number': phoneNumber,
      'email_address': email,
      'sms_opt_in': smsOptIn,
    });

    final failure = failWith;
    if (failure != null) throw failure;

    return {
      'resident_id': 1,
      'first_name': firstName ?? 'Maria',
      'middle_name': middleName ?? '',
      'last_name': lastName ?? 'Santos',
      'phone_number': phoneNumber ?? '09171111111',
      'email_address': email ?? 'maria@example.com',
      'sms_opt_in': smsOptIn ?? true,
      'barangay': {'barangay_name': 'San Fabian'},
    };
  }
}

const _resident = AppUser(
  id: '1',
  firstName: 'Maria',
  middleName: '',
  lastName: 'Santos',
  email: 'maria@example.com',
  phone: '09171111111',
  address: 'San Fabian',
);

Future<_FakeApi> _openSheet(
  WidgetTester tester, {
  void Function(AppUser)? onUserChanged,
}) async {
  // A phone-shaped viewport. The default 800x600 clips this screen and the
  // offscreen rows never build, so a field under test goes unfound for the
  // wrong reason.
  tester.view.physicalSize = const Size(1080, 3200);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  final api = _FakeApi();

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: Scaffold(
      body: ProfileScreen(
        appState: AppState(api),
        userStore: UserStore(api),
        user: _resident,
        onUserChanged: onUserChanged ?? (_) {},
        onLogout: () {},
        onOpenNotifications: () {},
        onOpenProfile: () {},
      ),
    ),
  ));
  await tester.pumpAndSettle();

  await tester.tap(find.text('Account details'));
  await tester.pumpAndSettle();

  return api;
}

/// Fields are positional in the sheet: first, middle, last, phone, email.
Finder _field(int index) => find.byType(TextField).at(index);

void main() {
  testWidgets('the sheet opens prefilled with what is on file', (tester) async {
    await _openSheet(tester);

    expect(find.widgetWithText(TextField, 'Maria'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Santos'), findsOneWidget);
    expect(find.widgetWithText(TextField, '09171111111'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'maria@example.com'), findsOneWidget);
  });

  testWidgets('only the changed fields are sent', (tester) async {
    final api = await _openSheet(tester);

    await tester.enterText(_field(0), 'Maria Clara');
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();

    expect(api.calls, hasLength(1));
    expect(api.calls.single['first_name'], 'Maria Clara');
    // PATCH leaves an absent key alone. Re-sending the unchanged email would
    // put it through the backend's unique rule against the resident's own row.
    expect(api.calls.single['email_address'], isNull);
    expect(api.calls.single['last_name'], isNull);
    expect(api.calls.single['phone_number'], isNull);
    // The SMS preference shares this endpoint but not this sheet. Sending it
    // from here would let a contact-details save overwrite a choice the
    // resident made on the settings row.
    expect(api.calls.single['sms_opt_in'], isNull);
  });

  testWidgets('a successful save hands the refreshed profile back to the shell',
      (tester) async {
    AppUser? received;
    await _openSheet(tester, onUserChanged: (user) => received = user);

    await tester.enterText(_field(0), 'Maria Clara');
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();

    // Without this the shell's copy goes stale behind the screen and the name
    // reverts on the next rebuild — which is what "saved" looking fake means.
    expect(received, isNotNull);
    expect(received!.firstName, 'Maria Clara');
    expect(find.text('Profile updated.'), findsOneWidget);
  });

  testWidgets('a blank required field blocks the request entirely',
      (tester) async {
    final api = await _openSheet(tester);

    await tester.enterText(_field(0), '');
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();

    expect(find.text('Required'), findsOneWidget);
    // The point is the absence of a call: a round trip to be told a field is
    // blank is a slow answer on a rural connection.
    expect(api.calls, isEmpty);
  });

  testWidgets('a malformed email blocks the request', (tester) async {
    final api = await _openSheet(tester);

    await tester.enterText(_field(4), 'maria@');
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();

    expect(find.text('Enter a valid email address.'), findsOneWidget);
    expect(api.calls, isEmpty);
  });

  testWidgets('a short mobile number blocks the request', (tester) async {
    final api = await _openSheet(tester);

    await tester.enterText(_field(3), '0917');
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();

    expect(find.text('Enter a valid mobile number.'), findsOneWidget);
    expect(api.calls, isEmpty);
  });

  testWidgets('saving with nothing changed does not call the server',
      (tester) async {
    final api = await _openSheet(tester);

    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();

    expect(find.text('Nothing to save.'), findsOneWidget);
    expect(api.calls, isEmpty);
  });

  testWidgets('a server rejection is shown in the sheet, not swallowed',
      (tester) async {
    final api = await _openSheet(tester);
    api.failWith = const ApiException(
      'The email address has already been taken.',
      statusCode: 422,
    );

    await tester.enterText(_field(4), 'taken@example.com');
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();

    // The sheet stays open with the message on it. Closing it and reporting
    // success is the exact failure this feature replaced.
    expect(find.text('The email address has already been taken.'),
        findsOneWidget);
    expect(find.text('Save changes'), findsOneWidget);
    expect(find.text('Profile updated.'), findsNothing);
  });

  testWidgets('the barangay cannot be edited from here', (tester) async {
    await _openSheet(tester);

    // Five fields: first, middle, last, phone, email. A sixth would mean the
    // barangay became writable — it is what every request is dispatched on, and
    // the endpoint refuses it, so a field here could only ever fail.
    expect(find.byType(TextField), findsNWidgets(5));
  });
}
