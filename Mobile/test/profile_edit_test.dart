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

    return {
      'resident_id': 1,
      'first_name': firstName ?? 'Maria',
      'middle_name': middleName ?? '',
      'last_name': lastName ?? 'Santos',
      'phone_number': phoneNumber ?? '09171111111',
      'email_address': email ?? 'maria@example.com',
      'street_address': streetAddress ?? '',
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

/// Fields are positional in the sheet: first, middle, last, street, phone,
/// email, and — only once the email or phone has been edited — the current
/// password at 6.
Finder _field(int index) => find.byType(TextField).at(index);

/// Types into the current-password field.
///
/// It does not exist until an edit to the email or phone brings it into the
/// tree, so the pump between the two is load-bearing: without it `_field(6)`
/// resolves against a six-field sheet and throws.
Future<void> _enterPassword(WidgetTester tester, String value) async {
  await tester.pump();
  await tester.enterText(_field(6), value);
}

void main() {
  testWidgets('the sheet opens prefilled with what is on file', (tester) async {
    await _openSheet(tester);

    expect(find.widgetWithText(TextField, 'Maria'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Santos'), findsOneWidget);
    expect(find.widgetWithText(TextField, '09171111111'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'maria@example.com'), findsOneWidget);
  });

  testWidgets('the phone field is digits-only and capped at 11', (tester) async {
    // `AppTextField.phone` carries the formatters. This sheet is the only place
    // left that renders one -- the request forms stopped asking for a callback
    // number once it came off the account -- so the rule is covered here or
    // nowhere.
    final api = await _openSheet(tester);

    await tester.enterText(_field(4), '0917-123-4567abc');
    // The number is a login-code destination, so moving it now needs the
    // password before the sheet will send anything.
    await _enterPassword(tester, 'password123');
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();

    expect(api.calls.single['phone_number'], '09171234567');
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

    await tester.enterText(_field(5), 'maria@');
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();

    expect(find.text('Enter a valid email address.'), findsOneWidget);
    expect(api.calls, isEmpty);
  });

  testWidgets('a short mobile number blocks the request', (tester) async {
    final api = await _openSheet(tester);

    await tester.enterText(_field(4), '0917');
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

    await tester.enterText(_field(5), 'taken@example.com');
    await _enterPassword(tester, 'password123');
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

    // Six fields: first, middle, last, street, phone, email. A seventh would
    // mean the barangay became writable — it is what every request is
    // dispatched on, and the endpoint refuses it, so a field here could only
    // ever fail. The current-password field is the one legitimate seventh,
    // and it is absent until a contact is edited — see the group below.
    expect(find.byType(TextField), findsNWidgets(6));
  });

  // The backend requires `current_password` to move `email_address` or
  // `phone_number` (18b587d) because those are where a login code is delivered.
  // Before this the sheet sent neither, so both edits 422'd with a message the
  // resident had no field to satisfy.
  group('the current-password field', () {
    testWidgets('is absent until the email or phone actually changes',
        (tester) async {
      await _openSheet(tester);

      expect(find.text('Current password'), findsNothing);

      // A name edit is not a credential change and must not ask for anything.
      await tester.enterText(_field(0), 'Maria Clara');
      await tester.pump();

      expect(find.text('Current password'), findsNothing);
      expect(find.byType(TextField), findsNWidgets(6));
    });

    testWidgets('appears when the email is edited and goes away when it is put back',
        (tester) async {
      await _openSheet(tester);

      await tester.enterText(_field(5), 'new@example.com');
      await tester.pump();

      expect(find.text('Current password'), findsOneWidget);
      expect(find.byType(TextField), findsNWidgets(7));

      // Undoing the edit takes the requirement away with it.
      await tester.enterText(_field(5), 'maria@example.com');
      await tester.pump();

      expect(find.text('Current password'), findsNothing);
      expect(find.byType(TextField), findsNWidgets(6));
    });

    testWidgets('appears when the phone is edited', (tester) async {
      await _openSheet(tester);

      await tester.enterText(_field(4), '09179999999');
      await tester.pump();

      expect(find.text('Current password'), findsOneWidget);
    });

    testWidgets('a blank password blocks the request entirely', (tester) async {
      final api = await _openSheet(tester);

      await tester.enterText(_field(5), 'new@example.com');
      await tester.pump();
      await tester.tap(find.text('Save changes'));
      await tester.pumpAndSettle();

      expect(find.text('Enter your current password to save this change.'),
          findsOneWidget);
      // The whole point: the 422 this replaces was a round trip that told the
      // resident something they could do nothing about.
      expect(api.calls, isEmpty);
    });

    testWidgets('is sent with a contact change', (tester) async {
      final api = await _openSheet(tester);

      await tester.enterText(_field(5), 'new@example.com');
      await _enterPassword(tester, 'password123');
      await tester.tap(find.text('Save changes'));
      await tester.pumpAndSettle();

      expect(api.calls.single['email_address'], 'new@example.com');
      expect(api.calls.single['current_password'], 'password123');
    });

    testWidgets('is never sent when only a name changed', (tester) async {
      final api = await _openSheet(tester);

      await tester.enterText(_field(0), 'Maria Clara');
      await tester.tap(find.text('Save changes'));
      await tester.pumpAndSettle();

      // Putting the password on the wire for a surname correction would be
      // sending a credential nothing asked for.
      expect(api.calls.single['current_password'], isNull);
    });

    testWidgets('a wrong password is reported under the field, not in the banner',
        (tester) async {
      final api = await _openSheet(tester);
      api.failWith = const ApiException(
        'Enter your current password to change the email address or phone number on this account.',
        statusCode: 422,
        fieldErrors: {
          'current_password':
              'Enter your current password to change the email address or phone number on this account.',
        },
      );

      await tester.enterText(_field(5), 'new@example.com');
      await _enterPassword(tester, 'wrong-password');
      await tester.tap(find.text('Save changes'));
      await tester.pumpAndSettle();

      // Read off the field's own decoration, not with find.text: the banner
      // renders the identical sentence, so a text finder passes whether the
      // message landed on the field or in the banner and proves neither.
      final password = tester.widget<TextField>(_field(6));
      expect(
        password.decoration?.errorText,
        'Enter your current password to change the email address or phone number on this account.',
      );

      // And shown once. Twice — field and banner — reads as two problems.
      expect(
        find.text(
            'Enter your current password to change the email address or phone number on this account.'),
        findsOneWidget,
      );
      expect(find.text('Profile updated.'), findsNothing);
      expect(find.text('Save changes'), findsOneWidget);
    });

    testWidgets('a 422 about another field still goes to the banner',
        (tester) async {
      final api = await _openSheet(tester);
      api.failWith = const ApiException(
        'The email address has already been taken.',
        statusCode: 422,
        fieldErrors: {
          'email_address': 'The email address has already been taken.',
        },
      );

      await tester.enterText(_field(5), 'taken@example.com');
      await _enterPassword(tester, 'password123');
      await tester.tap(find.text('Save changes'));
      await tester.pumpAndSettle();

      expect(find.text('The email address has already been taken.'),
          findsOneWidget);
    });
  });
}
