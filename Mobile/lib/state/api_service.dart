library serbis.state.api_service;

import 'dart:convert';
import 'package:flutter/foundation.dart' show visibleForTesting;
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import '../models/service_forms.dart' show AmbulanceIntake;
import 'api_exception.dart';
import 'app_log.dart';
import 'verification_delivery.dart';

export 'api_exception.dart';
export 'verification_delivery.dart';

class ApiService {
  /// Log area for everything in this file. Every line the HTTP layer writes
  /// names the method and path it was attempting; none of them carries a
  /// request body, a token, or an uploaded file — see [AppLog].
  static const String _logArea = 'api';

  static const String _rawBaseUrl = String.fromEnvironment('API_BASE_URL');

  /// The API root, including `/api`. Supplied at build time by
  /// `--dart-define=API_BASE_URL=...`; see `Mobile/README.md` for the commands.
  ///
  /// There is deliberately no default. This used to fall back to
  /// `http://127.0.0.1:8000/api`, which on a handset is the handset itself, so
  /// a build made without the flag installed fine, launched fine, and then
  /// failed every single call with a socket error a resident cannot interpret.
  /// A missing value is now caught in `main()` before any screen is drawn — the
  /// build breaks loudly for whoever made it instead of quietly for whoever
  /// installed it. A default would also have to be an `http://` URL, which
  /// Android 9+ and iOS ATS block in release regardless.
  ///
  /// A trailing slash would turn every `'$baseUrl/service-requests'` into
  /// `//service-requests`, which some proxies read as protocol-relative.
  static final String baseUrl = _rawBaseUrl.replaceAll(RegExp(r'/+$'), '');

  /// False when the build was produced without `--dart-define=API_BASE_URL`.
  static bool get isConfigured => baseUrl.isNotEmpty;

  static const String _tokenKey = 'serbis_token_v1';

