// What leaves the device for phone login, and how the answers are read.
//
// The screens are tested against fake services, which is right for what they
// show but proves nothing about the request itself — a screen test asserting
// "the store was called with the number" passes whether the JSON key is
// `phone_number` or `email_address`. These drive the real ApiService through a
// faked transport, so the paths, the keys and the parsing are what is asserted.

import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:serbis/models/phone_number.dart';
import 'package:serbis/state/api_exception.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/verification_delivery.dart';
import 'package:shared_preferences/shared_preferences.dart';

const String _tokenKey = 'serbis_token_v1';

class _Sent {
  final String method;
  final String path;
  final Map<String, dynamic> body;
  _Sent(this.method, this.path, this.body);
}

/// Runs [body] answering every call with [status] and [payload], and returns
/// what was sent.
Future<List<_Sent>> _capture(
  int status,
  Object? payload,
  Future<void> Function(ApiService api) body, {
  bool signedIn = false,
}) async {
  SharedPreferences.setMockInitialValues(<String, Object>{});
  FlutterSecureStorage.setMockInitialValues(
    signedIn ? <String, String>{_tokenKey: 'stored-token'} : <String, String>{},
  );

  final api = ApiService();
  await api.loadToken();

  final sent = <_Sent>[];

  await http.runWithClient(
    () => body(api),
    () => MockClient((request) async {
      sent.add(_Sent(
        request.method,
        request.url.path,
        request.body.isEmpty
            ? <String, dynamic>{}
            : jsonDecode(request.body) as Map<String, dynamic>,
      ));
      return http.Response(
        jsonEncode(payload),
        status,
        headers: <String, String>{'content-type': 'application/json'},
      );
    }),
  );

  return sent;
}

