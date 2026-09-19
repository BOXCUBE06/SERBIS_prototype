// PurokAutocompleteField — the type-ahead source for purok/street fields
// (MDRRMO feedback, 2026-09-19). No seed data: suggestions come from
// UserStore.puroks(barangayId), which reads real entries other residents of
// that barangay already typed.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/widgets/purok_field.dart';

class _FakeApi extends ApiService {
  Map<int, List<String>> puroksByBarangay = {};
  int getPuroksCalls = 0;
  int? lastRequestedBarangayId;

  @override
  Future<List<String>> getPuroks(int barangayId) async {
    getPuroksCalls++;
    lastRequestedBarangayId = barangayId;
    return puroksByBarangay[barangayId] ?? const [];
  }
}

Future<void> _pump(
  WidgetTester tester, {
  required TextEditingController controller,
  required UserStore userStore,
  int? barangayId,
}) async {
  await tester.pumpWidget(MaterialApp(
    home: Scaffold(
      body: PurokAutocompleteField(
        controller: controller,
        userStore: userStore,
        barangayId: barangayId,
      ),
    ),
  ));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('asks for no suggestions when no barangay is chosen yet',
      (tester) async {
    final api = _FakeApi();
    final controller = TextEditingController();
    addTearDown(controller.dispose);

    await _pump(tester, controller: controller, userStore: UserStore(api));

    expect(api.getPuroksCalls, 0);
  });

  testWidgets('fetches suggestions for the given barangay once it is known',
      (tester) async {
    final api = _FakeApi()
      ..puroksByBarangay = {1: ['Purok 3', 'Purok 7']};
    final controller = TextEditingController();
    addTearDown(controller.dispose);

    await _pump(tester, controller: controller, userStore: UserStore(api), barangayId: 1);

    expect(api.getPuroksCalls, 1);
    expect(api.lastRequestedBarangayId, 1);
  });

  group('matchingPuroks', () {
    test('matches a substring, case-insensitively', () {
      final result = matchingPuroks(['Purok 3', 'Purok 7', 'Purok 3 Extension'], 'purok 3');
      expect(result, unorderedEquals(['Purok 3', 'Purok 3 Extension']));
    });

    test('a blank query offers nothing — typed suggestions, not a dropdown', () {
      expect(matchingPuroks(['Purok 3', 'Purok 7'], ''), isEmpty);
      expect(matchingPuroks(['Purok 3', 'Purok 7'], '   '), isEmpty);
    });

    test('no suggestions to search means no matches regardless of query', () {
      expect(matchingPuroks([], 'Purok'), isEmpty);
    });

    test('no match is an empty result, not every suggestion', () {
      expect(matchingPuroks(['Purok 3', 'Purok 7'], 'Zaragoza'), isEmpty);
    });
  });

  testWidgets('typing into the field updates the controller normally',
      (tester) async {
    final api = _FakeApi()
      ..puroksByBarangay = {1: ['Purok 3', 'Purok 7']};
    final controller = TextEditingController();
    addTearDown(controller.dispose);

    await _pump(tester, controller: controller, userStore: UserStore(api), barangayId: 1);

    await tester.tap(find.byType(TextField));
    await tester.enterText(find.byType(TextField), 'Purok 9, near the chapel');
    await tester.pumpAndSettle();

    expect(controller.text, 'Purok 9, near the chapel');
  });

  testWidgets('changing the barangay drops the old suggestions and asks for new ones',
      (tester) async {
    final api = _FakeApi()
      ..puroksByBarangay = {
        1: ['Purok 3'],
        2: ['Purok 9'],
      };
    final controller = TextEditingController();
    addTearDown(controller.dispose);

    await _pump(tester, controller: controller, userStore: UserStore(api), barangayId: 1);
    await _pump(tester, controller: controller, userStore: UserStore(api), barangayId: 2);

    expect(api.getPuroksCalls, 2);
    expect(api.lastRequestedBarangayId, 2);
  });
}
