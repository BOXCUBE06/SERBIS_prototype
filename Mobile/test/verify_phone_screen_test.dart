// The code screen is the second half of registration: an account does not exist
// until the code texted to the number comes back. What is worth pinning is not
// that the field draws, but the refusals and the routing:
//
//  * a short code must be refused WITHOUT a round trip — the verify route is
//    rate-limited at five a minute, and a wasted attempt is one a resident who
//    mistyped cannot get back;
//  * a server rejection must land on the form, not be swallowed;
//  * verifying hands back a signed-in resident, so the caller navigates into
//    the app rather than to a login form;
//  * resend is disabled during the cooldown, because the server answers 429
//    inside it and the resident would be tapping into a refusal;
//  * the screen says where the code went (the number's last four digits — there
//    is no email any more), and when the server reports that the send timed out
//    it says the text may be late and what to do about it.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/screens/auth/verify_phone_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/theme/app_theme.dart';

class _FakeVerifyApi extends ApiService {
  int verifyCalls = 0;
  String? lastCode;
  String? lastPhone;
  Object? verifyError;

  int resendCalls = 0;
  String? lastResendPhone;
  Object? resendError;

  /// What the server reports about the code the resend just sent. Settable so
  /// a test can make the send "unknown" under an open screen, which is what a
  /// timeout to the SMS provider looks like.
  VerificationDelivery? resendDelivery = const VerificationDelivery(
    channel: 'sms',
    sentTo: '4567',
    retryAfter: 60,
  );

  @override
  Future<Map<String, dynamic>> verifyPhone({
    required String phoneNumber,
    required String code,
  }) async {
    verifyCalls++;
    lastCode = code;
    lastPhone = phoneNumber;

    final failure = verifyError;
    if (failure != null) throw failure;

    return {
      'resident_id': 31,
      'first_name': 'Maria',
      'last_name': 'Santos',
      'phone_number': '+639171234567',
      'barangay': {'barangay_name': 'San Fabian'},
    };
  }

  @override
  Future<VerificationDelivery?> resendVerificationCode({
    required String phoneNumber,
  }) async {
    resendCalls++;
    lastResendPhone = phoneNumber;
    final failure = resendError;
    if (failure != null) throw failure;

    return resendDelivery;
  }
}

Future<_FakeVerifyApi> _pump(
  WidgetTester tester, {
  _FakeVerifyApi? api,
  VerificationDelivery? delivery,
  void Function(AppUser)? onVerified,
  VoidCallback? onGoToLogin,
}) async {
  // Wider than a real phone, for the same reason as auth_screens_test: the
  // placeholder font flutter_test substitutes is far wider than Inter, and taps
  // on a clipped row silently miss rather than fail.
  tester.view.physicalSize = const Size(1440, 3200);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  final fake = api ?? _FakeVerifyApi();

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: VerifyPhoneScreen(
      userStore: UserStore(fake),
      phone: '09171234567',
      delivery: delivery,
      onVerified: onVerified ?? (_) {},
      onGoToLogin: onGoToLogin ?? () {},
    ),
  ));
  await tester.pump();

  return fake;
}

