library serbis.state.api_service;

import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

/// A failed API call. Carries the HTTP status so callers can tell a dead
/// network (`statusCode == null`) from a real rejection, and a message that is
/// already safe to show a resident.
class ApiException implements Exception {
  final String message;
  final int? statusCode;

  const ApiException(this.message, {this.statusCode});

  /// The token was rejected. Distinct from a failed login, which the auth
  /// endpoints report as a plain message instead.
  bool get isUnauthorized => statusCode == 401;

  /// No response at all — offline, wrong base URL, or a timeout.
  bool get isNetwork => statusCode == null;

  @override
  String toString() => message;
}

class ApiService {
  static const String _baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://127.0.0.1:8000/api',
  );
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
    } catch (_) {
      // An Android restore-from-backup can leave the entry unreadable: the
      // ciphertext is restored but the Keystore key that decrypts it is not.
      // Drop it instead of failing every launch from here on.
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
    } catch (_) {
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
        Uri.parse('$_baseUrl$path'),
        headers: _headers,
        body: jsonEncode(body),
      ),
      isAuthEndpoint: isAuthEndpoint,
    );
  }

  Future<Map<String, dynamic>> _get(String path) {
    return _send(
      () => http.get(Uri.parse('$_baseUrl$path'), headers: _headers),
    );
  }

  Future<Map<String, dynamic>> _patch(
    String path, [
    Map<String, dynamic>? body,
  ]) {
    return _send(
      () => http.patch(
        Uri.parse('$_baseUrl$path'),
        headers: _headers,
        body: body != null ? jsonEncode(body) : null,
      ),
    );
  }

  /// Runs a request and normalises every failure mode into an [ApiException].
  /// Transport errors are caught here rather than by importing `dart:io`, which
  /// would break the web build — `http` throws `ClientException` there and
  /// `SocketException` on native, and both are plain `Exception`s.
  Future<Map<String, dynamic>> _send(
    Future<http.Response> Function() call, {
    bool isAuthEndpoint = false,
  }) async {
    http.Response response;

    try {
      response = await call().timeout(const Duration(seconds: 15));
    } catch (_) {
      throw const ApiException(_networkMessage);
    }

    return _decode(response, isAuthEndpoint: isAuthEndpoint);
  }

  /// Inspects the status before the body. Previously every response was decoded
  /// blindly and a failure returned `{}` or, worse, the string
  /// `"Unauthenticated."` where a list was expected — which read as "you have no
  /// requests" rather than "your session expired".
  Map<String, dynamic> _decode(
    http.Response response, {
    bool isAuthEndpoint = false,
  }) {
    final status = response.statusCode;
    final ok = status >= 200 && status < 300;

    Map<String, dynamic>? body;
    try {
      final decoded = jsonDecode(response.body);
      body = decoded is Map<String, dynamic> ? decoded : {'data': decoded};
    } catch (_) {
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
      _clearToken();
      onUnauthorized?.call();
    }

    throw ApiException(_errorMessage(body, status), statusCode: status);
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

  /// Public on the backend so the register screen can populate its picker
  /// before the resident has an account.
  Future<List<Map<String, dynamic>>> getBarangays() async {
    final data = await _get('/barangays');
    return _listFrom(data, 'barangays');
  }

  Future<void> logout() async {
    try {
      await _post('/logout', {});
    } catch (_) {
      // Best effort. The local token is dropped either way.
    }

    await _clearToken();
  }

  Future<List<Map<String, dynamic>>> getRequests() async {
    final data = await _get('/service-requests');
    return _listFrom(data, 'requests');
  }

  /// [locale] is a BCP 47 subtag ('en', 'fil'). The server falls back to English
  /// for any locale it has no rows for, so an unsupported one is safe to send.
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async {
    final data = await _get('/services?locale=$locale');
    return _listFrom(data, 'services');
  }

  /// Unwraps a collection that may arrive bare, under `data`, or under a named
  /// key depending on whether the controller paginates.
  List<Map<String, dynamic>> _listFrom(
    Map<String, dynamic> data,
    String namedKey,
  ) {
    final raw = data['data'] ??
        data[namedKey] ??
        (data.isEmpty ? null : data.values.first);

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
    final uri = Uri.parse('$_baseUrl/service-requests');
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
    } catch (_) {
      throw const ApiException(_networkMessage);
    }

    return _decode(response);
  }

  Future<void> cancelRequest(int requestId) async {
    // Resident-scoped route; the controller sets status = 'Cancelled' itself and
    // rejects anything but the owner's own Pending request. No body needed.
    await _patch('/service-requests/$requestId/cancel');
  }
}
