// M18. `login_screen.dart` and `register_screen.dart` were absent from lcov
// entirely — absent means zero, not unmeasured. They are the only two screens
// every resident meets before they can do anything else, and the register form
// is the one that decides whether a resident exists at all.
//
// What is worth pinning here is not that the fields draw. It is the refusals
// and the failure paths:
//
//  * client validation must refuse a bad form WITHOUT a round trip, because a
//    resident on barangay signal pays for every one of them;
//  * the register password rules must mirror `Password::min(8)->mixedCase()
//    ->numbers()` in AuthController — a client rule that is merely *different*
//    from the server's rejects valid passwords and accepts ones the server then
//    refuses, and the old rule did both;
//  * a failed barangay fetch must offer a retry, because `barangay_id` is a
//    required non-null FK and an empty picker means nobody can register at all;
//  * `UserStore.register` REPORTS a server rejection as a returned String and
//    `UserStore.login` THROWS one — two different shapes for the same event,
//    and each screen has to show it rather than swallow it;
//  * neither screen may fire a second request while the first is in flight.
//
// Nothing here touches the network: both screens reach the server only through
// `UserStore`, which is a thin wrapper over `ApiService`, so faking the service
// covers both halves.

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/screens/auth/login_screen.dart';
import 'package:serbis/screens/auth/register_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/form_inputs.dart';
import 'package:serbis/widgets/shared_widgets.dart';

// ---------------------------------------------------------------------------
// Fake
// ---------------------------------------------------------------------------

const List<Map<String, dynamic>> _defaultBarangays = [
  {'barangay_id': 1, 'barangay_name': 'San Fabian'},
  {'barangay_id': 2, 'barangay_name': 'Soyung'},
];

/// Records what each screen sent and answers with whatever the test needs.
///
/// Extends [ApiService] rather than implementing it so the untouched methods
/// keep their real bodies — none of them are reached by these two screens, and
/// a hand-written stub would go stale silently.
class _FakeAuthApi extends ApiService {
  _FakeAuthApi({List<Map<String, dynamic>>? barangays})
      : _barangays = barangays ?? _defaultBarangays;

  final List<Map<String, dynamic>> _barangays;

  int barangayCalls = 0;

  /// Fails the next barangay fetch. Cleared by a test to prove Retry works.
  bool barangaysThrow = false;

  int loginCalls = 0;
  String? lastLoginPhone;
  String? lastLoginPassword;

  /// Thrown by `residentLogin` when set.
  Object? loginError;

  /// Holds a login open mid-flight so the loading frame is observable.
  Future<void>? loginGate;

  int registerCalls = 0;
  Map<String, dynamic>? lastRegister;

  /// The message `register` RETURNS — a server rejection, not an exception.
  String? registerMessage;

  /// What a real 201 reports about the code it just sent. Fixed here because
  /// no test in this file cares how the send went — the ones that do live in
  /// verify_phone_screen_test.dart.
  static const registerDelivery = VerificationDelivery(
    channel: 'sms',
    sentTo: '4567',
    retryAfter: 60,
  );

  /// Thrown by `register` when set. A different path from [registerMessage].
  Object? registerError;

  @override
  Future<List<Map<String, dynamic>>> getBarangays() async {
    barangayCalls++;
    if (barangaysThrow) {
      throw const ApiException('Cannot reach the server.');
    }
    return _barangays;
  }

  @override
  Future<Map<String, dynamic>> residentLogin({
    required String phoneNumber,
    required String password,
  }) async {
    loginCalls++;
    lastLoginPhone = phoneNumber;
    lastLoginPassword = password;

    final gate = loginGate;
    if (gate != null) await gate;

    final failure = loginError;
    if (failure != null) throw failure;

    return {
      'resident_id': 31,
      'first_name': 'Maria',
      'last_name': 'Santos',
      'phone_number': phoneNumber,
      'barangay': {'barangay_name': 'San Fabian'},
    };
  }

