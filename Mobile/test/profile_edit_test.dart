// M13. The profile edit sheet writes to the server, through PATCH /me.
//
// The bug this replaced was not a missing feature — it was a sheet with three
// TextFields and a "Save changes" button that wrote to local Strings and
// reported success. So the assertions that matter here are about what actually
// leaves the device: which fields are sent, which are never sent, and that a
// rejection is shown rather than swallowed.
//
// The mobile number is the login, so it is not one of the fields: it is shown
// as a person writes it (09…), and moved by its own two-step flow — the new
// number and the current password, then the code texted to the new number.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/screens/change_phone_sheet.dart';
import 'package:serbis/screens/profile_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/shared_widgets.dart' show ConfirmDialog;

const _barangays = [
  {'barangay_id': 1, 'barangay_name': 'San Fabian'},
  {'barangay_id': 2, 'barangay_name': 'San Miguel'},
];

class _FakeApi extends ApiService {
  /// Every updateProfile call, in order, exactly as the screen sent it.
  final List<Map<String, dynamic>> calls = [];

  /// When set, updateProfile throws it instead of succeeding.
  ApiException? failWith;

  // --- the number change ---------------------------------------------------

  final List<Map<String, String>> phoneRequests = [];
  final List<String> phoneCodes = [];
  int phoneResends = 0;

  ApiException? requestFailWith;
  ApiException? verifyFailWith;
  ApiException? resendFailWith;

  VerificationDelivery requestDelivery = const VerificationDelivery(
    channel: 'sms',
    sentTo: '9999',
    retryAfter: 60,
  );

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
    String? streetAddress,
    bool? smsOptIn,
    int? barangayId,
  }) async {
    calls.add({
      'first_name': firstName,
      'middle_name': middleName,
      'last_name': lastName,
      'street_address': streetAddress,
      'sms_opt_in': smsOptIn,
      'barangay_id': barangayId,
    });

    final failure = failWith;
    if (failure != null) throw failure;

    final barangay = _barangays.firstWhere((b) => b['barangay_id'] == (barangayId ?? 1));
    return {
      'resident_id': 1,
      'first_name': firstName ?? 'Maria',
      'middle_name': middleName ?? '',
      'last_name': lastName ?? 'Santos',
      'phone_number': '+639171111111',
      'street_address': streetAddress ?? '',
      'sms_opt_in': smsOptIn ?? true,
      'barangay_id': barangay['barangay_id'],
      'barangay': barangay,
    };
  }

  @override
  Future<List<Map<String, dynamic>>> getBarangays() async => _barangays;

  @override
  Future<VerificationDelivery?> requestPhoneChange({
    required String phoneNumber,
    required String currentPassword,
  }) async {
    phoneRequests.add({'phone': phoneNumber, 'password': currentPassword});
    final failure = requestFailWith;
    if (failure != null) throw failure;
    return requestDelivery;
  }

  @override
  Future<VerificationDelivery?> resendPhoneChangeCode() async {
    phoneResends++;
    final failure = resendFailWith;
    if (failure != null) throw failure;
    return requestDelivery;
  }

  @override
  Future<Map<String, dynamic>> verifyPhoneChange({required String code}) async {
    phoneCodes.add(code);
    final failure = verifyFailWith;
    if (failure != null) throw failure;

    return {
      'resident_id': 1,
      'first_name': 'Maria',
      'last_name': 'Santos',
      'phone_number': '+639179999999',
      'barangay': {'barangay_name': 'San Fabian'},
    };
  }
}

const _resident = AppUser(
  id: '1',
  firstName: 'Maria',
  middleName: '',
  lastName: 'Santos',
  // As the server returns it.
  phone: '+639171111111',
  address: 'San Fabian',
  barangayId: 1,
);

Future<_FakeApi> _openSheet(
  WidgetTester tester, {
  void Function(AppUser)? onUserChanged,
  AppUser user = _resident,
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
        user: user,
        onUserChanged: onUserChanged ?? (_) {},
        onLogout: () {},
        onOpenNotifications: () {},
        onOpenProfile: () {},
      ),
    ),
  ));
  await tester.pumpAndSettle();

  await tester.tap(find.text('Edit my details'));
  await tester.pumpAndSettle();

  return api;
}

