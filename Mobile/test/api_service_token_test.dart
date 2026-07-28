// Covers the one-time migration of the auth token out of plain
// SharedPreferences and into secure storage. The migration only ever runs once
// per install, on a build nobody can re-run afterwards, so a bug here logs
// every existing user out with no way to reproduce it after the fact.

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/state/api_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

const String _tokenKey = 'serbis_token_v1';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('migrates a legacy prefs token into secure storage and clears it',
      () async {
    SharedPreferences.setMockInitialValues(<String, Object>{
      _tokenKey: 'legacy-token',
    });
    FlutterSecureStorage.setMockInitialValues(<String, String>{});

    final api = ApiService();
    await api.loadToken();

    expect(api.isLoggedIn, isTrue);

    const storage = FlutterSecureStorage();
    expect(await storage.read(key: _tokenKey), 'legacy-token');

    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getString(_tokenKey), isNull,
        reason: 'the plain-text copy must not survive the migration');
  });

  test('reads secure storage and leaves an unrelated prefs entry alone',
      () async {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      _tokenKey: 'secure-token',
    });

    final api = ApiService();
    await api.loadToken();

    expect(api.isLoggedIn, isTrue);
    const storage = FlutterSecureStorage();
    expect(await storage.read(key: _tokenKey), 'secure-token');
  });

  test('prefers secure storage over a stale legacy entry', () async {
    SharedPreferences.setMockInitialValues(<String, Object>{
      _tokenKey: 'legacy-token',
    });
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      _tokenKey: 'secure-token',
    });

    final api = ApiService();
    await api.loadToken();

    expect(api.isLoggedIn, isTrue);
    const storage = FlutterSecureStorage();
    expect(await storage.read(key: _tokenKey), 'secure-token');
  });

  test('stays logged out when neither store holds a token', () async {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    FlutterSecureStorage.setMockInitialValues(<String, String>{});

    final api = ApiService();
    await api.loadToken();

    expect(api.isLoggedIn, isFalse);
  });

  test('treats an empty legacy value as no token', () async {
    SharedPreferences.setMockInitialValues(<String, Object>{_tokenKey: ''});
    FlutterSecureStorage.setMockInitialValues(<String, String>{});

    final api = ApiService();
    await api.loadToken();

    expect(api.isLoggedIn, isFalse);
    const storage = FlutterSecureStorage();
    expect(await storage.read(key: _tokenKey), isNull);
  });
}