Future<ApiException> _failure(Future<void> Function() call) async {
  try {
    await call();
  } on ApiException catch (e) {
    return e;
  }
  fail('the call was expected to throw an ApiException');
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('sign-up and login send a phone number, never an email', () {
    test('register posts phone_number and no email_address', () async {
      final sent = await _capture(
        201,
        {'verification_required': true, 'channel': 'sms', 'sent_to': '4567', 'retry_after': 60},
        (api) async {
          final outcome = await api.register(
            firstName: 'Maria',
            lastName: 'Santos',
            barangayId: 1,
            phoneNumber: '09171234567',
            password: 'Secret123',
          );
          expect(outcome.failed, isFalse);
          expect(outcome.delivery!.sentTo, '4567');
        },
      );

      expect(sent.single.path, endsWith('/register'));
      expect(sent.single.body['phone_number'], '09171234567');
      expect(sent.single.body.containsKey('email_address'), isFalse);
    });

    test('login posts phone_number', () async {
      final sent = await _capture(
        200,
        {'token': 't', 'user': {'resident_id': 1, 'first_name': 'Maria'}},
        (api) async {
          await api.residentLogin(phoneNumber: '09171234567', password: 'Secret123');
        },
      );

      expect(sent.single.path, endsWith('/resident/login'));
      expect(sent.single.body, {'phone_number': '09171234567', 'password': 'Secret123'});
    });

    test('verify and resend use the phone routes', () async {
      final sent = await _capture(
        200,
        {'token': 't', 'user': {'resident_id': 1}, 'channel': 'sms', 'retry_after': 60},
        (api) async {
          await api.verifyPhone(phoneNumber: '09171234567', code: '123456');
          await api.resendVerificationCode(phoneNumber: '09171234567');
        },
      );

      expect(sent[0].path, endsWith('/resident/verify-phone'));
      expect(sent[0].body, {'phone_number': '09171234567', 'code': '123456'});
      expect(sent[1].path, endsWith('/resident/verify-phone/resend'));
      expect(sent[1].body, {'phone_number': '09171234567'});
    });

    test('the profile PATCH no longer carries a number, an email or a password',
        () {
      final body = ApiService.buildProfileUpdateBody(
        firstName: 'Maria',
        streetAddress: 'Purok 3',
        smsOptIn: false,
      );

      expect(body, {
        'first_name': 'Maria',
        'street_address': 'Purok 3',
        'sms_opt_in': false,
      });
    });
  });

  group('the number change', () {
    test('step one posts the new number and the password to /me/phone',
        () async {
      final sent = await _capture(
        200,
        {'pending': true, 'channel': 'sms', 'sent_to': '9999', 'retry_after': 60, 'delivery': 'accepted'},
        (api) async {
          final delivery = await api.requestPhoneChange(
            phoneNumber: '09179999999',
            currentPassword: 'Secret123',
          );
          expect(delivery!.sentTo, '9999');
          expect(delivery.unknown, isFalse);
        },
        signedIn: true,
      );

      expect(sent.single.method, 'POST');
      expect(sent.single.path, endsWith('/me/phone'));
      expect(sent.single.body, {
        'phone_number': '09179999999',
        'current_password': 'Secret123',
      });
    });

    test('step two posts only the code and returns the refreshed profile',
        () async {
      final sent = await _capture(
        200,
        {'role': 'resident', 'user': {'resident_id': 1, 'phone_number': '+639179999999'}},
        (api) async {
          final user = await api.verifyPhoneChange(code: '123456');
          expect(user['phone_number'], '+639179999999');
        },
        signedIn: true,
      );

      expect(sent.single.path, endsWith('/me/phone/verify'));
      expect(sent.single.body, {'code': '123456'});
    });

    test('resend posts to /me/phone/resend with no password', () async {
      final sent = await _capture(
        200,
        {'code': 'code_sent', 'channel': 'sms', 'sent_to': '9999', 'retry_after': 60},
        (api) => api.resendPhoneChangeCode(),
        signedIn: true,
      );

      expect(sent.single.path, endsWith('/me/phone/resend'));
      expect(sent.single.body, isEmpty);
    });
  });

  group('the password reset', () {
    test('forgot posts the number and reads the cooldown', () async {
      final sent = await _capture(
        200,
        {'message': 'If this number has an account, a code is on its way.', 'retry_after': 60},
        (api) async {
          expect(await api.forgotPassword(phoneNumber: '09171234567'), 60);
        },
      );

      expect(sent.single.path, endsWith('/resident/password/forgot'));
      expect(sent.single.body, {'phone_number': '09171234567'});
    });

    test('verify returns the reset token', () async {
      final sent = await _capture(
        200,
        {'reset_token': 'abc123'},
        (api) async {
          expect(
            await api.verifyPasswordReset(phoneNumber: '09171234567', code: '123456'),
            'abc123',
          );
        },
      );

      expect(sent.single.path, endsWith('/resident/password/verify'));
      expect(sent.single.body, {'phone_number': '09171234567', 'code': '123456'});
    });

    test('reset sends the token and the new password twice, and stores no token',
        () async {
      final sent = await _capture(
        200,
        {'message': 'Your password has been changed. Log in with your new password.'},
        (api) async {
          await api.resetPassword(
            phoneNumber: '09171234567',
            resetToken: 'abc123',
            password: 'NewPass123',
          );
          // A reset does not sign in.
          expect(api.isLoggedIn, isFalse);
        },
      );

      expect(sent.single.path, endsWith('/resident/password/reset'));
      expect(sent.single.body, {
        'phone_number': '09171234567',
        'reset_token': 'abc123',
        'password': 'NewPass123',
        'password_confirmation': 'NewPass123',
      });
    });

    test('a wrong or expired code reads as the server said it', () async {
      await _capture(
        422,
        {'message': 'That code is not right, or it has expired. Ask for a new one.', 'code': 'invalid_code'},
        (api) async {
          final error = await _failure(
              () => api.verifyPasswordReset(phoneNumber: '09171234567', code: '000000'));
          expect(error.code, 'invalid_code');
          expect(error.message, contains('not right'));
        },
      );
    });
  });

  group('what the server can answer with', () {
    test('phone_unverified, sms_unavailable and app_update_required are told apart',
        () async {
      Future<ApiException> answer(int status, Map<String, dynamic> body) async {
        late ApiException caught;
        await _capture(status, body, (api) async {
          caught = await _failure(() =>
              api.residentLogin(phoneNumber: '09171234567', password: 'Secret123'));
        });
        return caught;
      }

      final unverified = await answer(403, {
        'message': 'Please enter the code we just sent to finish creating your account.',
        'code': 'phone_unverified',
        'channel': 'sms',
        'sent_to': '4567',
        'retry_after': 30,
      });
      expect(unverified.isPhoneUnverified, isTrue);
      expect(unverified.delivery!.retryAfter, 30);

      final unavailable = await answer(503, {
        'message': 'We could not send the text message.',
        'code': 'sms_unavailable',
      });
      expect(unavailable.isSmsUnavailable, isTrue);
      expect(unavailable.isPhoneUnverified, isFalse);

      final oldApp = await answer(410, {
        'message': 'Please update the SERBIS app to continue. / Paki-update ang SERBIS app para magpatuloy.',
        'code': 'app_update_required',
      });
      expect(oldApp.isAppUpdateRequired, isTrue);
      // The server's own bilingual sentence is what the resident reads.
      expect(oldApp.message, contains('Paki-update'));
    });

    test('a 410 on the login form does not sign anyone out', () async {
      var signedOut = 0;
      SharedPreferences.setMockInitialValues(<String, Object>{});
      FlutterSecureStorage.setMockInitialValues(<String, String>{_tokenKey: 'stored-token'});
      final api = ApiService();
      await api.loadToken();
      api.onUnauthorized = () => signedOut++;

      await http.runWithClient(
        () => _failure(() => api.residentLogin(phoneNumber: '09171234567', password: 'x')),
        () => MockClient((_) async => http.Response(
              jsonEncode({'message': 'Please update the SERBIS app.', 'code': 'app_update_required'}),
              410,
              headers: <String, String>{'content-type': 'application/json'},
            )),
      );

      expect(signedOut, 0);
    });
  });

  group('delivery', () {
    test('reads unknown from the additive delivery field', () {
      final unknown = VerificationDelivery.fromJson(
          {'channel': 'sms', 'sent_to': '4567', 'retry_after': 60, 'delivery': 'unknown'});
      final accepted = VerificationDelivery.fromJson(
          {'channel': 'sms', 'sent_to': '4567', 'retry_after': 60, 'delivery': 'accepted'});
      final older = VerificationDelivery.fromJson(
          {'channel': 'sms', 'sent_to': '4567', 'retry_after': 60});

      expect(unknown!.unknown, isTrue);
      expect(accepted!.unknown, isFalse);
      // A server that predates the field reads as a normal send.
      expect(older!.unknown, isFalse);
    });
  });

  group('showing a number', () {
    test('E.164 and 639… read as 09…, everything else is left alone', () {
      expect(PhoneNumber.display('+639171234567'), '09171234567');
      expect(PhoneNumber.display('639171234567'), '09171234567');
      expect(PhoneNumber.display('09171234567'), '09171234567');
      expect(PhoneNumber.display('  +639171234567 '), '09171234567');
      expect(PhoneNumber.display(''), '');
      expect(PhoneNumber.display(null), '');
      expect(PhoneNumber.display('not-a-phone'), 'not-a-phone');
      expect(PhoneNumber.display('+14155550100'), '+14155550100');
    });

    test('lastFour gives what a resident needs to recognise their number', () {
      expect(PhoneNumber.lastFour('+639171234567'), '4567');
      expect(PhoneNumber.lastFour('09171234567'), '4567');
      expect(PhoneNumber.lastFour('12'), '');
      expect(PhoneNumber.lastFour(null), '');
    });
  });
}
