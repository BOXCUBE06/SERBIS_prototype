// A stored token carries no profile — AuthGate exchanges it for one via
// UserStore.currentUser() on every relaunch. That call used to treat a
// rejected token and a dead network identically: both dropped the resident
// to the login screen, which reads as "doesn't remember me" for someone who
// has simply lost signal.
//
// _restoreSession now tells the two apart by ApiException.isNetwork, not by
// isUnauthorized alone — a real error response (a 500, say) still carries a
// status and is left on the login screen, same as before. Only a connection
// failure (no signal, DNS, a timeout, the server unreachable — everything
// ApiService._send wraps into a status-less ApiException) is eligible to
// boot from the cached profile UserStore now writes on every successful
// `/me`, login and profile update.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:serbis/main.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/user_cache.dart';

const _meJson = <String, dynamic>{
  'resident_id': 7,
  'first_name': 'Maria',
  'last_name': 'Santos',
  'email_address': 'maria@test.local',
  'phone_number': '09171111111',
  'barangay': {'barangay_name': 'San Fabian'},
};

class _FakeApi extends ApiService {
  _FakeApi({this.meError});

  Object? meError;

  @override
  bool get isLoggedIn => true;

  @override
  Future<void> loadToken() async {}

  @override
  Future<Map<String, dynamic>> me() async {
    if (meError != null) throw meError!;
    return _meJson;
  }

  @override
  Future<List<Map<String, dynamic>>> getRequests() async => [];

  @override
  Future<List<Map<String, dynamic>>> getAdvisories() async => [];

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async => [];

  @override
  Future<List<Map<String, dynamic>>> getInfoMaterials() async => [];
}

Future<void> _pump(WidgetTester tester, ApiService api) async {
  await tester.pumpWidget(MaterialApp(home: AuthGate(api: api)));
  await tester.pumpAndSettle();
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('a network failure with no cached profile still shows login',
      (tester) async {
    await _pump(
      tester,
      _FakeApi(meError: const ApiException('Cannot connect to server.')),
    );

    expect(find.text('Register'), findsOneWidget);
    expect(find.text('Patient transport'), findsNothing);
  });

  testWidgets(
      'a network failure with a cached profile boots straight into the app',
      (tester) async {
    await UserCache().save(_meJson);

    await _pump(
      tester,
      _FakeApi(meError: const ApiException('Cannot connect to server.')),
    );

    expect(find.text('Patient transport'), findsOneWidget);
    expect(find.text('Register'), findsNothing);
  });

  testWidgets('a rejected token shows login even with a cached profile',
      (tester) async {
    await UserCache().save(_meJson);

    await _pump(
      tester,
      _FakeApi(
        meError: const ApiException('Your session expired.', statusCode: 401),
      ),
    );

    expect(find.text('Register'), findsOneWidget);
    expect(find.text('Patient transport'), findsNothing);
  });

  testWidgets('a real server error (not a connection failure) shows login too',
      (tester) async {
    await UserCache().save(_meJson);

    await _pump(
      tester,
      _FakeApi(
        meError: const ApiException('Something went wrong.', statusCode: 500),
      ),
    );

    expect(find.text('Register'), findsOneWidget);
    expect(find.text('Patient transport'), findsNothing);
  });

  testWidgets('a successful restore caches the profile for next time',
      (tester) async {
    expect(await UserCache().load(), isNull);

    await _pump(tester, _FakeApi());

    expect(find.text('Patient transport'), findsOneWidget);
    final cached = await UserCache().load();
    expect(cached, isNotNull);
    expect(cached!.fullName, 'Maria Santos');
  });
}
