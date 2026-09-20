// The forgot-password screen: the number, then the code texted to it, then a new
// password. What is worth pinning is the parts a resident can get wrong or a
// stranger could probe:
//
//  * the number is checked here before any text is spent;
//  * the screen never says whether the number has an account — the second step
//    always reads "if this number has an account";
//  * a short code is refused without a round trip;
//  * a wrong code shows the server's (single, neutral) sentence and stays put;
//  * the new password is checked against the same rules the server applies, and
//    the two entries must match;
//  * finishing does not sign anyone in: it hands a sentence back to the login
//    screen;
//  * an expired reset token sends the resident back to the start rather than
//    leaving them retrying a password that can never be accepted.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/screens/auth/forgot_password_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/theme/app_theme.dart';
import 'package:serbis/widgets/shared_widgets.dart';

class _FakeApi extends ApiService {
  final List<String> forgotPhones = [];
  final List<Map<String, String>> verifies = [];
  final List<Map<String, String>> resets = [];

  int cooldown = 60;
  ApiException? forgotError;
  ApiException? verifyError;
  ApiException? resetError;

  @override
  Future<int> forgotPassword({required String phoneNumber}) async {
    forgotPhones.add(phoneNumber);
    final failure = forgotError;
    if (failure != null) throw failure;
    return cooldown;
  }

  @override
  Future<String> verifyPasswordReset({
    required String phoneNumber,
    required String code,
  }) async {
    verifies.add({'phone': phoneNumber, 'code': code});
    final failure = verifyError;
    if (failure != null) throw failure;
    return 'reset-token-1';
  }

  @override
  Future<void> resetPassword({
    required String phoneNumber,
    required String resetToken,
    required String password,
  }) async {
    resets.add({'phone': phoneNumber, 'token': resetToken, 'password': password});
    final failure = resetError;
    if (failure != null) throw failure;
  }
}

Finder _field(String label) => find.descendant(
      of: find.ancestor(of: find.text(label), matching: find.byType(AuthTextField)),
      matching: find.byType(TextFormField),
    );

Future<_FakeApi> _pump(
  WidgetTester tester, {
  _FakeApi? api,
  String? initialPhone,
  VoidCallback? onGoToLogin,
  void Function(String)? onReset,
}) async {
  tester.view.physicalSize = const Size(1440, 3200);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  final fake = api ?? _FakeApi();

  await tester.pumpWidget(MaterialApp(
    theme: buildAppTheme(),
    home: ForgotPasswordScreen(
      userStore: UserStore(fake),
      initialPhone: initialPhone,
      onGoToLogin: onGoToLogin ?? () {},
      onReset: onReset ?? (_) {},
    ),
  ));
  await tester.pumpAndSettle();

  return fake;
}

/// Gets to the code step with a valid number.
Future<void> _toCodeStep(WidgetTester tester) async {
  await tester.enterText(_field('Mobile number'), '09171234567');
  await tester.tap(find.text('Send code'));
  await tester.pumpAndSettle();
}

