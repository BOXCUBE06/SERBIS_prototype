// Covers the failure half of the HTTP layer — `_decode` and `_errorMessage` in
// `api_service.dart` — which the coverage run of 2026-08-06 measured at 0 of 22
// lines. Nothing tested the sentence a resident actually reads when a request
// fails, so every message below could have been rewritten, reordered or dropped
// without a single test noticing.
//
// These drive the real `ApiService` through a faked transport rather than
// calling the private methods, because the branch that matters most is not a
// string at all: a 401 on a stored token has to sign the resident out, and a 401
// on the login form must not. That distinction lives in `_decode`, above
// `_errorMessage`, and only a real call exercises both together.
//
// `http.runWithClient` is what makes this possible without changing production
// code: `_send` calls the top-level `http.get`/`http.post`, and those resolve
// the client from the current zone. The base URL is empty under a plain
// `flutter test` (no `--dart-define`), so the request URLs are relative — the
// fake never looks at them beyond the path.

import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:serbis/state/api_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

const String _tokenKey = 'serbis_token_v1';

/// Runs [body] with every HTTP call answered by [respond].
Future<T> withResponse<T>(
  http.Response Function(http.Request request) respond,
  Future<T> Function() body,
) {
  return http.runWithClient(body, () => MockClient((r) async => respond(r)));
}

http.Response json(int status, Object? payload) => http.Response(
      jsonEncode(payload),
      status,
      headers: <String, String>{'content-type': 'application/json'},
    );

/// A signed-in service. The token has to be real for the sign-out assertions to
/// mean anything — clearing a token that was never there proves nothing.
Future<ApiService> signedInService() async {
  SharedPreferences.setMockInitialValues(<String, Object>{});
  FlutterSecureStorage.setMockInitialValues(<String, String>{
    _tokenKey: 'stored-token',
  });

  final api = ApiService();
  await api.loadToken();
  expect(api.isLoggedIn, isTrue, reason: 'fixture must start signed in');
  return api;
}