  /// Keychain on iOS, EncryptedSharedPreferences on Android. The token used to
  /// sit in plain `SharedPreferences`, which is a readable XML file on a rooted
  /// device and survives in device backups — and a resident's Sanctum token is
  /// good for 30 days (`resident_expiration` in config/sanctum.php), so a
  /// lifted one is a working credential to an account holding a government ID
  /// scan for weeks, not just until the next request.
  static const FlutterSecureStorage _secureStorage = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );

  static const String _networkMessage =
      'Cannot connect to server. Check your internet connection.';

  String? _token;

  /// Fired when a stored token is rejected, so the shell can drop to the login
  /// screen instead of showing empty lists. Never fired for the auth endpoints,
  /// where a 401 just means the password was wrong.
  void Function()? onUnauthorized;

  /// Reads the stored token, moving it out of the legacy plain-text
  /// `SharedPreferences` entry the first time this runs. Without that one-time
  /// migration every existing install would be silently logged out by the
  /// switch to secure storage.
  Future<void> loadToken() async {
    _token = await _readSecureToken();
    if (isLoggedIn) {
      return;
    }

    // Legacy read path. Keep for exactly one release, then delete this block
    // and the `shared_preferences` import with it.
    final prefs = await SharedPreferences.getInstance();
    final legacy = prefs.getString(_tokenKey);
    if (legacy == null || legacy.isEmpty) {
      return;
    }

    _token = legacy;
    // Only drop the old copy once the new one is actually written, or a failed
    // write loses the session it was migrating.
    if (await _writeSecureToken(legacy)) {
      await prefs.remove(_tokenKey);
    }
  }

  bool get isLoggedIn => _token != null && _token!.isNotEmpty;

  Future<String?> _readSecureToken() async {
    try {
      return await _secureStorage.read(key: _tokenKey);
    } catch (error) {
      // An Android restore-from-backup can leave the entry unreadable: the
      // ciphertext is restored but the Keystore key that decrypts it is not.
      // Drop it instead of failing every launch from here on.
      //
      // Worth a line: from the resident's side this is indistinguishable from
      // never having logged in, so without it a "it keeps signing me out"
      // report has nothing behind it.
      AppLog.error(_logArea, 'read stored token', error: error);
      await _secureStorage.delete(key: _tokenKey).catchError((_) {});
      return null;
    }
  }

  /// Returns whether the write landed. There is deliberately no plain-prefs
  /// fallback — that would put the token straight back where this task moved
  /// it from.
  Future<bool> _writeSecureToken(String token) async {
    try {
      await _secureStorage.write(key: _tokenKey, value: token);
      return true;
    } catch (error) {
      // The token itself is never logged, only that storing it failed. A
      // resident hitting this is logged out again on the next launch with no
      // explanation, which is otherwise invisible.
      AppLog.error(_logArea, 'store token', error: error);
      return false;
    }
  }

  Future<void> _saveToken(String token) async {
    _token = token;
    await _writeSecureToken(token);
  }

  Future<void> _clearToken() async {
    _token = null;
    await _secureStorage.delete(key: _tokenKey).catchError((_) {});
    // Also clear the legacy entry: a token issued before this release may still
    // be sitting there if the migration write failed.
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_tokenKey);
  }

  Map<String, String> get _headers {
    final headers = <String, String>{
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };

    if (_token != null) {
      headers['Authorization'] = 'Bearer $_token';
    }

    return headers;
  }

  Future<Map<String, dynamic>> _post(
    String path,
    Map<String, dynamic> body, {
    bool isAuthEndpoint = false,
  }) {
    return _send(
      () => http.post(
        Uri.parse('$baseUrl$path'),
        headers: _headers,
        body: jsonEncode(body),
      ),
      endpoint: 'POST $path',
      isAuthEndpoint: isAuthEndpoint,
    );
  }

  Future<Map<String, dynamic>> _get(String path) {
    return _send(
      () => http.get(Uri.parse('$baseUrl$path'), headers: _headers),
      endpoint: 'GET $path',
    );
  }

  Future<Map<String, dynamic>> _patch(
    String path, [
    Map<String, dynamic>? body,
  ]) {
    return _send(
      () => http.patch(
        Uri.parse('$baseUrl$path'),
        headers: _headers,
        body: body != null ? jsonEncode(body) : null,
      ),
      endpoint: 'PATCH $path',
    );
  }

  /// Runs a request and normalises every failure mode into an [ApiException].
  /// Transport errors are caught here rather than by importing `dart:io`, which
  /// would break the web build — `http` throws `ClientException` there and
  /// `SocketException` on native, and both are plain `Exception`s.
  Future<Map<String, dynamic>> _send(
    Future<http.Response> Function() call, {
    required String endpoint,
    bool isAuthEndpoint = false,
  }) async {
    http.Response response;

    try {
      response = await call().timeout(const Duration(seconds: 15));
    } catch (error) {
      // The transport error type is the whole diagnostic value here: a
      // TimeoutException, a SocketException and a ClientException mean three
      // different things (server slow, server unreachable, request malformed)
      // and the resident sees the same sentence for all three.
      AppLog.error(_logArea, endpoint, error: error, reason: 'no response');
      throw const ApiException(_networkMessage);
    }

    return _decode(response, endpoint: endpoint, isAuthEndpoint: isAuthEndpoint);
  }

  /// Inspects the status before the body. Previously every response was decoded
  /// blindly and a failure returned `{}` or, worse, the string
  /// `"Unauthenticated."` where a list was expected — which read as "you have no
  /// requests" rather than "your session expired".
  Map<String, dynamic> _decode(
    http.Response response, {
    required String endpoint,
    bool isAuthEndpoint = false,
  }) {
    final status = response.statusCode;
    final ok = status >= 200 && status < 300;

    Map<String, dynamic>? body;
    try {
      final decoded = jsonDecode(response.body);
      body = decoded is Map<String, dynamic> ? decoded : {'data': decoded};
    } catch (_) {
      // Not passed to AppLog.error: a FormatException stringifies the source it
      // failed on, and the source here is the response body. The length is
      // logged instead — it separates "empty reply" from "an HTML error page
      // where JSON was expected" without writing any of it down.
      AppLog.warn(
        _logArea,
        endpoint,
        reason: 'body was not JSON (${response.bodyBytes.length} bytes)',
      );
      body = null;
    }

    if (ok) {
      if (body == null) {
        throw ApiException(
          'Unexpected response from server.',
          statusCode: status,
        );
      }
      return body;
    }

    // A rejected stored token means the session is over. Auth endpoints are
    // exempt: there a 401 is just "wrong email or password".
    if (status == 401 && !isAuthEndpoint) {
      AppLog.warn(_logArea, endpoint, reason: 'token rejected, signing out');
      _clearToken();
      onUnauthorized?.call();
    }

    final message = _errorMessage(body, status);
    AppLog.error(_logArea, endpoint, status: status, reason: message);
    // `code` is the server's own reason, carried through so a screen can act on
    // it rather than string-matching the message a resident reads.
    // `body` is null when the reply was not JSON at all, so this cannot be
    // an unconditional lookup.
    final code = body?['code'];
    final challengeId = body?['challenge_id'];
    throw ApiException(
      message,
      statusCode: status,
      code: code is String ? code : null,
      // A refusal can still have sent a code — see [VerificationDelivery].
      delivery: VerificationDelivery.fromJson(body),
      challengeId: challengeId is String ? challengeId : null,
      fieldErrors: _fieldErrors(body),
    );
  }

  /// Laravel's `errors` map flattened to one message per field. `_errorMessage`
  /// above already picks the first of these for the banner; this keeps the
  /// field names so a screen can mark the input that was actually rejected.
  static Map<String, String> _fieldErrors(Map<String, dynamic>? body) {
    final errors = body?['errors'];
    if (errors is! Map<String, dynamic>) return const {};

    final flattened = <String, String>{};

    errors.forEach((field, messages) {
      if (messages is List && messages.isNotEmpty) {
        flattened[field] = messages.first.toString();
      } else if (messages is String) {
        flattened[field] = messages;
      }
    });

    return flattened;
  }

  /// Laravel reports validation failures under `errors` and everything else
  /// under `message`. Fall back to something a resident can act on.
  String _errorMessage(Map<String, dynamic>? body, int status) {
    final errors = body?['errors'];
    if (errors is Map<String, dynamic> && errors.isNotEmpty) {
      final first = errors.values.first;
      if (first is List && first.isNotEmpty) {
        return first.first.toString();
      }
      if (first is String) {
        return first;
      }
    }

    final message = body?['message'];
    if (message is String && message.isNotEmpty) {
      // The framework default is meaningless to a resident.
      if (message != 'Unauthenticated.') {
        return message;
      }
    }

    if (status == 401) return 'Your session expired. Please log in again.';
    if (status == 403) return 'You are not allowed to do that.';
    if (status == 404) return 'Not found.';
    if (status >= 500) return 'The server had a problem. Please try again.';
    return 'Request failed ($status).';
  }

  /// Creates the account and asks the server to send the first code. The
  /// returned outcome carries either the message to show on the form or the
  /// delivery details the code screen needs.
  Future<RegisterOutcome> register({
    required String firstName,
    String? middleName,
    required String lastName,
    required int barangayId,
    required String phoneNumber,
    required String email,
    required String password,
  }) async {
    try {
      // No 'role': it is not a column on tbl_residents and the server assigns
      // status itself. barangay_id and phone_number are both required there.
      final data = await _post(
        '/register',
        {
          'first_name': firstName,
          if (middleName != null && middleName.isNotEmpty)
            'middle_name': middleName,
          'last_name': lastName,
          'barangay_id': barangayId,
          'phone_number': phoneNumber,
          'email_address': email,
          'password': password,
          'password_confirmation': password,
        },
        isAuthEndpoint: true,
      );

      return RegisterOutcome.sent(VerificationDelivery.fromJson(data));
    } on ApiException catch (e) {
      return RegisterOutcome.failed(e.message);
    }
  }

  Future<Map<String, dynamic>> residentLogin({
    required String email,
    required String password,
  }) async {
    final data = await _post(
      '/resident/login',
      {'email_address': email, 'password': password},
      isAuthEndpoint: true,
    );

    if (data['token'] != null) {
      await _saveToken(data['token'] as String);
      return (data['user'] as Map<String, dynamic>?) ?? {};
    }

    throw const ApiException('Invalid credentials.');
  }

  /// Finishes registration. On success the server issues a token, so the
  /// resident lands signed in rather than being handed to a login form — this
  /// mirrors [residentLogin] deliberately, including saving the token.
  Future<Map<String, dynamic>> verifyEmail({
    required String email,
    required String code,
  }) async {
    final data = await _post(
      '/resident/verify-email',
      {'email_address': email, 'code': code},
      isAuthEndpoint: true,
    );

    if (data['token'] != null) {
      await _saveToken(data['token'] as String);
      return (data['user'] as Map<String, dynamic>?) ?? {};
    }

    throw const ApiException('That code was not accepted.');
  }

  /// Asks for a replacement code. The server refuses inside its cooldown with
  /// 429 and a `retry_after`, which surfaces as an [ApiException] carrying
  /// `code == 'resend_too_soon'`.
  Future<VerificationDelivery?> resendVerificationCode({
    required String email,
  }) async {
    final data = await _post(
      '/resident/verify-email/resend',
      {'email_address': email},
      isAuthEndpoint: true,
    );

    // The channel can differ from the one the last code went out on, so the
    // screen relabels itself from this rather than keeping its first answer.
    return VerificationDelivery.fromJson(data);
  }

  /// Second half of resident login: the SMS (or mail-fallback) code that came
  /// back on the `mfa_required` refusal. Mirrors [verifyEmail] — success
  /// saves the token the same way — but this is a distinct server-side code
  /// from the signup one and the two must not be confused.
  Future<Map<String, dynamic>> verifyLoginCode({
    required String challengeId,
    required String code,
  }) async {
    final data = await _post(
      '/resident/login/verify',
      {'challenge_id': challengeId, 'code': code},
      isAuthEndpoint: true,
    );

    if (data['token'] != null) {
      await _saveToken(data['token'] as String);
      return (data['user'] as Map<String, dynamic>?) ?? {};
    }

    throw const ApiException('That code was not accepted.');
  }

  /// Asks for a replacement login code against an existing challenge. Takes
  /// the challenge id, not the email/password — the resident already proved
  /// the password once to get this challenge.
  Future<VerificationDelivery?> resendLoginCode({
    required String challengeId,
  }) async {
    final data = await _post(
      '/resident/login/resend',
      {'challenge_id': challengeId},
      isAuthEndpoint: true,
    );

    return VerificationDelivery.fromJson(data);
  }

  /// Rebuilds the signed-in resident from a stored token on relaunch.
  Future<Map<String, dynamic>> me() async {
    final data = await _get('/me');
    return (data['user'] as Map<String, dynamic>?) ?? {};
  }

  /// Resident-scoped profile edit. Only the six fields the backend accepts are
  /// sent — the five contact fields plus `sms_opt_in`; `barangay_id`, `status`,
  /// `photo` and `password` are refused there and have no business being
  /// offered here — the barangay in particular is what every service request is
  /// dispatched on.
  ///
  /// Fields are omitted when null rather than sent empty, because the endpoint
  /// is a PATCH: an absent key leaves the column alone, while an empty string
  /// would clear it. `middleName` is the exception — it is nullable on the
  /// resident row, so an empty string there is a real value meaning "none".
  Future<Map<String, dynamic>> updateProfile({
    String? firstName,
    String? middleName,
    String? lastName,
    String? phoneNumber,
    String? email,
    bool? smsOptIn,
    String? currentPassword,
  }) async {
    final data = await _patch(
      '/me',
      buildProfileUpdateBody(
        firstName: firstName,
        middleName: middleName,
        lastName: lastName,
        phoneNumber: phoneNumber,
        email: email,
        smsOptIn: smsOptIn,
        currentPassword: currentPassword,
      ),
    );
    return (data['user'] as Map<String, dynamic>?) ?? {};
  }

  /// Assembles the `PATCH /me` body without sending it.
  ///
  /// Separate from [updateProfile] for the same reason as [buildSubmitRequest]:
  /// `_patch` calls `http.patch` directly, so a test that faked the service
  /// would override the very method that decides the key names — and a screen
  /// test asserting "the store was called with smsOptIn: false" proves nothing
  /// about whether `sms_opt_in` is what leaves the device.
  @visibleForTesting
  static Map<String, dynamic> buildProfileUpdateBody({
    String? firstName,
    String? middleName,
    String? lastName,
    String? phoneNumber,
    String? email,
    bool? smsOptIn,
    String? currentPassword,
  }) {
    return <String, dynamic>{
      if (firstName != null) 'first_name': firstName,
      if (middleName != null) 'middle_name': middleName.isEmpty ? null : middleName,
      if (lastName != null) 'last_name': lastName,
      if (phoneNumber != null) 'phone_number': phoneNumber,
      if (email != null) 'email_address': email,
      // Proof of knowledge, not a column. The endpoint requires it only when
      // `email_address` or `phone_number` actually moves — those are where a
      // login code is delivered, so a bearer token alone must not be enough to
      // change them. Omitted entirely on every other save, which is what keeps
      // a surname correction from asking for a password.
      if (currentPassword != null) 'current_password': currentPassword,
      // Sent as a JSON boolean, not '1'/'0'. The backend's rule accepts both,
      // but the column is boolean and the response is cast to one, so anything
      // else here would make the value that goes out differ in type from the
      // value that comes back.
      if (smsOptIn != null) 'sms_opt_in': smsOptIn,
    };
  }

  /// Public on the backend so the register screen can populate its picker
  /// before the resident has an account.
  Future<List<Map<String, dynamic>>> getBarangays() async {
    final data = await _get('/barangays');
    return listFrom(data);
  }

  Future<void> logout() async {
    try {
      await _post('/logout', {});
    } catch (_) {
      // Best effort, and already logged by _send/_decode. The local token is
      // dropped either way, so there is nothing further to record — but note
      // the server-side token is not revoked by this failure. It is not
      // permanent either: it still expires on its own after 30 days
      // (resident_expiration in config/sanctum.php), just not immediately.
    }

    await _clearToken();
  }

  Future<List<Map<String, dynamic>>> getRequests() async {
    final data = await _get('/service-requests');
    return listFrom(data);
  }

  /// [locale] is a BCP 47 subtag ('en', 'fil'). The server falls back to English
  /// for any locale it has no rows for, so an unsupported one is safe to send.
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async {
    final data = await _get('/services?locale=$locale');
    return listFrom(data);
  }

  Future<List<Map<String, dynamic>>> getInfoMaterials() async {
    final data = await _get('/info-materials');
    return listFrom(data);
  }

  /// The SMS blasts this resident actually received. Admin accounts get a 403
  /// here — the route is resident-only — which surfaces as an ApiException the
  /// caller reports, not as an empty advisory list.
  Future<List<Map<String, dynamic>>> getAdvisories() async {
    final data = await _get('/advisories');
    return listFrom(data);
  }

  /// Which Ambulance units the availability endpoint reports free for
  /// `[start, end)` — the same check `POST /service-requests` re-runs under a
  /// lock at submit time. This call is advisory only: a resident sees it
  /// before committing to a date and time, but the server's own check at
  /// submission is the one that decides whether the booking is accepted.
  Future<List<Map<String, dynamic>>> getAmbulanceAvailability({
    required DateTime start,
    required DateTime end,
  }) async {
    final query = <String, String>{
      'start': start.toUtc().toIso8601String(),
      'end': end.toUtc().toIso8601String(),
    };
    final data = await _get(
      '/ambulance-availability?${Uri(queryParameters: query).query}',
    );
    return listFrom(data);
  }

  /// Fetches a published material's bytes from its absolute `full_url`, which
  /// points at the public storage disk rather than at `/api`. The auth header
  /// goes along anyway: it costs nothing on a public file and keeps working if
  /// the route is ever moved behind Sanctum.
  ///
  /// A longer timeout than the JSON calls — this is a file over a rural
  /// connection, and 15 s would fail a download that was progressing fine.
  Future<List<int>> downloadFile(String url) async {
    http.Response response;

    try {
      response = await http
          .get(Uri.parse(url), headers: _headers)
          .timeout(const Duration(seconds: 60));
    } catch (error) {
      // The URL is not logged. It is a `full_url` off the public storage disk,
      // so it carries no credential — but it names the exact document this
      // resident was reading, and the point of the offline library is that
      // people read evacuation and health material privately.
      AppLog.error(_logArea, 'download material', error: error,
          reason: 'no response');
      throw const ApiException(_networkMessage);
    }

    if (response.statusCode < 200 || response.statusCode >= 300) {
      AppLog.error(_logArea, 'download material',
          status: response.statusCode, reason: 'rejected');
      throw ApiException(
        'Could not download this file.',
        statusCode: response.statusCode,
      );
    }

    return response.bodyBytes;
  }

  /// Unwraps a list response. Every collection endpoint this app calls arrives
  /// under `data`: the paginating controllers emit it themselves, Laravel's
  /// resource collections wrap in it, and `_decode` wraps a bare JSON array in
  /// it too. There used to be fallbacks to a named key and then to
  /// `values.first`; both were guesses at a shape the backend never sends, and
  /// `values.first` threw `Bad state: No element` on any non-JSON body — an
  /// nginx error page or a captive-portal interstitial decoded to `{}`.
  ///
  /// A missing `data` now yields an empty list rather than an exception,
  /// because a caller showing "nothing yet" is recoverable where a crash on the
  /// Track screen is not. Malformed rows are dropped individually so one bad
  /// record does not discard the whole response.
  @visibleForTesting
  static List<Map<String, dynamic>> listFrom(Map<String, dynamic> data) {
    final raw = data['data'];

    if (raw is List) {
      return raw.whereType<Map<String, dynamic>>().toList();
    }

    return [];
  }

  /// Assembles the multipart POST without sending it.
  ///
  /// Separate from [submitRequest] so the body can be asserted on: a
  /// `MultipartRequest` builds its own `http.Client` inside `send()`, so there
  /// is no seam to fake, and the one thing worth testing here is which parts
  /// end up attached.
  @visibleForTesting
  http.MultipartRequest buildSubmitRequest({
    required int serviceId,
    required String description,
    required List<int> validIdFileBytes,
    required String validIdFileName,
    String? requiredVehicleType,
    List<int>? sitePhotoBytes,
    String? sitePhotoFileName,
    DateTime? scheduledAt,
    AmbulanceIntake? intake,
  }) {
    final uri = Uri.parse('$baseUrl/service-requests');
    final request = http.MultipartRequest('POST', uri);

    request.headers.addAll({
      'Accept': 'application/json',
      if (_token != null) 'Authorization': 'Bearer $_token',
    });

    request.fields['service_id'] = serviceId.toString();

    if (intake != null) {
      // No `description` for an ambulance request. The server composes it from
      // exactly these fields and ignores whatever a client sends, so attaching
      // one would put a second composer on the wire — the two would drift, and
      // the resident's Track screen would disagree with the dispatcher's panel
      // about the same request.
      request.fields.addAll(intake.toFields());

      // Indexed keys, not a repeated bare `patient_relatives[]`: `fields` is a
      // Map and cannot hold a duplicate name, and sending them as file parts
      // would land them in $request->file() where the `patient_relatives.*`
      // rule never looks. `patient_relatives[0]` is what PHP parses back into
      // the array that rule validates. Blank slots are already dropped by
      // AmbulanceIntake.from, so nothing empty reaches here.
      for (var i = 0; i < intake.relatives.length; i++) {
        request.fields['patient_relatives[$i]'] = intake.relatives[i];
      }
    } else {
      request.fields['description'] = description;
    }

    if (requiredVehicleType != null && requiredVehicleType.isNotEmpty) {
      request.fields['required_vehicle_type'] = requiredVehicleType;
    }
    // UTC with a 'Z' suffix, never a naive local string. The server honours an
    // offset-carrying string as the real instant it names; a bare
    // "2026-09-01 09:00:00" would instead be read as Manila wall clock,
    // correct only by coincidence when the resident's device happens to be
    // set to Philippine time and silently wrong the moment it is not.
    if (scheduledAt != null) {
      request.fields['scheduled_at'] = scheduledAt.toUtc().toIso8601String();
    }

    request.files.add(
      http.MultipartFile.fromBytes(
        'valid_id',
        validIdFileBytes,
        filename: validIdFileName,
      ),
    );

    // Optional, and the part must be absent rather than empty when there is no
    // photo: `site_photo` is `nullable|file` server-side, so a zero-byte part
    // is a 422 on a request that should have been accepted without one.
    if (sitePhotoBytes != null &&
        sitePhotoBytes.isNotEmpty &&
        sitePhotoFileName != null &&
        sitePhotoFileName.isNotEmpty) {
      request.files.add(
        http.MultipartFile.fromBytes(
          'site_photo',
          sitePhotoBytes,
          filename: sitePhotoFileName,
        ),
      );
    }

    return request;
  }

  Future<Map<String, dynamic>> submitRequest({
    required int serviceId,
    required String description,
    required List<int> validIdFileBytes,
    required String validIdFileName,
    String? requiredVehicleType,
    List<int>? sitePhotoBytes,
    String? sitePhotoFileName,
    DateTime? scheduledAt,
    AmbulanceIntake? intake,
  }) async {
    final request = buildSubmitRequest(
      serviceId: serviceId,
      description: description,
      validIdFileBytes: validIdFileBytes,
      validIdFileName: validIdFileName,
      requiredVehicleType: requiredVehicleType,
      sitePhotoBytes: sitePhotoBytes,
      sitePhotoFileName: sitePhotoFileName,
      scheduledAt: scheduledAt,
      intake: intake,
    );

    http.Response response;
    try {
      final streamed =
          await request.send().timeout(const Duration(seconds: 30));
      response = await http.Response.fromStream(streamed);
    } catch (error) {
      // Neither the description nor either image is logged — the description is
      // free text a resident typed under duress (a patient's condition, a
      // callback number), one image is a government ID and the other can show a
      // house and its surroundings. Only the size of the upload, which is what
      // distinguishes a timeout on large photos from a dead connection. The
      // total is what matters now that there can be two files: a submit that
      // times out at 6MB and one that times out at 2MB are different problems.
      final uploadBytes =
          request.files.fold<int>(0, (sum, file) => sum + file.length);
      AppLog.error(_logArea, 'POST /service-requests', error: error,
          reason: 'no response, $uploadBytes byte upload'
              '${request.files.length > 1 ? ' in ${request.files.length} files' : ''}');
      throw const ApiException(_networkMessage);
    }

    return _decode(response, endpoint: 'POST /service-requests');
  }

  /// Replaces the signed-in resident's profile photo. Returns the updated
  /// resident row, so the caller does not have to re-fetch `/me` to learn that
  /// `has_photo` is now true.
  Future<Map<String, dynamic>> uploadProfilePhoto({
    required List<int> bytes,
    required String fileName,
  }) async {
    final uri = Uri.parse('$baseUrl/me/photo');
    final request = http.MultipartRequest('POST', uri);

    request.headers.addAll({
      'Accept': 'application/json',
      if (_token != null) 'Authorization': 'Bearer $_token',
    });

    request.files.add(
      http.MultipartFile.fromBytes('photo', bytes, filename: fileName),
    );

    http.Response response;
    try {
      final streamed =
          await request.send().timeout(const Duration(seconds: 30));
      response = await http.Response.fromStream(streamed);
    } catch (error) {
      // The image itself is never logged — it is a photograph of the resident.
      // Only its size, which separates a timeout on a large upload from a dead
      // connection.
      AppLog.error(_logArea, 'POST /me/photo', error: error,
          reason: 'no response, ${bytes.length} byte upload');
      throw const ApiException(_networkMessage);
    }

    final body = _decode(response, endpoint: 'POST /me/photo');
    return (body['user'] as Map<String, dynamic>?) ?? {};
  }

  /// Drops the photo and the file behind it, returning the resident to initials.
  Future<Map<String, dynamic>> deleteProfilePhoto() async {
    final body = await _send(
      () => http.delete(Uri.parse('$baseUrl/me/photo'), headers: _headers),
      endpoint: 'DELETE /me/photo',
    );
    return (body['user'] as Map<String, dynamic>?) ?? {};
  }

  /// The image bytes for a resident's profile photo, or null when there is
  /// nothing to show.
  ///
  /// Bytes rather than a URL for `Image.network`: the route is behind
  /// `auth:sanctum`, and on the web build an `<img src>` carries no
  /// Authorization header, so a URL would 401 on exactly one of the two
  /// platforms the app ships to.
  Future<List<int>?> fetchProfilePhoto(String residentId) async {
    final uri = Uri.parse('$baseUrl/residents/$residentId/photo');
    const endpoint = 'GET /residents/{id}/photo';

    http.Response response;
    try {
      response = await http
          .get(uri, headers: {
            'Accept': '*/*',
            if (_token != null) 'Authorization': 'Bearer $_token',
          })
          .timeout(const Duration(seconds: 15));
    } catch (error) {
      AppLog.error(_logArea, endpoint, error: error, reason: 'no response');
      return null;
    }

    if (response.statusCode == 200) {
      return response.bodyBytes;
    }

    // A 404 is the ordinary answer for a resident who has not uploaded one, so
    // it is not an error. Anything else is worth a line, but never a thrown
    // exception: a missing avatar must not take a screen down with it.
    if (response.statusCode != 404) {
      AppLog.warn(_logArea, endpoint, reason: 'status ${response.statusCode}');
    }

    return null;
  }

  Future<void> cancelRequest(int requestId) async {
    // Resident-scoped route; the controller sets status = 'Cancelled' itself and
    // rejects anything but the owner's own Pending request. No body needed.
    await _patch('/service-requests/$requestId/cancel');
  }

  /// The equipment catalogue a resident can borrow from. Plain `auth:sanctum`,
  /// not `is.admin` — see `routes/api.php` — so this is reachable with a
  /// resident token.
  Future<List<Map<String, dynamic>>> getEquipments() async {
    final data = await _get('/equipments');
    return listFrom(data);
  }

  /// The resident's own borrow requests. `EquipmentBorrowingController::index`
  /// scopes this to `resident_id` itself when the caller is a Resident, so
  /// there is no client-side filtering to get wrong.
  Future<List<Map<String, dynamic>>> getBorrowings() async {
    final data = await _get('/borrowings');
    return listFrom(data);
  }

  /// Files a new equipment loan. The server assigns `resident_id` from the
  /// token and `status: 'Pending'` itself — nothing here can put a request in
  /// any other state or on any other resident's account.
  Future<Map<String, dynamic>> submitBorrowRequest({
    required int equipmentId,
    required int quantity,
    required String purpose,
  }) async {
    return _post('/borrowings', {
      'equipment_id': equipmentId,
      'quantity': quantity,
      'purpose': purpose,
    });
  }
}
