import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/state/api_service.dart';

/// The base URL is a compile-time constant, so it cannot be varied inside a
/// single run. These tests assert the *relationship* between the define and
/// what the app ends up with, which makes them meaningful under both:
///
///   flutter test
///   flutter test --dart-define=API_BASE_URL=https://api.example.test/api//
///
/// The first proves a build with no define is not configured — the case that
/// used to ship silently pointing at `http://127.0.0.1:8000/api`. The second
/// proves the define is picked up and normalised.
void main() {
  const defined = String.fromEnvironment('API_BASE_URL');

  test('isConfigured reflects whether the build was given an API_BASE_URL', () {
    expect(ApiService.isConfigured, defined.isNotEmpty);
  });

  test('a build with no define has no address to fall back on', () {
    if (defined.isNotEmpty) {
      return; // Only meaningful on a plain `flutter test` run.
    }

    expect(ApiService.baseUrl, isEmpty);
  });

  test('trailing slashes are stripped so paths do not become //path', () {
    if (defined.isEmpty) {
      return; // Only meaningful when a define was supplied.
    }

    expect(ApiService.baseUrl.endsWith('/'), isFalse);
    expect(ApiService.baseUrl, defined.replaceAll(RegExp(r'/+$'), ''));
  });
}
