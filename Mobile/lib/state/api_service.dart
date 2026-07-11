/// HTTP client that connects the SERBIS Flutter app to the Laravel backend.
///
/// All API calls go through this single class. It stores the Sanctum token
/// returned after login and attaches it automatically as a Bearer header on
/// every protected request.
///
/// Base URL: set [_baseUrl] to your Laravel server address.
library serbis.state.api_service;

import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class ApiService {
  // ── Change this to your Laravel server URL ──────────────────────────────
  // Examples:
  //   Local (web/Chrome/Windows desktop) : 'http://127.0.0.1:8000/api'
  //   Local (Android emulator)           : 'http://10.0.2.2:8000/api'
  //   Local (physical device)            : 'http://YOUR_PC_LOCAL_IP:8000/api'
  //   Production                         : 'https://yourdomain.com/api'
  static const String _baseUrl = 'http://127.0.0.1:8000/api';
  // ────────────────────────────────────────────────────────────────────────

  static const String _tokenKey = 'serbis_token_v1';

  String? _token;

  // ── Token persistence ───────────────────────────────────────────────────

  /// Call once on startup (in AuthGate.initState) to restore a previous
  /// session from local storage.
  Future<void> loadToken() async {
    final prefs = await SharedPreferences.getInstance();
    _token = prefs.getString(_tokenKey);
  }

  /// Whether a resident is currently logged in.
  bool get isLoggedIn => _token != null && _token!.isNotEmpty;

  Future<void> _saveToken(String token) async {
    _token = token;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_tokenKey, token);
  }

  Future<void> _clearToken() async {
    _token = null;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_tokenKey);
  }

  // ── Shared HTTP headers ─────────────────────────────────────────────────

  Map<String, String> get _headers => {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        if (_token != null) 'Authorization': 'Bearer $_token',
      };

  // ── Low-level helpers ───────────────────────────────────────────────────

  Future<Map<String, dynamic>> _post(
    String path,
    Map<String, dynamic> body,
  ) async {
    final response = await http
        .post(
          Uri.parse('$_baseUrl$path'),
          headers: _headers,
          body: jsonEncode(body),
        )
        .timeout(const Duration(seconds: 15));
    return _decode(response);
  }

  Future<Map<String, dynamic>> _get(String path) async {
    final response = await http
        .get(Uri.parse('$_baseUrl$path'), headers: _headers)
        .timeout(const Duration(seconds: 15));
    return _decode(response);
  }

  Future<Map<String, dynamic>> _patch(String path, [Map<String, dynamic>? body]) async {
    final response = await http
        .patch(
          Uri.parse('$_baseUrl$path'),
          headers: _headers,
          body: body != null ? jsonEncode(body) : null,
        )
        .timeout(const Duration(seconds: 15));
    return _decode(response);
  }

  Map<String, dynamic> _decode(http.Response response) {
    final decoded = jsonDecode(response.body);
    if (decoded is Map<String, dynamic>) return decoded;
    return {'data': decoded};
  }

  // ── Auth ─────────────────────────────────────────────────────────────────

  /// Registers a new resident account.
  ///
  /// Returns null on success, or an error message string on failure.
  /// Matches your Laravel [AuthController.register] endpoint which expects
  /// first_name, last_name, role, email_address, password.
  Future<String?> register({
    required String firstName,
    required String lastName,
    required String email,
    required String password,
  }) async {
    try {
      final data = await _post('/register', {
        'first_name': firstName,
        'last_name': lastName,
        'role': 'resident',        // mobile app always registers as resident
        'email_address': email,
        'password': password,
        'password_confirmation': password,
      });

      // Laravel validation errors come back as { errors: { field: [...] } }
      if (data['errors'] != null) {
        final errors = data['errors'] as Map<String, dynamic>;
        // Return the first validation error message found
        final first = errors.values.first;
        if (first is List && first.isNotEmpty) return first.first as String;
      }

      if (data['message'] != null && data['token'] == null) {
        return data['message'] as String;
      }

      return null; // success
    } on Exception catch (e) {
      return 'Cannot connect to server. Check your internet connection.\n($e)';
    }
  }

  /// Logs in a resident using email + password.
  ///
  /// Calls your Laravel [AuthController.residentLogin] endpoint.
  /// Returns the resident user map on success, or throws a [String]
  /// error message on failure.
  Future<Map<String, dynamic>> residentLogin({
    required String email,
    required String password,
  }) async {
    try {
      final data = await _post('/resident/login', {
        'email_address': email,
        'password': password,
      });

      if (data['token'] != null) {
        await _saveToken(data['token'] as String);
        return (data['user'] as Map<String, dynamic>?) ?? {};
      }

      // 401 or validation failure
      throw data['message'] ?? 'Invalid credentials.';
    } on String {
      rethrow;
    } on Exception catch (e) {
      throw 'Cannot connect to server. Check your internet connection.\n($e)';
    }
  }

  /// Logs out the current resident — invalidates the Sanctum token on the
  /// server, then clears it locally.
  Future<void> logout() async {
    try {
      await _post('/logout', {});
    } on Exception {
      // If the network call fails, still clear the local token so the
      // resident isn't stuck in a logged-in state.
    }
    await _clearToken();
  }

  // ── Service requests ─────────────────────────────────────────────────────

  /// Fetches all service requests for the logged-in resident.
  Future<List<Map<String, dynamic>>> getRequests() async {
    final data = await _get('/service-requests');
    // Handle both { data: [...] } and plain array responses
    final raw = data['data'] ?? data['requests'] ?? data.values.first;
    if (raw is List) return raw.cast<Map<String, dynamic>>();
    return [];
  }

  /// Submits a new service request to the server.
  ///
  /// Matches Laravel [ServiceRequestController.store], which requires:
  ///   - service_id (int, must exist in tbl_services)
  ///   - description (string)
  ///   - valid_id (an uploaded image file: jpg/jpeg/png, max 2MB)
  ///   - required_vehicle_type (optional string, must exist in tbl_vehicles)
  ///
  /// [validIdFileBytes]/[validIdFileName] come from an image picker
  /// (e.g. package:image_picker or package:file_picker) — this endpoint
  /// needs an actual file, not just text, so it must be sent as
  /// multipart/form-data rather than JSON.
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

    final streamed = await request.send().timeout(const Duration(seconds: 30));
    final response = await http.Response.fromStream(streamed);
    return _decode(response);
  }

  /// Cancels a request by its numeric service request id (the backend's
  /// primary key — NOT the display ref number like "QR-2026-101").
  /// There's no dedicated /cancel route; this just PATCHes the status
  /// via the same [ServiceRequestController.update] used for edits.
  Future<void> cancelRequest(int requestId) async {
    await _patch('/service-requests/$requestId', {'status': 'Cancelled'});
  }
}