  @override
  Future<RegisterOutcome> register({
    required String firstName,
    String? middleName,
    required String lastName,
    required int barangayId,
    String? streetAddress,
    required String phoneNumber,
    required String password,
    String accountType = 'head_of_family',
    String? organizationName,
  }) async {
    registerCalls++;
    lastRegister = {
      'first_name': firstName,
      'last_name': lastName,
      'barangay_id': barangayId,
      if (streetAddress != null && streetAddress.isNotEmpty)
        'street_address': streetAddress,
      'phone_number': phoneNumber,
      'password': password,
      'account_type': accountType,
      if (organizationName != null) 'organization_name': organizationName,
    };

    final failure = registerError;
    if (failure != null) throw failure;

    final message = registerMessage;

    return message == null
        ? const RegisterOutcome.sent(registerDelivery)
        : RegisterOutcome.failed(message);
  }
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

/// The `TextFormField` inside the [AuthTextField] carrying [label].
///
/// By label rather than by hint or by index: the hints are sentences that get
/// reworded, and an index silently retargets the moment a field is inserted.
Finder _fieldRoot(String label) => find.ancestor(
      of: find.text(label),
      matching: find.byType(AuthTextField),
    );

Finder _field(String label) => find.descendant(
      of: _fieldRoot(label),
      matching: find.byType(TextFormField),
    );

/// Asserts that [message] is showing as the validation error under the field
/// labelled [label].
///
/// One field has a hint that reads exactly like its own validator message —
/// 'Select your barangay'. On it, a bare `findsOneWidget` passes on the hint
/// alone with validation never having run, so the assertion has to be that
/// BOTH copies are present. Anywhere else one copy is the error and the only
/// copy. (Login's Password field was the second such case until its
/// label-restating hint was dropped.)
void _expectFieldError(String label, String message,
    {bool hintReadsTheSame = false}) {
  expect(
    find.descendant(of: _fieldRoot(label), matching: find.text(message)),
    hintReadsTheSame ? findsNWidgets(2) : findsOneWidget,
    reason: '"$message" should be showing under "$label"',
  );
}

Future<_FakeAuthApi> _pumpLogin(
  WidgetTester tester, {
  _FakeAuthApi? api,
  String? infoMessage,
  void Function(AppUser)? onLoginSuccess,
  VoidCallback? onGoToRegister,
  void Function(String phone, VerificationDelivery? delivery)?
      onPhoneUnverified,
  void Function(String phone, String challengeId, VerificationDelivery? delivery)?
      onMfaRequired,
  void Function(String phone)? onForgotPassword,
}) async {
  // A phone-shaped viewport, but WIDER than a real phone on purpose. The
  // default 800x600 clips these forms and the offscreen rows never build; 360
  // logical px — an honest phone width — then overflows the "Don't have an
  // account? Register" row, because flutter_test substitutes a fixed-width
  // placeholder font whose glyphs are far wider than Inter's. That overflow is
  // an artifact of the font, not a layout bug, and it makes taps on the clipped
  // half silently miss.
  tester.view.physicalSize = const Size(1440, 3200);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  final fake = api ?? _FakeAuthApi();

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: LoginScreen(
      userStore: UserStore(fake),
      onLoginSuccess: onLoginSuccess ?? (_) {},
      onGoToRegister: onGoToRegister ?? () {},
      onPhoneUnverified: onPhoneUnverified ?? (_, __) {},
      onMfaRequired: onMfaRequired ?? (_, __, ___) {},
      onForgotPassword: onForgotPassword ?? (_) {},
      infoMessage: infoMessage,
    ),
  ));
  await tester.pumpAndSettle();

  return fake;
}

Future<_FakeAuthApi> _pumpRegister(
  WidgetTester tester, {
  _FakeAuthApi? api,
  void Function(String phone, VerificationDelivery? delivery)?
      onRegisterSuccess,
  VoidCallback? onGoToLogin,
}) async {
  // Taller than the login screen: seven fields, a picker and a consent row.
  // Same width, for the same placeholder-font reason.
  tester.view.physicalSize = const Size(1440, 5400);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  final fake = api ?? _FakeAuthApi();

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: RegisterScreen(
      userStore: UserStore(fake),
      onRegisterSuccess: onRegisterSuccess ?? (_, __) {},
      onGoToLogin: onGoToLogin ?? () {},
    ),
  ));
  await tester.pumpAndSettle();

  return fake;
}