/// Gets to the password step.
Future<void> _toPasswordStep(WidgetTester tester) async {
  await _toCodeStep(tester);
  await tester.enterText(find.byType(TextField).first, '123456');
  await tester.tap(find.text('Verify'));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('starts with the number the login screen had', (tester) async {
    await _pump(tester, initialPhone: '09171234567');

    final field = tester.widget<TextFormField>(_field('Mobile number'));
    expect(field.controller!.text, '09171234567');
  });

  testWidgets('a bad number is refused before anything is sent', (tester) async {
    final api = await _pump(tester);

    await tester.tap(find.text('Send code'));
    await tester.pumpAndSettle();
    expect(find.text('Enter your mobile number'), findsOneWidget);

    await tester.enterText(_field('Mobile number'), '0917');
    await tester.tap(find.text('Send code'));
    await tester.pumpAndSettle();
    expect(find.text('Enter a valid mobile number'), findsOneWidget);

    expect(api.forgotPhones, isEmpty);
  });

  testWidgets('sending moves on and never says whether the number has an account',
      (tester) async {
    final api = await _pump(tester);

    await _toCodeStep(tester);

    expect(api.forgotPhones, ['09171234567']);
    expect(find.text('Check your messages'), findsOneWidget);
    final line = tester.widget<Text>(find.byKey(const Key('forgot-sent-line')));
    // Conditional, always: the same sentence whatever the server knows.
    expect(line.data, startsWith('If 09171234567 has an account'));
    expect(find.textContaining('Resend code in 60s'), findsOneWidget);
  });

  testWidgets('starts the resend countdown at what the server reported',
      (tester) async {
    final api = _FakeApi()..cooldown = 25;
    await _pump(tester, api: api);

    await _toCodeStep(tester);

    expect(find.text('Resend code in 25s'), findsOneWidget);
  });

  testWidgets('a rate-limited ask shows the server\'s sentence and stays on step one',
      (tester) async {
    final api = _FakeApi()
      ..forgotError = const ApiException(
        'Too Many Attempts.',
        statusCode: 429,
      );
    await _pump(tester, api: api);

    await _toCodeStep(tester);

    expect(find.byKey(const Key('forgot-error')), findsOneWidget);
    expect(find.text('Send code'), findsOneWidget);
    expect(find.text('Check your messages'), findsNothing);
  });

  testWidgets('a short code is refused without calling the server', (tester) async {
    final api = await _pump(tester);
    await _toCodeStep(tester);

    await tester.enterText(find.byType(TextField).first, '123');
    await tester.tap(find.text('Verify'));
    await tester.pumpAndSettle();

    expect(find.text('Enter the 6-digit code we sent you.'), findsWidgets);
    expect(api.verifies, isEmpty);
  });

  testWidgets('a wrong code shows the server\'s sentence and stays on the code step',
      (tester) async {
    final api = _FakeApi()
      ..verifyError = const ApiException(
        'That code is not right, or it has expired. Ask for a new one.',
        statusCode: 422,
        code: 'invalid_code',
      );
    await _pump(tester, api: api);
    await _toCodeStep(tester);

    await tester.enterText(find.byType(TextField).first, '000000');
    await tester.tap(find.text('Verify'));
    await tester.pumpAndSettle();

    expect(find.textContaining('not right'), findsWidgets);
    expect(find.text('Choose a new password'), findsNothing);
  });

  testWidgets('resend is off during the cooldown and on after it', (tester) async {
    final api = await _pump(tester);
    await _toCodeStep(tester);

    await tester.tap(find.textContaining('Resend code in'));
    await tester.pump();
    expect(api.forgotPhones, hasLength(1));

    await tester.pump(const Duration(seconds: 61));
    await tester.tap(find.text('Send a new code'));
    await tester.pumpAndSettle();

    expect(api.forgotPhones, hasLength(2));
    // Still the conditional wording: a resend says nothing more than the ask did.
    expect(find.textContaining('If this number has an account'), findsOneWidget);
  });

  testWidgets('a good code opens the password step', (tester) async {
    final api = await _pump(tester);

    await _toPasswordStep(tester);

    expect(api.verifies, [
      {'phone': '09171234567', 'code': '123456'},
    ]);
    expect(find.text('Choose a new password'), findsOneWidget);
  });

  testWidgets('the new password must meet the rules and match', (tester) async {
    final api = await _pump(tester);
    await _toPasswordStep(tester);

    await tester.tap(find.text('Change password'));
    await tester.pumpAndSettle();
    expect(find.text('Enter a new password'), findsOneWidget);

    await tester.enterText(_field('New password'), 'short');
    await tester.tap(find.text('Change password'));
    await tester.pumpAndSettle();
    expect(find.text('At least 8 characters'), findsOneWidget);

    await tester.enterText(_field('New password'), 'alllowercase1');
    await tester.tap(find.text('Change password'));
    await tester.pumpAndSettle();
    expect(find.text('Use upper and lower case letters and a number'), findsOneWidget);

    await tester.enterText(_field('New password'), 'NewPass123');
    await tester.enterText(_field('Confirm new password'), 'Different123');
    await tester.tap(find.text('Change password'));
    await tester.pumpAndSettle();
    expect(find.text('The two passwords do not match'), findsOneWidget);

    expect(api.resets, isEmpty);
  });

  testWidgets('finishing sends the token and hands a message back, without signing in',
      (tester) async {
    String? message;
    final api = await _pump(tester, onReset: (m) => message = m);
    await _toPasswordStep(tester);

    await tester.enterText(_field('New password'), 'NewPass123');
    await tester.enterText(_field('Confirm new password'), 'NewPass123');
    await tester.tap(find.text('Change password'));
    await tester.pumpAndSettle();

    expect(api.resets, [
      {'phone': '09171234567', 'token': 'reset-token-1', 'password': 'NewPass123'},
    ]);
    expect(message, 'Your password has been changed. Log in with your new password.');
  });

  testWidgets('an expired reset sends the resident back to the number step',
      (tester) async {
    final api = _FakeApi()
      ..resetError = const ApiException(
        'This reset has expired. Start again.',
        statusCode: 422,
        code: 'reset_expired',
      );
    String? message;
    await _pump(tester, api: api, onReset: (m) => message = m);
    await _toPasswordStep(tester);

    await tester.enterText(_field('New password'), 'NewPass123');
    await tester.enterText(_field('Confirm new password'), 'NewPass123');
    await tester.tap(find.text('Change password'));
    await tester.pumpAndSettle();

    expect(message, isNull);
    expect(find.text('Forgot your password?'), findsOneWidget);
    expect(find.text('This reset has expired. Start again.'), findsOneWidget);
  });

  testWidgets('"Use a different number" and "Back to log in" both leave', (tester) async {
    var wentBack = false;
    await _pump(tester, onGoToLogin: () => wentBack = true);
    await _toCodeStep(tester);

    await tester.tap(find.text('Use a different number'));
    await tester.pumpAndSettle();
    expect(find.text('Forgot your password?'), findsOneWidget);

    await tester.tap(find.text('Back to log in'));
    await tester.pumpAndSettle();
    expect(wentBack, isTrue);
  });
}
