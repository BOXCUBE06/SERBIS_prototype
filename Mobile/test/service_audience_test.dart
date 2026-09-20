// The server leaves out any service this kind of account may not request, and
// says separately whether Equipment Borrowing and "Others" — which are not
// service rows — are open to it. These tests pin that the app reads that, and
// that a response without it (an older server) changes nothing.

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _AudienceApi extends ApiService {
  _AudienceApi(this._audience);

  final ({bool equipmentBorrowing, bool others}) _audience;

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async {
    serviceAudience = _audience;
    return const [];
  }
}

Future<ApiService> _apiAnswering(Map<String, dynamic> body) async {
  final api = ApiService();
  await http.runWithClient(
    () => api.getServices(),
    () => MockClient((_) async => http.Response(jsonEncode(body), 200)),
  );
  return api;
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues(<String, Object>{});
  });

  test('borrowing and Others are offered until the server says otherwise', () {
    final state = AppState(ApiService());

    expect(state.borrowingAllowed, isTrue);
    expect(state.othersAllowed, isTrue);
  });

  test('a catalogue that closes both leaves the app offering neither', () async {
    final state = AppState(_AudienceApi((equipmentBorrowing: false, others: false)));

    await state.loadServices();

    expect(state.borrowingAllowed, isFalse);
    expect(state.othersAllowed, isFalse);
  });

  test('each flag is read on its own', () async {
    final state = AppState(_AudienceApi((equipmentBorrowing: true, others: false)));

    await state.loadServices();

    expect(state.borrowingAllowed, isTrue);
    expect(state.othersAllowed, isFalse);
  });

  test('the audience block in the response is parsed', () async {
    final api = await _apiAnswering({
      'data': <Map<String, dynamic>>[],
      'audience': {'equipment_borrowing': false, 'others': true},
    });

    expect(api.serviceAudience.equipmentBorrowing, isFalse);
    expect(api.serviceAudience.others, isTrue);
  });

  test('a response with no audience block changes nothing', () async {
    final api = await _apiAnswering({'data': <Map<String, dynamic>>[]});

    expect(api.serviceAudience.equipmentBorrowing, isTrue);
    expect(api.serviceAudience.others, isTrue);
  });
}
