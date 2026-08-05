library serbis.state.api_service;

import 'dart:convert';
import 'package:flutter/foundation.dart' show visibleForTesting;
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import 'api_exception.dart';
import 'app_log.dart';

export 'api_exception.dart';

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
  /// device and survives in device backups — and Sanctum tokens never expire,
  /// so a lifted one is a permanent credential to an account holding a
  /// government ID scan.
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
    throw ApiException(message, statusCode: status);
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

  Future<String?> register({
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
      await _post(
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

      return null;
    } on ApiException catch (e) {
      return e.message;
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

  /// Rebuilds the signed-in resident from a stored token on relaunch.
  Future<Map<String, dynamic>> me() async {
    final data = await _get('/me');
    return (data['user'] as Map<String, dynamic>?) ?? {};
  }

  /// Resident-scoped profile edit. Only the five fields the backend accepts are
  /// sent; `barangay_id`, `status`, `photo` and `password` are refused there and
  /// have no business being offered here — the barangay in particular is what
  /// every service request is dispatched on.
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
  }) async {
    final body = <String, dynamic>{
      if (firstName != null) 'first_name': firstName,
      if (middleName != null) 'middle_name': middleName.isEmpty ? null : middleName,
      if (lastName != null) 'last_name': lastName,
      if (phoneNumber != null) 'phone_number': phoneNumber,
      if (email != null) 'email_address': email,
    };

    final data = await _patch('/me', body);
    return (data['user'] as Map<String, dynamic>?) ?? {};
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
      // the server-side token survives, and Sanctum tokens do not expire.
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

  Future<Map<String, dynamic>> submitRequest({
    required int serviceId,
    required String description,
    required List<int> validIdFileBytes,
    required String validIdFileName,
    String? requiredVehicleType,
  }) async {
    final uri = Uri.parse('$baseUrl/service-requests');
    final request = http.MultipartRequest('POST', uri);

    request.headers.addAll({
      'Accept': 'application/json',
      if (_token != null) 'Authorization': 'Bearer $_token',
    });

    request.fields['service_id'] = serviceId.toString();
    request.fields['description'] = description;
    if (requiredVehicleType != null && requiredVehicleType.isNotEmpty) {
      request.fields['required_vehicle_type'] = requiredVehicleType;
    }

    request.files.add(
      http.MultipartFile.fromBytes(
        'valid_id',
        validIdFileBytes,
        filename: validIdFileName,
      ),
    );

    http.Response response;
    try {
      final streamed =
          await request.send().timeout(const Duration(seconds: 30));
      response = await http.Response.fromStream(streamed);
    } catch (error) {
      // Neither the description nor the ID image is logged — the description is
      // free text a resident typed under duress (a patient's condition, a
      // callback number) and the image is a government ID. Only the size of the
      // upload, which is what distinguishes a timeout on a large photo from a
      // dead connection.
      AppLog.error(_logArea, 'POST /service-requests', error: error,
          reason: 'no response, ${validIdFileBytes.length} byte upload');
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
}