void main() {
  testWidgets('names the number the code went to, as a person writes it',
      (tester) async {
    await _pump(tester);

    expect(find.text('Check your messages'), findsOneWidget);
    // Without the server's last four, the number the resident typed.
    expect(find.textContaining('09171234567'), findsOneWidget);
  });

  testWidgets('names the last four digits when the server reports them',
      (tester) async {
    await _pump(
      tester,
      delivery: const VerificationDelivery(
        channel: 'sms',
        sentTo: '4567',
        retryAfter: 60,
      ),
    );

    expect(find.textContaining('ending in 4567'), findsOneWidget);
    expect(find.textContaining('email'), findsNothing);
  });

  testWidgets('starts the countdown at what the server reported',
      (tester) async {
    // A login inside the cooldown sends no new code and reports the remainder,
    // so the button must not re-enable a full minute later than it should.
    await _pump(
      tester,
      delivery: const VerificationDelivery(
        channel: 'sms',
        sentTo: '4567',
        retryAfter: 12,
      ),
    );

    expect(find.text('Resend code in 12s'), findsOneWidget);
  });

  testWidgets('says the text may be late when the server timed out sending it',
      (tester) async {
    await _pump(
      tester,
      delivery: const VerificationDelivery(
        channel: 'sms',
        sentTo: '4567',
        retryAfter: 60,
        unknown: true,
      ),
    );

    expect(find.byKey(const Key('delivery-unknown-hint')), findsOneWidget);
    expect(find.textContaining("Didn't get a text?"), findsOneWidget);
  });

  testWidgets('shows no hint when the send was accepted', (tester) async {
    await _pump(tester);

    expect(find.byKey(const Key('delivery-unknown-hint')), findsNothing);
  });

  testWidgets('a resend that comes back unknown shows the hint',
      (tester) async {
    final api = _FakeVerifyApi()
      ..resendDelivery = const VerificationDelivery(
        channel: 'sms',
        sentTo: '4567',
        retryAfter: 60,
        unknown: true,
      );
    await _pump(
      tester,
      api: api,
      delivery: const VerificationDelivery(
        channel: 'sms',
        sentTo: '4567',
        retryAfter: 0,
      ),
    );

    expect(find.byKey(const Key('delivery-unknown-hint')), findsNothing);

    await tester.tap(find.text('Send a new code'));
    await tester.pump();
    await tester.pump();

    expect(api.resendCalls, 1);
    expect(api.lastResendPhone, '09171234567');
    expect(find.byKey(const Key('delivery-unknown-hint')), findsOneWidget);
  });

  testWidgets('refuses a short code without calling the server',
      (tester) async {
    final api = await _pump(tester);

    await tester.enterText(find.byType(TextField).first, '123');
    await tester.tap(find.text('Verify'));
    await tester.pump();

    expect(api.verifyCalls, 0);
    expect(find.text('Enter the 6-digit code we sent you.'), findsWidgets);
  });

  testWidgets('sends a six-digit code with the number and hands back the resident',
      (tester) async {
    AppUser? verified;
    final api = await _pump(tester, onVerified: (user) => verified = user);

    await tester.enterText(find.byType(TextField).first, '123456');
    await tester.tap(find.text('Verify'));
    await tester.pump();
    await tester.pump();

    expect(api.verifyCalls, 1);
    expect(api.lastCode, '123456');
    expect(api.lastPhone, '09171234567');
    // Verifying issues a token, so the caller signs in rather than being sent
    // back to the login form.
    expect(verified, isNotNull);
    // Stored as E.164, shown as a person writes it.
    expect(verified!.phone, '+639171234567');
    expect(verified!.phoneDisplay, '09171234567');
  });

  testWidgets('shows a server rejection on the form and stays put',
      (tester) async {
    final api = _FakeVerifyApi()
      ..verifyError = const ApiException(
        'That code is not right, or it has expired. Ask for a new one.',
        statusCode: 422,
        code: 'invalid_code',
      );
    AppUser? verified;
    await _pump(tester, api: api, onVerified: (user) => verified = user);

    await tester.enterText(find.byType(TextField).first, '000000');
    await tester.tap(find.text('Verify'));
    await tester.pump();
    await tester.pump();

    expect(verified, isNull);
    expect(find.textContaining('not right'), findsWidgets);
  });

  testWidgets('resend is disabled while the cooldown is running',
      (tester) async {
    final api = await _pump(tester);

    // A code was sent by whatever routed here, so the server-side cooldown is
    // already running and the button must not offer to burn a 429.
    expect(find.textContaining('Resend code in'), findsOneWidget);

    await tester.tap(find.textContaining('Resend code in'));
    await tester.pump();

    expect(api.resendCalls, 0);
  });

  testWidgets('resend becomes available once the cooldown elapses',
      (tester) async {
    final api = await _pump(tester);

    // The screen counts down with a real Timer; pumping past it is what proves
    // the control ever re-enables.
    await tester.pump(const Duration(seconds: 61));

    expect(find.text('Send a new code'), findsOneWidget);

    await tester.tap(find.text('Send a new code'));
    await tester.pump();
    await tester.pump();

    expect(api.resendCalls, 1);
    expect(find.textContaining('on its way'), findsOneWidget);
  });

  testWidgets('a resend the server could not text shows its sentence',
      (tester) async {
    final api = _FakeVerifyApi()
      ..resendError = const ApiException(
        'We could not send the text message. Check the number and try again in a minute, or visit the MDRRMO office.',
        statusCode: 503,
        code: 'sms_unavailable',
      );
    await _pump(
      tester,
      api: api,
      delivery: const VerificationDelivery(
          channel: 'sms', sentTo: '4567', retryAfter: 0),
    );

    await tester.tap(find.text('Send a new code'));
    await tester.pump();
    await tester.pump();

    expect(find.textContaining('could not send the text message'), findsWidgets);
  });
}