/// The message a resident is shown when [call] fails.
Future<String> messageFrom(Future<void> Function() call) async {
  try {
    await call();
  } on ApiException catch (e) {
    return e.message;
  }
  fail('the call was expected to throw an ApiException');
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('validation failures', () {
    test('shows the first field error verbatim', () async {
      // The real shape of a Laravel 422. The resident is told which field and
      // why; a generic "Request failed (422)" would send them back to the form
      // with nothing to change.
      final api = await signedInService();

      final message = await withResponse(
        (_) => json(422, <String, dynamic>{
          'message': 'The given data was invalid.',
          'errors': <String, dynamic>{
            'email_address': <String>[
              'The email address has already been taken.',
            ],
            'phone_number': <String>['The phone number field is required.'],
          },
        }),
        () => messageFrom(() => api.me()),
      );

      expect(message, 'The email address has already been taken.');
    });

    test('reads a field error that is a bare string, not a list', () async {
      final api = await signedInService();

      final message = await withResponse(
        (_) => json(422, <String, dynamic>{
          'errors': <String, dynamic>{'quantity': 'Only 2 are available.'},
        }),
        () => messageFrom(() => api.me()),
      );

      expect(message, 'Only 2 are available.');
    });

    test('falls through to message when errors is present but empty', () async {
      final api = await signedInService();

      final message = await withResponse(
        (_) => json(422, <String, dynamic>{
          'errors': <String, dynamic>{},
          'message': 'The given data was invalid.',
        }),
        () => messageFrom(() => api.me()),
      );

      expect(message, 'The given data was invalid.');
    });

    test('falls through when the field holds an empty list', () async {
      final api = await signedInService();

      final message = await withResponse(
        (_) => json(422, <String, dynamic>{
          'errors': <String, dynamic>{'email_address': <String>[]},
          'message': 'The given data was invalid.',
        }),
        () => messageFrom(() => api.me()),
      );

      expect(message, 'The given data was invalid.');
    });
  });

  group('server messages', () {
    test('shows the server message when there are no field errors', () async {
      // How a deactivated account, a closed borrowing transition and a busy
      // fleet all reach the resident: one sentence written by the controller.
      final api = await signedInService();

      final message = await withResponse(
        (_) => json(422, <String, dynamic>{
          'message': 'No vehicle is available for this barangay right now.',
        }),
        () => messageFrom(() => api.me()),
      );

      expect(message, 'No vehicle is available for this barangay right now.');
    });

    test('never shows the framework default', () async {
      // 'Unauthenticated.' is Laravel's, not the office's. It tells a resident
      // nothing about what to do next, which is the whole reason the 401
      // fallback below exists.
      final api = await signedInService();

      final message = await withResponse(
        (_) => json(401, <String, dynamic>{'message': 'Unauthenticated.'}),
        () => messageFrom(() => api.me()),
      );

      expect(message, 'Your session expired. Please log in again.');
    });

    test('ignores an empty message and falls back on the status', () async {
      final api = await signedInService();

      final message = await withResponse(
        (_) => json(403, <String, dynamic>{'message': ''}),
        () => messageFrom(() => api.me()),
      );

      expect(message, 'You are not allowed to do that.');
    });
  });

  group('status fallbacks', () {
    test('403 without a body', () async {
      final api = await signedInService();

      final message = await withResponse(
        (_) => json(403, <String, dynamic>{}),
        () => messageFrom(() => api.getAdvisories()),
      );

      expect(message, 'You are not allowed to do that.');
    });

    test('404 without a body', () async {
      final api = await signedInService();

      final message = await withResponse(
        (_) => json(404, <String, dynamic>{}),
        () => messageFrom(() => api.me()),
      );

      expect(message, 'Not found.');
    });

    test('500 with an HTML error page instead of JSON', () async {
      // A crashed server, a proxy, or a captive portal. The body cannot be
      // decoded at all, so there is no message to read and the status is the
      // only thing left to speak from.
      final api = await signedInService();

      final message = await withResponse(
        (_) => http.Response(
          '<!DOCTYPE html><html><body>Server Error</body></html>',
          500,
          headers: <String, String>{'content-type': 'text/html'},
        ),
        () => messageFrom(() => api.me()),
      );

      expect(message, 'The server had a problem. Please try again.');
    });

    test('an unmapped status names itself', () async {
      // Deliberately not a friendlier sentence: an unmapped status is a case
      // nobody designed for, and the number is what makes it reportable.
      final api = await signedInService();

      final message = await withResponse(
        (_) => json(400, <String, dynamic>{}),
        () => messageFrom(() => api.me()),
      );

      expect(message, 'Request failed (400).');
    });

    test('carries the status code on the exception', () async {
      final api = await signedInService();

      await withResponse((_) => json(404, <String, dynamic>{}), () async {
        try {
          await api.me();
          fail('expected an ApiException');
        } on ApiException catch (e) {
          expect(e.statusCode, 404);
        }
      });
    });
  });

  group('a rejected stored token ends the session', () {
    test('401 clears the token and calls onUnauthorized', () async {
      final api = await signedInService();
      var signedOut = 0;
      api.onUnauthorized = () => signedOut++;

      await withResponse(
        (_) => json(401, <String, dynamic>{'message': 'Unauthenticated.'}),
        () => messageFrom(() => api.getRequests()),
      );

      expect(signedOut, 1);
      expect(api.isLoggedIn, isFalse);
      const storage = FlutterSecureStorage();
      expect(
        await storage.read(key: _tokenKey),
        isNull,
        reason: 'a rejected token must not survive on the device',
      );
    });

    test('401 on the login form does not sign anyone out', () async {
      // The exemption that makes the branch above safe. On `/register` and
      // `/resident/login` a 401 means the password was wrong, not that a
      // session ended — firing onUnauthorized here would bounce the resident
      // off the screen they are trying to log in from.
      final api = await signedInService();
      var signedOut = 0;
      api.onUnauthorized = () => signedOut++;

      final message = await withResponse(
        (_) => json(401, <String, dynamic>{'message': 'Invalid credentials.'}),
        () => api.register(
          firstName: 'Maria',
          lastName: 'Santos',
          barangayId: 1,
          phoneNumber: '09171234567',
          email: 'maria@example.test',
          password: 'Secret123',
        ),
      );

      expect(message, 'Invalid credentials.');
      expect(signedOut, 0);
      expect(api.isLoggedIn, isTrue, reason: 'the stored token is unrelated');
    });

    test('a 403 does not sign anyone out', () async {
      // An admin account reading /advisories. Wrong account for the route, but
      // the token is valid — dropping it would log out a working session.
      final api = await signedInService();
      var signedOut = 0;
      api.onUnauthorized = () => signedOut++;

      await withResponse(
        (_) => json(403, <String, dynamic>{}),
        () => messageFrom(() => api.getAdvisories()),
      );

      expect(signedOut, 0);
      expect(api.isLoggedIn, isTrue);
    });
  });

  group('bodies that are not failures', () {
    test('a 200 that is not JSON is still an error', () async {
      // The case that used to return {} and render as an empty list — "you have
      // no requests" where the truth was "the reply was not the API's".
      final api = await signedInService();

      final message = await withResponse(
        (_) => http.Response('<html>hi</html>', 200),
        () => messageFrom(() => api.me()),
      );

      expect(message, 'Unexpected response from server.');
    });

    test('a bare JSON array is wrapped under data', () async {
      // `GET /info-materials` and `GET /barangays` return a bare array, and
      // `listFrom` only reads `data`.
      final api = await signedInService();

      final rows = await withResponse(
        (_) => json(200, <dynamic>[
          <String, dynamic>{'barangay_id': 1},
          <String, dynamic>{'barangay_id': 2},
        ]),
        () => api.getBarangays(),
      );

      expect(rows, hasLength(2));
      expect(rows.first['barangay_id'], 1);
    });
  });

  group('transport failures', () {
    test('an unreachable server reads as a connection problem', () async {
      // Not a status at all — no response ever arrived, so there is no body to
      // read and nothing the office wrote. The sentence names the one thing the
      // resident can act on.
      final api = await signedInService();

      final message = await withResponse(
        (_) => throw const SocketLikeException(),
        () => messageFrom(() => api.me()),
      );

      expect(
        message,
        'Cannot connect to server. Check your internet connection.',
      );
    });

    test('a transport failure does not sign anyone out', () async {
      // A flood knocks the tower over, not the session. Clearing the token here
      // would make an outage indistinguishable from an expiry and force a login
      // the resident may not be able to complete.
      final api = await signedInService();
      var signedOut = 0;
      api.onUnauthorized = () => signedOut++;

      await withResponse(
        (_) => throw const SocketLikeException(),
        () => messageFrom(() => api.me()),
      );

      expect(signedOut, 0);
      expect(api.isLoggedIn, isTrue);
    });
  });
}

/// Stands in for the transport errors `_send` catches. `dart:io` is not
/// imported anywhere in `api_service.dart` on purpose — that would break the
/// web build — so the type is irrelevant to the code under test and only needs
/// to be an Exception.
class SocketLikeException implements Exception {
  const SocketLikeException();

  @override
  String toString() => 'SocketLikeException: connection failed';
}
