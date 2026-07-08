/// Central HTTP client for the SERBIS app.
///
/// All requests go through this class. It stores the auth token
/// returned by Laravel Sanctum after login/register and attaches
/// it automatically to every protected request.
library serbis.state.api_service;

import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class ApiService {
  // ── Change this to your Laravel server URL ──────────────────────
  static const String _baseUrl = 'http://YOUR_LARAVEL_IP_OR_DOMAIN/api';
  // ────────────────────────────────────────────────────────────────

  static const String _tokenKey = 'serbis_auth_token';

  String? _token;

  // ── Token management ─────────────────────────────────────────────

  /// Loads a previously saved token from local storage.
  /// Call this once on app startup (in AuthGate.initState).
  Future<void> loadToken() async {
    final prefs = await SharedPreferences.getInstance();
    _token = prefs.getString(_tokenKey);
  }

  /// Returns true if the user is currently logged in.
  bool get isLoggedIn => _token != null;

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

  // ── HTTP helpers ──────────────────────────────────────────────────

  Map<String, String> get _headers => {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        if (_token != null) 'Authorization': 'Bearer $_token',
      };

  Future<Map<String, dynamic>> _post(
    String path,
    Map<String, dynamic> body,
  ) async {
    final response = await http.post(
      Uri.parse('$_baseUrl$path'),
      headers: _headers,
      body: jsonEncode(body),
    );
    return jsonDecode(response.body) as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> _get(String path) async {
    final response = await http.get(
      Uri.parse('$_baseUrl$path'),
      headers: _headers,
    );
    return jsonDecode(response.body) as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> _patch(String path) async {
    final response = await http.patch(
      Uri.parse('$_baseUrl$path'),
      headers: _headers,
    );
    return jsonDecode(response.body) as Map<String, dynamic>;
  }

  // ── Auth ──────────────────────────────────────────────────────────

  /// Registers a new account. Returns null on success, or an error
  /// message string if the server rejected the request.
  Future<String?> register({
    required String name,
    required String phone,
    required String address,
    required String password,
  }) async {
    try {
      final data = await _post('/register', {
        'name': name,
        'phone': phone,
        'address': address,
        'password': password,
        'password_confirmation': password,
      });

      if (data['token'] != null) {
        // Some setups return the token immediately after register.
        // If yours does, save it here instead of requiring a login step.
      }

      if (data['errors'] != null || data['message'] != null) {
        return data['message'] ?? 'Registration failed.';
      }

      return null; // success
    } catch (e) {
      return 'Cannot connect to server. Please check your connection.';
    }
  }

  /// Logs in with phone + password. Returns the logged-in user map
  /// on success, or throws a string error message on failure.
  Future<Map<String, dynamic>> login({
    required String phone,
    required String password,
  }) async {
    try {
      final data = await _post('/login', {
        'phone': phone,
        'password': password,
      });

      if (data['token'] != null) {
        await _saveToken(data['token'] as String);
        return data['user'] as Map<String, dynamic>;
      }

      throw data['message'] ?? 'Incorrect phone number or password.';
    } catch (e) {
      if (e is String) rethrow;
      throw 'Cannot connect to server. Please check your connection.';
    }
  }

  /// Logs out the current user (invalidates the Sanctum token).
  Future<void> logout() async {
    try {
      await _post('/logout', {});
    } catch (_) {
      // Even if the server call fails, clear the local token.
    }
    await _clearToken();
  }

  /// Returns the currently logged-in user's data.
  Future<Map<String, dynamic>> getUser() async {
    return _get('/user');
  }

  // ── Service requests ──────────────────────────────────────────────

  /// Fetches all service requests for the logged-in resident.
  Future<List<Map<String, dynamic>>> getRequests() async {
    final data = await _get('/requests');
    final list = data['data'] ?? data['requests'] ?? data;
    return (list as List).cast<Map<String, dynamic>>();
  }

  /// Submits a new service request.
  Future<Map<String, dynamic>> submitRequest({
    required String type,
    required String refNo,
    required List<String> metaLines,
  }) async {
    return _post('/requests', {
      'type': type,
      'ref_no': refNo,
      'meta': metaLines,
    });
  }

  /// Cancels a request by its reference number.
  Future<void> cancelRequest(String refNo) async {
    await _patch('/requests/$refNo/cancel');
  }
}