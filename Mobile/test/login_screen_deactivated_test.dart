import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/screens/auth/login_screen.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';

/// The login screen's answer to a deactivated account.
///
/// The backend refuses with 403 + `account_deactivated` (AuthController::
/// residentLogin, 2026-09-03). Without a branch for it the resident sees the
/// ordinary form error above a live "Log in" button and retries a rejection
/// that can never change — which is exactly what the comment at the backend
/// decision point warned would happen.
///
/// What is asserted here is the *absence* of a retry, not just the presence of
/// a message. A test that only checked the text would pass against the bug.
class _DeactivatedApi extends ApiService {
  int loginCalls = 0;

  @override
  Future<Map<String, dynamic>> residentLogin({
    required String email,
    required String password,
  }) async {
    loginCalls++;
    throw const ApiException(
      'This account has been deactivated. Please visit the MDRRMO office.',
      statusCode: 403,
      code: 'account_deactivated',
    );
  }
}

void main() {
  const message =
      'This account has been deactivated. Please visit the MDRRMO office.';

  Future<_DeactivatedApi> pumpAndSubmit(WidgetTester tester) async {
    final api = _DeactivatedApi();

    // The login screen is taller than the 800x600 default test surface, which
    // leaves "Log in" at y=602 — off-screen, so tap() misses it and every
    // assertion below fails for a reason that has nothing to do with the
    // branch under test.
    //
    // 600 wide, not a phone 430: flutter test renders with a fixed-width test
    // font whose metrics are not the shipped ones, and at 430 the sign-up Row
    // (login_screen.dart:290) overflows by 4.5px under that font alone. That
    // is a measurement artifact of the harness, not a layout bug — the real
    // build was driven at 430x932 without it — so this buys margin rather than
    // asserting against a false positive.
    await tester.binding.setSurfaceSize(const Size(600, 1000));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(MaterialApp(
      home: LoginScreen(
        userStore: UserStore(api),
        onLoginSuccess: (_) {},
        onGoToRegister: () {},
        onEmailUnverified: (_, __) {},
        onMfaRequired: (_, __, ___) {},
      ),
    ));

    await tester.enterText(
        find.byType(TextFormField).first, 'maria@test.local');
    await tester.enterText(find.byType(TextFormField).last, 'Password123');

    await tester.tap(find.text('Log in'));
    await tester.pumpAndSettle();

    return api;
  }

  testWidgets('a deactivated account shows the server message', (tester) async {
    await pumpAndSubmit(tester);

    expect(find.text(message), findsOneWidget);
  });

  testWidgets('a deactivated account offers no retry', (tester) async {
    final api = await pumpAndSubmit(tester);

    expect(api.loginCalls, 1);

    // The button is what removes the retry. A disabled ElevatedButton has a
    // null onPressed — tapping it must not reach the API again.
    final button = tester.widget<ElevatedButton>(
      find.ancestor(
        of: find.text('Log in'),
        matching: find.byType(ElevatedButton),
      ),
    );
    expect(button.onPressed, isNull);

    await tester.tap(find.text('Log in'));
    await tester.pumpAndSettle();

    expect(api.loginCalls, 1, reason: 'A closed account must not be retryable.');
  });

  testWidgets('editing the email clears the block', (tester) async {
    await pumpAndSubmit(tester);

    expect(find.text(message), findsOneWidget);

    // A different account may be perfectly fine, so the screen must not become
    // a dead end that needs an app restart.
    await tester.enterText(find.byType(TextFormField).first, 'other@test.local');
    await tester.pumpAndSettle();

    expect(find.text(message), findsNothing);

    final button = tester.widget<ElevatedButton>(
      find.ancestor(
        of: find.text('Log in'),
        matching: find.byType(ElevatedButton),
      ),
    );
    expect(button.onPressed, isNotNull);
  });
}
