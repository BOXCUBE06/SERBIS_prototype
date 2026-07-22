library serbis.state.api_service;

import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class ApiService {
  static const String _baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://127.0.0.1:8000/api',
  );
  static const String _tokenKey = 'serbis_token_v1';

  String? _token;

  Future<void> loadToken() async {
    final prefs = await SharedPreferences.getInstance();
    _token = prefs.getString(_tokenKey);
  }

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
    try {
      final decoded = jsonDecode(response.body);
      if (decoded is Map<String, dynamic>) {
        return decoded;
      }
      return {'data': decoded};
    } catch (_) {
      return {};
    }
  }

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
        'role': 'resident',
        'email_address': email,
        'password': password,
        'password_confirmation': password,
      });

      if (data['errors'] != null) {
        final errors = data['errors'] as Map<String, dynamic>;
        final first = errors.values.first;
        if (first is List && first.isNotEmpty) {
          return first.first as String;
        }
      }

      if (data['message'] != null && data['token'] == null) {
        return data['message'] as String;
      }

      return null;
    } catch (e) {
      return 'Cannot connect to server. Check your internet connection.';
    }
  }

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

      throw data['message'] ?? 'Invalid credentials.';
    } on String {
      rethrow;
    } catch (e) {
      throw 'Cannot connect to server. Check your internet connection.';
    }
  }

  Future<void> logout() async {
    try {
      await _post('/logout', {});
    } catch (_) {}

    await _clearToken();
  }

  Future<List<Map<String, dynamic>>> getRequests() async {
    final data = await _get('/service-requests');
    final raw = data['data'] ?? data['requests'] ?? data.values.first;
    if (raw is List) {
      return raw.cast<Map<String, dynamic>>();
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

    final streamed = await request.send().timeout(const Duration(seconds: 30));
    final response = await http.Response.fromStream(streamed);
    return _decode(response);
  }

  Future<void> cancelRequest(int requestId) async {
    await _patch('/service-requests/$requestId', {'status': 'Cancelled'});
  }
}