/// Fills every field on the register form with values the client accepts, so
/// each test only has to break the one thing it is about.
Future<void> _fillValidRegistration(
  WidgetTester tester, {
  String phone = '09171234567',
  String password = 'Pasada123',
  String? confirm,
  bool pickBarangay = true,
}) async {
  await tester.enterText(_field('First name'), 'Juan');
  await tester.enterText(_field('Last name'), 'Delacruz');
  await tester.enterText(_field('Mobile number'), phone);
  await tester.enterText(_field('Password'), password);
  await tester.enterText(_field('Confirm password'), confirm ?? password);

  if (pickBarangay) {
    await tester.tap(find.byType(DropdownButtonFormField<int>));
    await tester.pumpAndSettle();
    // `.last` is the entry in the open menu overlay; the closed button lays out
    // its own copy of every item to size itself.
    await tester.tap(find.text('San Fabian').last);
    await tester.pumpAndSettle();
  }
}

/// Ticks the data-privacy consent. Identified by the icon rather than the
/// sentence, which is long enough to get reworded without anyone touching the
/// behaviour.
Future<void> _agree(WidgetTester tester) async {
  await tester.tap(find.byIcon(Icons.check_box_outline_blank_rounded));
  await tester.pumpAndSettle();
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------

void main() {
  group('what the system is for', () {
    testWidgets('the login screen says what SERBIS is before asking to sign in',
        (tester) async {
      // Somebody handed this app at a barangay hall meets a login form for a
      // system nobody has described to them.
      await _pumpLogin(tester);

      expect(find.byType(ServicePurposeNote), findsOneWidget);
      expect(
        find.textContaining('coordination system'),
        findsWidgets,
      );
      expect(find.textContaining('MDRRMO Echague'), findsOneWidget);
    });

    testWidgets('the card is printed in both languages', (tester) async {
      // The language toggle lives on AppState, which does not exist before
      // login, so neither auth screen has a locale to read. Both languages are
      // printed rather than one guessed at.
      await _pumpLogin(tester);

      expect(
        find.text('Ang SERBIS ay isang coordination system kasama ang MDRRMO Echague.'),
        findsOneWidget,
      );
      expect(
        find.text('SERBIS is a coordination system run with the Echague MDRRMO.'),
        findsOneWidget,
      );
      expect(find.text('Humiling ng serbisyo sa MDRRMO'), findsOneWidget);
      expect(find.text('Mga gabay pangkaligtasan, offline'), findsOneWidget);
      expect(
        find.text('Tumanggap ng SMS announcements mula sa MDRRMO'),
        findsOneWidget,
      );
    });

    testWidgets('the card does not call SERBIS an emergency line', (tester) async {
      // SERBIS coordinates requests with the office; it does not put a
      // dispatcher on the other end of the button. The old copy said
      // "the disaster and emergency service line", which promised one.
      await _pumpLogin(tester);

      expect(find.textContaining('emergency service line'), findsNothing);
      expect(find.textContaining('Emergency'), findsNothing);
    });

    testWidgets('the register screen says the same thing', (tester) async {
      await _pumpRegister(tester);

      expect(find.byType(ServicePurposeNote), findsOneWidget);
      expect(
        find.text('Ang SERBIS ay isang coordination system kasama ang MDRRMO Echague.'),
        findsOneWidget,
      );
    });

    testWidgets('registering no longer promises a verification step that does not exist',
        (tester) async {
      // The old copy said "you will log in afterwards to verify your account".
      // There is no verify route, no code is ever sent, and the OTP columns it
      // referred to were dropped as dead schema. A resident reading it waits
      // for a screen that never comes.
      await _pumpRegister(tester);

      expect(find.textContaining('verify your account'), findsNothing);
    });
  });

  group('login validation', () {
    testWidgets('refuses an empty form without calling the server',
        (tester) async {
      final api = await _pumpLogin(tester);

      await tester.tap(find.text('Log in'));
      await tester.pumpAndSettle();

      _expectFieldError('Mobile number', 'Enter your mobile number');
      _expectFieldError('Password', 'Enter your password');
      // The whole point of client validation: no round trip.
      expect(api.loginCalls, 0);
    });

    testWidgets('refuses a number that is not a Philippine mobile number',
        (tester) async {
      final api = await _pumpLogin(tester);

      // The field only lets digits and + through, so what reaches the validator
      // is a number of the wrong shape, not text.
      for (final bad in ['0917123', '0288888888', '12345678901']) {
        await tester.enterText(_field('Mobile number'), bad);
        await tester.enterText(_field('Password'), 'whatever');
        await tester.tap(find.text('Log in'));
        await tester.pumpAndSettle();

        expect(find.text('Enter a valid mobile number'), findsOneWidget,
            reason: bad);
      }
      expect(api.loginCalls, 0);
    });

    testWidgets('keeps letters and punctuation out of the number field',
        (tester) async {
      await _pumpLogin(tester);

      await tester.enterText(_field('Mobile number'), '09a17-123 4567');

      final field = tester.widget<TextFormField>(_field('Mobile number'));
      expect(field.controller!.text, '091712345' '67');
    });
  });

  group('login', () {
    testWidgets('sends the number and hands back the mapped resident',
        (tester) async {
      AppUser? delivered;
      final api = await _pumpLogin(
        tester,
        onLoginSuccess: (user) => delivered = user,
      );

      await tester.enterText(_field('Mobile number'), '09171234567');
      await tester.enterText(_field('Password'), 'SitePhoto123');
      await tester.tap(find.text('Log in'));
      await tester.pumpAndSettle();

      expect(api.lastLoginPhone, '09171234567');
      expect(delivered, isNotNull);
      expect(delivered!.firstName, 'Maria');
      // `address` is the barangay relation — tbl_residents has no address
      // column, so this asserts the mapping, not just the round trip.
      expect(delivered!.address, 'San Fabian');
    });

    testWidgets('never trims the password', (tester) async {
      // A trimmed password is a login that fails against a server that did not
      // trim it, with a "wrong credentials" message that is a lie. The number is
      // trimmed on purpose; the password deliberately is not.
      final api = await _pumpLogin(tester);

      await tester.enterText(_field('Mobile number'), '09171234567');
      await tester.enterText(_field('Password'), ' spaced pass 1 ');
      await tester.tap(find.text('Log in'));
      await tester.pumpAndSettle();

      expect(api.lastLoginPassword, ' spaced pass 1 ');
    });

    testWidgets('shows a rejection on the form and stays put', (tester) async {
      var succeeded = false;
      final api = _FakeAuthApi()
        ..loginError =
            const ApiException('Invalid resident credentials.', statusCode: 401);

      await _pumpLogin(
        tester,
        api: api,
        onLoginSuccess: (_) => succeeded = true,
      );

      await tester.enterText(_field('Mobile number'), '09171234567');
      await tester.enterText(_field('Password'), 'wrong-password');
      await tester.tap(find.text('Log in'));
      await tester.pumpAndSettle();

      expect(find.text('Invalid resident credentials.'), findsOneWidget);
      expect(succeeded, isFalse);
      // Still usable: a screen stuck in its loading state after a bad password
      // is a resident who cannot try again.
      expect(find.text('Log in'), findsOneWidget);
    });

    testWidgets('an unverified account is routed to the code screen, not an error',
        (tester) async {
      // An abandoned registration: the password was right, the number was
      // never verified. Showing the message on this form would leave the
      // resident with nothing they can do about it, so the screen hands the
      // number up instead and the caller opens the verify screen.
      String? routedTo;
      var succeeded = false;
      final api = _FakeAuthApi()
        ..loginError = const ApiException(
          'Please enter the code we just sent to finish creating your account.',
          statusCode: 403,
          code: 'phone_unverified',
        );

      await _pumpLogin(
        tester,
        api: api,
        onLoginSuccess: (_) => succeeded = true,
        onPhoneUnverified: (phone, _) => routedTo = phone,
      );

      await tester.enterText(_field('Mobile number'), '09171234567');
      await tester.enterText(_field('Password'), 'Password123');
      await tester.tap(find.text('Log in'));
      await tester.pumpAndSettle();

      expect(routedTo, '09171234567');
      expect(succeeded, isFalse);
      expect(
        find.textContaining('enter the code we just sent'),
        findsNothing,
        reason: 'the refusal is routing, not an error to read',
      );
    });

    testWidgets('falls back to a connection message on a non-API failure',
        (tester) async {
      // Anything that is not an ApiException got past the HTTP layer's own
      // normalisation, so the raw error is never resident-readable.
      final api = _FakeAuthApi()..loginError = StateError('bad state');

      await _pumpLogin(tester, api: api);

      await tester.enterText(_field('Mobile number'), '09171234567');
      await tester.enterText(_field('Password'), 'SitePhoto123');
      await tester.tap(find.text('Log in'));
      await tester.pumpAndSettle();

      expect(find.text('Cannot connect to server. Check your connection.'),
          findsOneWidget);
      expect(find.text('bad state'), findsNothing);
    });

    testWidgets('does not fire a second login while one is in flight',
        (tester) async {
      final gate = Completer<void>();
      final api = _FakeAuthApi()..loginGate = gate.future;

      await _pumpLogin(tester, api: api);

      await tester.enterText(_field('Mobile number'), '09171234567');
      await tester.enterText(_field('Password'), 'SitePhoto123');
      await tester.tap(find.text('Log in'));
      await tester.pump();

      // The label is replaced by a spinner mid-flight, so the button is found
      // by type rather than by text.
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      await tester.tap(find.byType(ElevatedButton));
      await tester.pump();
      expect(api.loginCalls, 1);

      gate.complete();
      await tester.pumpAndSettle();
    });

    testWidgets('"Forgot password?" opens the reset flow with the number typed so far',
        (tester) async {
      String? carried;
      await _pumpLogin(tester, onForgotPassword: (phone) => carried = phone);

      await tester.enterText(_field('Mobile number'), '09171234567');
      await tester.tap(find.text('Forgot password?'));
      await tester.pumpAndSettle();

      expect(carried, '09171234567');
      // The old placeholder said to contact MDRRMO; the flow is real now.
      expect(find.text('Contact MDRRMO to reset your password.'), findsNothing);
    });

    testWidgets('an old app is told to update and cannot retry', (tester) async {
      final api = _FakeAuthApi()
        ..loginError = const ApiException(
          'Please update the SERBIS app to continue. / Paki-update ang SERBIS app para magpatuloy.',
          statusCode: 410,
          code: 'app_update_required',
        );

      await _pumpLogin(tester, api: api);

      await tester.enterText(_field('Mobile number'), '09171234567');
      await tester.enterText(_field('Password'), 'Password123');
      await tester.tap(find.text('Log in'));
      await tester.pumpAndSettle();

      // Shown as a block, not a retryable form error, and both languages arrive
      // in the server's one string.
      expect(find.textContaining('Paki-update ang SERBIS app'), findsOneWidget);
      expect(find.textContaining('Please update the SERBIS app'), findsOneWidget);
      final button = tester.widget<ElevatedButton>(
        find.ancestor(of: find.text('Log in'), matching: find.byType(ElevatedButton)),
      );
      expect(button.onPressed, isNull);
    });

    testWidgets('a login that could not be texted shows the server\'s sentence',
        (tester) async {
      final api = _FakeAuthApi()
        ..loginError = const ApiException(
          'We could not send the text message. Check the number and try again in a minute, or visit the MDRRMO office.',
          statusCode: 503,
          code: 'sms_unavailable',
        );
      var routed = false;

      await _pumpLogin(tester, api: api, onMfaRequired: (_, __, ___) => routed = true);

      await tester.enterText(_field('Mobile number'), '09171234567');
      await tester.enterText(_field('Password'), 'Password123');
      await tester.tap(find.text('Log in'));
      await tester.pumpAndSettle();

      expect(find.textContaining('could not send the text message'), findsOneWidget);
      expect(routed, isFalse);
      // Retryable: nothing the resident typed was wrong.
      expect(find.text('Log in'), findsOneWidget);
    });

    testWidgets('a login that reached its code prompt hands the number and challenge up',
        (tester) async {
      String? phone;
      String? challenge;
      final api = _FakeAuthApi()
        ..loginError = const ApiException(
          'Enter the code we just sent to finish signing in.',
          statusCode: 403,
          code: 'mfa_required',
          challengeId: 'chal-1',
        );

      await _pumpLogin(
        tester,
        api: api,
        onMfaRequired: (p, c, _) {
          phone = p;
          challenge = c;
        },
      );

      await tester.enterText(_field('Mobile number'), '09171234567');
      await tester.enterText(_field('Password'), 'Password123');
      await tester.tap(find.text('Log in'));
      await tester.pumpAndSettle();

      expect(phone, '09171234567');
      expect(challenge, 'chal-1');
    });

    testWidgets('shows the hand-off message from a completed registration',
        (tester) async {
      await _pumpLogin(
        tester,
        infoMessage: 'Account created. Please log in.',
      );

      expect(find.text('Account created. Please log in.'), findsOneWidget);
    });

    testWidgets('the Register link leaves the screen', (tester) async {
      var asked = false;
      await _pumpLogin(tester, onGoToRegister: () => asked = true);

      await tester.tap(find.text('Register'));
      await tester.pumpAndSettle();

      expect(asked, isTrue);
    });
  });

  // -------------------------------------------------------------------------
  // Register
  // -------------------------------------------------------------------------

  group('the barangay picker', () {
    testWidgets('loads its options on open', (tester) async {
      final api = await _pumpRegister(tester);

      expect(api.barangayCalls, 1);
      expect(find.byType(DropdownButtonFormField<int>), findsOneWidget);
      expect(find.text("Couldn't load barangays."), findsNothing);
    });

    testWidgets('offers a retry when the fetch fails, and recovers',
        (tester) async {
      // `barangay_id` is a required non-null FK, so an unloadable picker blocks
      // registration outright — the highest-stakes silent failure in the app.
      final api = _FakeAuthApi()..barangaysThrow = true;
      await _pumpRegister(tester, api: api);

      expect(find.text("Couldn't load barangays."), findsOneWidget);
      expect(find.byType(DropdownButtonFormField<int>), findsNothing);

      api.barangaysThrow = false;
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();

      expect(api.barangayCalls, 2);
      expect(find.byType(DropdownButtonFormField<int>), findsOneWidget);
      expect(find.text("Couldn't load barangays."), findsNothing);
    });

    testWidgets('refuses a submit that has no barangay to send',
        (tester) async {
      // Reachable only on the failed-fetch path: with the dropdown rendered its
      // own validator answers first. Without it there is no validator at all,
      // so this guard is the only thing standing between the resident and a 422
      // on a required FK.
      final api = _FakeAuthApi()..barangaysThrow = true;
      await _pumpRegister(tester, api: api);

      await _fillValidRegistration(tester, pickBarangay: false);
      await _agree(tester);
      await tester.tap(find.text('Create account'));
      await tester.pumpAndSettle();

      expect(find.text('Select your barangay.'), findsOneWidget);
      expect(api.registerCalls, 0);
    });
  });

  group('registering as an organization', () {
    testWidgets('an individual is the default and sends head_of_family', (tester) async {
      final api = await _pumpRegister(tester);

      expect(find.text('Organization name'), findsNothing);

      await _fillValidRegistration(tester);
      await _agree(tester);
      await tester.tap(find.text('Create account'));
      await tester.pumpAndSettle();

      expect(api.lastRegister!['account_type'], 'head_of_family');
      expect(api.lastRegister!.containsKey('organization_name'), isFalse);
    });

    testWidgets('offers Individual and Organization and nothing else', (tester) async {
      await _pumpRegister(tester);

      final choice = find.byType(SegmentedButton<bool>);

      expect(find.descendant(of: choice, matching: find.text('Individual')), findsOneWidget);
      expect(find.descendant(of: choice, matching: find.text('Organization')), findsOneWidget);
      expect(find.descendant(of: choice, matching: find.text('Barangay')), findsNothing);
    });

    testWidgets('an organization asks for its name and the names become the contact person',
        (tester) async {
      await _pumpRegister(tester);

      await tester.tap(find.text('Organization'));
      await tester.pumpAndSettle();

      expect(find.text('Organization name'), findsOneWidget);
      expect(find.text('Contact first name'), findsOneWidget);
      expect(find.text('Contact last name'), findsOneWidget);
      expect(find.text('First name'), findsNothing);
    });

    testWidgets('an organization with no name is refused before it is sent', (tester) async {
      final api = await _pumpRegister(tester);

      await tester.tap(find.text('Organization'));
      await tester.pumpAndSettle();
      await tester.enterText(_field('Contact first name'), 'Ian');
      await tester.enterText(_field('Contact last name'), 'Uy');
      await tester.enterText(_field('Mobile number'), '09171234567');
      await tester.enterText(_field('Password'), 'Pasada123');
      await tester.enterText(_field('Confirm password'), 'Pasada123');
      await tester.tap(find.byType(DropdownButtonFormField<int>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('San Fabian').last);
      await tester.pumpAndSettle();
      await _agree(tester);
      await tester.tap(find.text('Create account'));
      await tester.pumpAndSettle();

      expect(find.text('Enter the organization name'), findsOneWidget);
      expect(api.registerCalls, 0);
    });

    testWidgets('a filled organization form sends organization and its name', (tester) async {
      final api = await _pumpRegister(tester);

      await tester.tap(find.text('Organization'));
      await tester.pumpAndSettle();
      await tester.enterText(_field('Organization name'), 'Isabela State University');
      await tester.enterText(_field('Contact first name'), 'Ian');
      await tester.enterText(_field('Contact last name'), 'Uy');
      await tester.enterText(_field('Mobile number'), '09171234567');
      await tester.enterText(_field('Password'), 'Pasada123');
      await tester.enterText(_field('Confirm password'), 'Pasada123');
      await tester.tap(find.byType(DropdownButtonFormField<int>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('San Fabian').last);
      await tester.pumpAndSettle();
      await _agree(tester);
      await tester.tap(find.text('Create account'));
      await tester.pumpAndSettle();

      expect(api.registerCalls, 1);
      expect(api.lastRegister!['account_type'], 'organization');
      expect(api.lastRegister!['organization_name'], 'Isabela State University');
      expect(api.lastRegister!['first_name'], 'Ian');
    });
  });

  group('register validation', () {
    testWidgets('refuses an empty form field by field', (tester) async {
      final api = await _pumpRegister(tester);

      await tester.tap(find.text('Create account'));
      await tester.pumpAndSettle();

      _expectFieldError('First name', 'Enter your first name');
      _expectFieldError('Last name', 'Enter your last name');
      _expectFieldError('Mobile number', 'Enter your mobile number');
      _expectFieldError('Password', 'Enter a password');
      _expectFieldError('Confirm password', 'Confirm your password');
      // The picker is not an AuthTextField, so it is asserted on directly. Its
      // hint reads the same as its validator message, hence two copies.
      expect(find.text('Select your barangay'), findsNWidgets(2));
      expect(api.registerCalls, 0);
    });

    // AuthController runs `Password::min(8)->mixedCase()->numbers()`. Each case
    // is a password the SERVER would refuse; a client that let one through
    // spends a round trip to say so, and the rule this replaced did worse than
    // that — it demanded a symbol the server never asked for.
    //
    // One test per case rather than a loop inside one: `pumpWidget` twice in a
    // single test UPDATES the existing element instead of building a new one,
    // so `RegisterScreen`'s State survives and the consent tick carries into
    // the next case.
    const passwordRule = <String, String>{
      'Pas12': 'Password must be at least 8 characters',
      'pasada123': 'Include at least one uppercase letter (A-Z)',
      'PASADA123': 'Include at least one lowercase letter (a-z)',
      'PasadaPass': 'Include at least one number (0-9)',
    };

    passwordRule.forEach((password, message) {
      testWidgets('refuses "$password" the way the server would',
          (tester) async {
        final api = await _pumpRegister(tester);

        await _fillValidRegistration(tester, password: password);
        await _agree(tester);
        await tester.tap(find.text('Create account'));
        await tester.pumpAndSettle();

        _expectFieldError('Password', message);
        expect(api.registerCalls, 0, reason: '"$password" reached the server');
      });
    });

    testWidgets('accepts a password the server would accept', (tester) async {
      // The other half of the rule, and the one that catches a client rule
      // grown stricter than the server's: eight characters, mixed case, a
      // number, no symbol.
      final api = await _pumpRegister(tester);

      await _fillValidRegistration(tester, password: 'Pasada12');
      await _agree(tester);
      await tester.tap(find.text('Create account'));
      await tester.pumpAndSettle();

      expect(api.registerCalls, 1);
    });

    testWidgets('refuses a confirmation that does not match', (tester) async {
      final api = await _pumpRegister(tester);

      await _fillValidRegistration(
        tester,
        password: 'Pasada123',
        confirm: 'Pasada124',
      );
      await _agree(tester);
      await tester.tap(find.text('Create account'));
      await tester.pumpAndSettle();

      expect(find.text('Passwords do not match'), findsOneWidget);
      expect(api.registerCalls, 0);
    });

    testWidgets('refuses a mobile number that is not one', (tester) async {
      final api = await _pumpRegister(tester);

      await _fillValidRegistration(tester, phone: '0917');
      await _agree(tester);
      await tester.tap(find.text('Create account'));
      await tester.pumpAndSettle();

      expect(find.text('Enter a valid mobile number'), findsOneWidget);
      expect(api.registerCalls, 0);
    });

    testWidgets('refuses to register anyone who has not agreed',
        (tester) async {
      // Consent is not a form field, so `validate()` cannot speak for it — an
      // untested guard here means a resident's data leaves the device without
      // the notice ever having been accepted.
      final api = await _pumpRegister(tester);

      await _fillValidRegistration(tester);
      await tester.tap(find.text('Create account'));
      await tester.pumpAndSettle();

      expect(
          find.text('Please agree to the data privacy notice to continue.'),
          findsOneWidget);
      expect(api.registerCalls, 0);
    });
  });

  group('register', () {
    testWidgets('sends trimmed values and the chosen barangay id',
        (tester) async {
      var succeeded = false;
      final api = await _pumpRegister(
        tester,
        onRegisterSuccess: (_, __) => succeeded = true,
      );

      await _fillValidRegistration(tester);
      await _agree(tester);
      await tester.tap(find.text('Create account'));
      await tester.pumpAndSettle();

      expect(succeeded, isTrue);
      expect(api.lastRegister, {
        'first_name': 'Juan',
        'last_name': 'Delacruz',
        // The id, not the name: the resident row takes an FK.
        'barangay_id': 1,
        'phone_number': '09171234567',
        'password': 'Pasada123',
        // The default: an individual, the head of a household.
        'account_type': 'head_of_family',
      });
    });

    testWidgets('sends the street address when filled in, optional otherwise',
        (tester) async {
      final api = await _pumpRegister(tester);

      await _fillValidRegistration(tester);
      // Not an AuthTextField like the fields above — PurokAutocompleteField
      // wraps a plain AppTextField/TextField so RawAutocomplete can drive its
      // focus, so this is found by label rather than through `_field()`.
      final streetField = find.descendant(
        of: find.byWidgetPredicate(
            (w) => w is AppTextField && w.label == 'Street / Purok (optional)'),
        matching: find.byType(TextField),
      );
      await tester.enterText(streetField, '  Purok 3  ');
      await _agree(tester);
      await tester.tap(find.text('Create account'));
      await tester.pumpAndSettle();

      expect(api.registerCalls, 1);
      expect(api.lastRegister!['street_address'], 'Purok 3');
    });

    testWidgets('ticking the consent box is visible, not just recorded',
        (tester) async {
      await _pumpRegister(tester);

      expect(find.byIcon(Icons.check_box_rounded), findsNothing);
      await _agree(tester);
      expect(find.byIcon(Icons.check_box_rounded), findsOneWidget);
      expect(find.byIcon(Icons.check_box_outline_blank_rounded), findsNothing);
    });

    testWidgets('shows a returned server rejection on the form',
        (tester) async {
      // `UserStore.register` converts an ApiException into a RETURNED message
      // rather than throwing it — the opposite of login. A screen that only
      // handled the throw would show nothing at all here and then call
      // onRegisterSuccess for an account that was never created.
      var succeeded = false;
      final api = _FakeAuthApi()
        ..registerMessage = 'That number is already registered to an account.';

      await _pumpRegister(
        tester,
        api: api,
        onRegisterSuccess: (_, __) => succeeded = true,
      );

      await _fillValidRegistration(tester);
      await _agree(tester);
      await tester.tap(find.text('Create account'));
      await tester.pumpAndSettle();

      expect(find.text('That number is already registered to an account.'),
          findsOneWidget);
      expect(succeeded, isFalse);
      // The form is still there with the values in it — a taken number is one
      // field to change, not a form to retype.
      expect(find.text('Create account'), findsOneWidget);
    });

    testWidgets('falls back to a connection message on a thrown failure',
        (tester) async {
      var succeeded = false;
      final api = _FakeAuthApi()..registerError = StateError('bad state');

      await _pumpRegister(
        tester,
        api: api,
        onRegisterSuccess: (_, __) => succeeded = true,
      );

      await _fillValidRegistration(tester);
      await _agree(tester);
      await tester.tap(find.text('Create account'));
      await tester.pumpAndSettle();

      expect(find.text('Cannot connect to server. Check your connection.'),
          findsOneWidget);
      expect(find.text('bad state'), findsNothing);
      expect(succeeded, isFalse);
    });

    testWidgets('the Log in link leaves the screen', (tester) async {
      var asked = false;
      await _pumpRegister(tester, onGoToLogin: () => asked = true);

      await tester.tap(find.text('Log in'));
      await tester.pumpAndSettle();

      expect(asked, isTrue);
    });
  });
}