/// Text fields are positional in the sheet: first, middle, last, then the
/// barangay search and street/purok.
Finder _field(int index) => find.byType(TextField).at(index);

Finder get _street => _field(4);

/// Opens the barangay menu and picks [name] from it.
Future<void> _pickBarangay(WidgetTester tester, String name) async {
  await tester.ensureVisible(find.byType(DropdownMenu<String>));
  await tester.tap(find.byType(DropdownMenu<String>));
  await tester.pumpAndSettle();
  // `.last` is the entry in the open menu overlay.
  await tester.tap(find.text(name).last);
  await tester.pumpAndSettle();
}

Future<void> _save(WidgetTester tester) async {
  await tester.ensureVisible(find.text('Save changes'));
  await tester.tap(find.text('Save changes'));
  await tester.pumpAndSettle();
}

/// The TextFields of the number-change sheet only. The account sheet stays
/// under it in the tree, so a bare `find.byType(TextField)` would find both.
Finder _changeFields() => find.descendant(
      of: find.byType(ChangePhoneSheet),
      matching: find.byType(TextField),
    );

Future<void> _openChangePhone(WidgetTester tester) async {
  await tester.ensureVisible(find.text('Change'));
  await tester.tap(find.text('Change'));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('the sheet opens prefilled with what is on file', (tester) async {
    await _openSheet(tester);

    expect(find.widgetWithText(TextField, 'Maria'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Santos'), findsOneWidget);
    // The number is shown the way a person writes it, not as stored.
    expect(find.text('09171111111'), findsWidgets);
    expect(find.textContaining('+63917'), findsNothing);
  });

  testWidgets('there is no email field and no editable phone field',
      (tester) async {
    await _openSheet(tester);

    expect(find.text('Email address'), findsNothing);
    expect(find.widgetWithText(TextField, '09171111111'), findsNothing);
    expect(find.text('Change'), findsOneWidget);
  });

  testWidgets('only the changed fields are sent', (tester) async {
    final api = await _openSheet(tester);

    await tester.enterText(_field(0), 'Maria Clara');
    await tester.pump();
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();

    expect(api.calls, hasLength(1));
    expect(api.calls.single['first_name'], 'Maria Clara');
    // PATCH leaves an absent key alone.
    expect(api.calls.single['last_name'], isNull);
    // The SMS preference shares this endpoint but not this sheet. Sending it
    // from here would let a details save overwrite a choice the resident made
    // on the settings row.
    expect(api.calls.single['sms_opt_in'], isNull);
  });

  testWidgets('a successful save hands the refreshed profile back to the shell',
      (tester) async {
    AppUser? received;
    await _openSheet(tester, onUserChanged: (user) => received = user);

    await tester.enterText(_field(0), 'Maria Clara');
    await tester.pump();
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
    await tester.pump();
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();

    expect(find.text('Required'), findsOneWidget);
    // The point is the absence of a call: a round trip to be told a field is
    // blank is a slow answer on a rural connection.
    expect(api.calls, isEmpty);
  });

  testWidgets('save stays disabled until a field changes, and again once it is put back',
      (tester) async {
    final api = await _openSheet(tester);
    ElevatedButton save() => tester.widget<ElevatedButton>(
        find.ancestor(of: find.text('Save changes'), matching: find.byType(ElevatedButton)));

    expect(save().onPressed, isNull);
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();
    expect(api.calls, isEmpty);

    await tester.enterText(_street, 'Purok 3');
    await tester.pump();
    expect(save().onPressed, isNotNull);

    await tester.enterText(_street, '');
    await tester.pump();
    expect(save().onPressed, isNull);
  });

  testWidgets('the close button dismisses the sheet without saving', (tester) async {
    final api = await _openSheet(tester);

    await tester.enterText(_field(0), 'Maria Clara');
    await tester.tap(find.byTooltip('Close'));
    await tester.pumpAndSettle();

    expect(find.text('Edit my details'), findsOneWidget); // the card's button
    expect(find.byType(TextField), findsNothing);
    expect(api.calls, isEmpty);
  });

  testWidgets('a server rejection is shown in the sheet, not swallowed',
      (tester) async {
    final api = await _openSheet(tester);
    api.failWith = const ApiException(
      'The street address may not be greater than 255 characters.',
      statusCode: 422,
    );

    await tester.enterText(_street, 'Purok 3');
    await tester.pump();
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();

    // The sheet stays open with the message on it. Closing it and reporting
    // success is the exact failure this feature replaced.
    expect(
        find.text('The street address may not be greater than 255 characters.'),
        findsOneWidget);
    expect(find.text('Save changes'), findsOneWidget);
    expect(find.text('Profile updated.'), findsNothing);
  });

  testWidgets('the barangay is a picker, not a locked row', (tester) async {
    await _openSheet(tester);

    // First, middle, last, the barangay search and street/purok. The number
    // stays locked: it moves through its own two-step flow.
    expect(find.byType(TextField), findsNWidgets(5));
    expect(find.byType(DropdownMenu<String>), findsOneWidget);
    expect(find.widgetWithText(TextField, '09171111111'), findsNothing);
  });

  testWidgets('moving asks first, in green, and names both barangays', (tester) async {
    final api = await _openSheet(tester);

    await _pickBarangay(tester, 'San Miguel');
    await _save(tester);

    expect(find.byType(ConfirmDialog), findsOneWidget);
    expect(tester.widget<ConfirmDialog>(find.byType(ConfirmDialog)).destructive, isFalse);
    expect(
      find.text('Requests you already filed stay with San Fabian. New requests and MDRRMO texts go to San Miguel.'),
      findsOneWidget,
    );
    // Nothing leaves the device before the resident says yes.
    expect(api.calls, isEmpty);
  });

  testWidgets('going back from the dialog sends nothing and keeps the sheet', (tester) async {
    final api = await _openSheet(tester);

    await _pickBarangay(tester, 'San Miguel');
    await _save(tester);
    await tester.tap(find.text('Go back'));
    await tester.pumpAndSettle();

    expect(api.calls, isEmpty);
    expect(find.byType(DropdownMenu<String>), findsOneWidget);
  });

  testWidgets('confirming sends barangay_id and only that', (tester) async {
    AppUser? received;
    final api = await _openSheet(tester, onUserChanged: (u) => received = u);

    await _pickBarangay(tester, 'San Miguel');
    await _save(tester);
    await tester.tap(find.text('Change barangay'));
    await tester.pumpAndSettle();

    expect(api.calls, hasLength(1));
    expect(api.calls.single['barangay_id'], 2);
    expect(api.calls.single['first_name'], isNull);
    expect(received?.barangayId, 2);
    expect(received?.address, 'San Miguel');
  });

  testWidgets('a name change alone does not ask about the barangay', (tester) async {
    final api = await _openSheet(tester);

    await tester.enterText(_field(0), 'Maria Clara');
    await tester.pump();
    await _save(tester);

    expect(find.byType(ConfirmDialog), findsNothing);
    expect(api.calls.single['barangay_id'], isNull);
  });

  testWidgets('a barangay hall account keeps the barangay locked', (tester) async {
    await _openSheet(
      tester,
      user: const AppUser(
        id: '1',
        firstName: 'Maria',
        lastName: 'Santos',
        phone: '+639171111111',
        address: 'San Fabian',
        barangayId: 1,
        accountType: 'barangay',
      ),
    );

    expect(find.byType(DropdownMenu<String>), findsNothing);
    expect(find.byIcon(Icons.lock_outline_rounded), findsNWidgets(2));
  });

  // The number is the login and where every code goes, so moving it takes two
  // proofs, in two steps: the current password, and a code texted to the NEW
  // number. Nothing about the account changes until the second succeeds.
  group('changing the mobile number', () {
    testWidgets('opens its own sheet with a number field and a password field',
        (tester) async {
      await _openSheet(tester);
      await _openChangePhone(tester);

      expect(find.text('Change your mobile number'), findsOneWidget);
      expect(_changeFields(), findsNWidgets(2));
      expect(find.text('New mobile number'), findsOneWidget);
      expect(find.text('Current password'), findsOneWidget);
    });

    testWidgets('a blank number and a blank password stop before any request',
        (tester) async {
      final api = await _openSheet(tester);
      await _openChangePhone(tester);

      await tester.tap(find.text('Send code'));
      await tester.pumpAndSettle();

      expect(find.text('Required'), findsOneWidget);
      expect(find.text('Enter your current password to change your number.'),
          findsOneWidget);
      expect(api.phoneRequests, isEmpty);
    });

    testWidgets('a malformed number and your own number stop before any request',
        (tester) async {
      final api = await _openSheet(tester);
      await _openChangePhone(tester);

      await tester.enterText(_changeFields().at(1), 'password123');

      await tester.enterText(_changeFields().at(0), '0917');
      await tester.tap(find.text('Send code'));
      await tester.pumpAndSettle();
      expect(find.text('Enter a valid mobile number.'), findsOneWidget);

      // Typed in a different spelling: still the number already on the account.
      await tester.enterText(_changeFields().at(0), '+639171111111');
      await tester.tap(find.text('Send code'));
      await tester.pumpAndSettle();
      expect(find.text('That is already your number.'), findsOneWidget);

      expect(api.phoneRequests, isEmpty);
    });

    testWidgets('sends the new number and the password, then asks for the code',
        (tester) async {
      final api = await _openSheet(tester);
      await _openChangePhone(tester);

      await tester.enterText(_changeFields().at(0), '09179999999');
      await tester.enterText(_changeFields().at(1), 'password123');
      await tester.tap(find.text('Send code'));
      await tester.pumpAndSettle();

      expect(api.phoneRequests, [
        {'phone': '09179999999', 'password': 'password123'},
      ]);
      expect(find.text('Enter the code'), findsOneWidget);
      // Names where it went: the NEW number's last four digits.
      expect(find.textContaining('ending in 9999'), findsOneWidget);
      expect(find.byKey(const Key('delivery-unknown-hint')), findsNothing);
      // The account has not changed yet.
      expect(api.phoneCodes, isEmpty);
    });

    testWidgets('the right code changes the number and reports the save',
        (tester) async {
      AppUser? received;
      final api = await _openSheet(tester, onUserChanged: (u) => received = u);
      await _openChangePhone(tester);

      await tester.enterText(_changeFields().at(0), '09179999999');
      await tester.enterText(_changeFields().at(1), 'password123');
      await tester.tap(find.text('Send code'));
      await tester.pumpAndSettle();

      await tester.enterText(_changeFields().first, '123456');
      await tester.tap(find.text('Confirm new number'));
      await tester.pumpAndSettle();

      expect(api.phoneCodes, ['123456']);
      expect(received, isNotNull);
      expect(received!.phone, '+639179999999');
      expect(received!.phoneDisplay, '09179999999');
      expect(find.text('Profile updated.'), findsOneWidget);
      // Both sheets are gone.
      expect(find.byType(ChangePhoneSheet), findsNothing);
    });

    testWidgets('a short code is refused without calling the server',
        (tester) async {
      final api = await _openSheet(tester);
      await _openChangePhone(tester);

      await tester.enterText(_changeFields().at(0), '09179999999');
      await tester.enterText(_changeFields().at(1), 'password123');
      await tester.tap(find.text('Send code'));
      await tester.pumpAndSettle();

      await tester.enterText(_changeFields().first, '123');
      await tester.tap(find.text('Confirm new number'));
      await tester.pumpAndSettle();

      expect(find.text('Enter the 6-digit code.'), findsOneWidget);
      expect(api.phoneCodes, isEmpty);
    });

    testWidgets('a wrong password lands on the password field, not the banner',
        (tester) async {
      final api = await _openSheet(tester);
      api.requestFailWith = const ApiException(
        'Enter your current password to change your phone number.',
        statusCode: 422,
        fieldErrors: {
          'current_password':
              'Enter your current password to change your phone number.',
        },
      );
      await _openChangePhone(tester);

      await tester.enterText(_changeFields().at(0), '09179999999');
      await tester.enterText(_changeFields().at(1), 'wrong');
      await tester.tap(find.text('Send code'));
      await tester.pumpAndSettle();

      final password = tester.widget<TextField>(_changeFields().at(1));
      expect(password.decoration?.errorText,
          'Enter your current password to change your phone number.');
      // Shown once, under its field.
      expect(find.byKey(const Key('change-phone-error')), findsNothing);
      expect(find.text('Enter the code'), findsNothing);
    });

    testWidgets('a number another account holds is shown under the number field',
        (tester) async {
      final api = await _openSheet(tester);
      api.requestFailWith = const ApiException(
        'That number is already registered to another account.',
        statusCode: 422,
        fieldErrors: {
          'phone_number': 'That number is already registered to another account.',
        },
      );
      await _openChangePhone(tester);

      await tester.enterText(_changeFields().at(0), '09172222222');
      await tester.enterText(_changeFields().at(1), 'password123');
      await tester.tap(find.text('Send code'));
      await tester.pumpAndSettle();

      final phone = tester.widget<TextField>(_changeFields().at(0));
      expect(phone.decoration?.errorText,
          'That number is already registered to another account.');
    });

    testWidgets('when no text can be sent it says so in the app\'s own words',
        (tester) async {
      final api = await _openSheet(tester);
      api.requestFailWith = const ApiException(
        'A message in the server\'s English.',
        statusCode: 503,
        code: 'sms_unavailable',
      );
      await _openChangePhone(tester);

      await tester.enterText(_changeFields().at(0), '09179999999');
      await tester.enterText(_changeFields().at(1), 'password123');
      await tester.tap(find.text('Send code'));
      await tester.pumpAndSettle();

      expect(find.textContaining('could not send the text message'),
          findsOneWidget);
      expect(find.text('A message in the server\'s English.'), findsNothing);
      // Still on step one: nothing was sent, so nothing to enter.
      expect(find.text('Enter the code'), findsNothing);
    });

    testWidgets('a timed-out send opens the code step with the hint',
        (tester) async {
      final api = await _openSheet(tester);
      api.requestDelivery = const VerificationDelivery(
        channel: 'sms',
        sentTo: '9999',
        retryAfter: 60,
        unknown: true,
      );
      await _openChangePhone(tester);

      await tester.enterText(_changeFields().at(0), '09179999999');
      await tester.enterText(_changeFields().at(1), 'password123');
      await tester.tap(find.text('Send code'));
      await tester.pumpAndSettle();

      expect(find.text('Enter the code'), findsOneWidget);
      expect(find.byKey(const Key('delivery-unknown-hint')), findsOneWidget);
      expect(find.textContaining("Didn't get a text?"), findsOneWidget);
    });

    testWidgets('resend waits out the cooldown, then asks for a new code',
        (tester) async {
      final api = await _openSheet(tester);
      await _openChangePhone(tester);

      await tester.enterText(_changeFields().at(0), '09179999999');
      await tester.enterText(_changeFields().at(1), 'password123');
      await tester.tap(find.text('Send code'));
      await tester.pumpAndSettle();

      expect(find.textContaining('Resend code in'), findsOneWidget);
      await tester.tap(find.textContaining('Resend code in'));
      await tester.pump();
      expect(api.phoneResends, 0);

      await tester.pump(const Duration(seconds: 61));
      await tester.tap(find.text('Send a new code'));
      await tester.pumpAndSettle();

      expect(api.phoneResends, 1);
      expect(find.text('A new code is on its way.'), findsOneWidget);
    });

    testWidgets('too many wrong codes sends the resident back to step one',
        (tester) async {
      final api = await _openSheet(tester);
      api.verifyFailWith = const ApiException(
        'Too many wrong codes. Start the change again.',
        statusCode: 429,
        code: 'too_many_attempts',
      );
      await _openChangePhone(tester);

      await tester.enterText(_changeFields().at(0), '09179999999');
      await tester.enterText(_changeFields().at(1), 'password123');
      await tester.tap(find.text('Send code'));
      await tester.pumpAndSettle();

      await tester.enterText(_changeFields().first, '000000');
      await tester.tap(find.text('Confirm new number'));
      await tester.pumpAndSettle();

      // Back to the number and the password: the change is gone server-side, so
      // there is no code left to enter.
      expect(find.text('Change your mobile number'), findsOneWidget);
      expect(find.text('Too many wrong codes. Start the change again.'),
          findsOneWidget);
    });
  });
}
