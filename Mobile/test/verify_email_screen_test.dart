// The code screen is the second half of registration: an account exists but is
// unusable until the emailed code comes back. What is worth pinning is not that
// the field draws, but the refusals and the routing:
//
//  * a short code must be refused WITHOUT a round trip — the verify route is
//    rate-limited at five a minute, and a wasted attempt is one a resident who
//    mistyped cannot get back;
//  * a server rejection must land on the form, not be swallowed;
//  * verifying hands back a signed-in resident, so the caller navigates into
//    the app rather than to a login form;
//  * resend is disabled during the cooldown, because the server answers 429
//    inside it and the resident would be tapping into a refusal.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/screens/auth/verify_email_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/theme/app_theme.dart';

class _FakeVerifyApi extends ApiService {
  int verifyCalls = 0;
  String? lastCode;
  Object? verifyError;

  int resendCalls = 0;
  Object? resendError;

  @override
  Future<Map<String, dynamic>> verifyEmail({
    required String email,
    required String code,
  }) async {
    verifyCalls++;
    lastCode = code;

    final failure = verifyError;
    if (failure != null) throw failure;

    return {
      'resident_id': 31,
      'first_name': 'Maria',
      'last_name': 'Santos',
      'email_address': email,
      'phone_number': '09171111111',
      'barangay': {'barangay_name': 'San Fabian'},
    };
  }

  @override
  Future<void> resendVerificationCode({required String email}) async {
    resendCalls++;
    final failure = resendError;
    if (failure != null) throw failure;
  }
}

Future<_FakeVerifyApi> _pump(
  WidgetTester tester, {
  _FakeVerifyApi? api,
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
    home: VerifyEmailScreen(
      userStore: UserStore(fake),
      email: 'grace@test.local',
      onVerified: onVerified ?? (_) {},
      onGoToLogin: onGoToLogin ?? () {},
    ),
  ));
  await tester.pump();

  return fake;
}

void main() {
  testWidgets('names the address the code went to', (tester) async {
    await _pump(tester);

    expect(find.textContaining('grace@test.local'), findsOneWidget);
  });

  testWidgets('refuses a short code without calling the server',
      (tester) async {
    final api = await _pump(tester);

    await tester.enterText(find.byType(TextField).first, '123');
    await tester.tap(find.text('Verify'));
    await tester.pump();

    expect(api.verifyCalls, 0);
    expect(find.text('Enter the 6-digit code from your email.'), findsWidgets);
  });

  testWidgets('sends a six-digit code and hands back the resident',
      (tester) async {
    AppUser? verified;
    final api = await _pump(tester, onVerified: (user) => verified = user);

    await tester.enterText(find.byType(TextField).first, '123456');
    await tester.tap(find.text('Verify'));
    await tester.pump();
    await tester.pump();

    expect(api.verifyCalls, 1);
    expect(api.lastCode, '123456');
    // Verifying issues a token, so the caller signs in rather than being sent
    // back to the login form.
    expect(verified, isNotNull);
    expect(verified!.email, 'grace@test.local');
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
}
