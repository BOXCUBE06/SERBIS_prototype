// The verifier on a Library row (MDRRMO feedback, 2026-09-18): a verified
// material names who checked it and in what capacity, on the row itself. An
// unverified one shows nothing, and the name survives the offline cache.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/info_material.dart';
import 'package:serbis/screens/library_screen.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/material_cache.dart';
import 'package:serbis/state/request_store.dart';

InfoMaterial _material({
  bool verified = false,
  String? name,
  String? role,
}) =>
    InfoMaterial(
      id: 1,
      title: 'Evacuation Center Map',
      fileType: 'pdf',
      sizeBytes: 2048,
      url: 'https://api.test/f/1.pdf',
      verified: verified,
      verifiedByName: name,
      verifiedByRole: role,
    );

Future<void> _pumpLibrary(WidgetTester tester, InfoMaterial material) async {
  // Wide on purpose: tests draw text in the Ahem font, one full em per glyph,
  // which overflows the badge on a phone-width row that a real font fits.
  tester.view.physicalSize = const Size(3000, 3000);
  tester.view.devicePixelRatio = 3.0;
  addTearDown(tester.view.reset);

  final state = AppState(ApiService());
  state.materials.add(material);

  await tester.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: LibraryScreen(
          appState: state,
          onOpenNotifications: () {},
          onOpenProfile: () {},
        ),
      ),
    ),
  );

  await tester.scrollUntilVisible(find.text('Evacuation Center Map'), 300);
  await tester.pumpAndSettle();
}

void main() {
  group('verifierLabel', () {
    test('joins name and role', () {
      expect(
        _material(verified: true, name: 'Dr. Ramos', role: 'Emergency physician').verifierLabel,
        'Dr. Ramos — Emergency physician',
      );
    });

    test('is null when the material is not verified, even with a name present', () {
      expect(_material(name: 'Dr. Ramos', role: 'Emergency physician').verifierLabel, isNull);
    });

    test('is null for a verified material the server sent no verifier for', () {
      expect(_material(verified: true).verifierLabel, isNull);
      expect(_material(verified: true, name: '', role: '').verifierLabel, isNull);
    });
  });

  group('Library row', () {
    testWidgets('names the verifier and their role on a verified material', (tester) async {
      await _pumpLibrary(
        tester,
        _material(verified: true, name: 'Dr. Ramos', role: 'Emergency physician'),
      );

      expect(find.text('Verified by Dr. Ramos — Emergency physician'), findsOneWidget);
      expect(find.text('Verified'), findsOneWidget);
    });

    testWidgets('shows nothing about a verifier on an unverified material', (tester) async {
      await _pumpLibrary(tester, _material());

      expect(find.textContaining('Verified'), findsNothing);
      expect(find.textContaining('Sinuri'), findsNothing);
    });
  });

  test('a saved material keeps its verifier through the offline index', () {
    final entry = CachedMaterial(
      id: 1,
      title: 'Evacuation Center Map',
      fileType: 'pdf',
      sizeBytes: 2048,
      path: '/documents/materials/1.pdf',
      savedAt: DateTime(2026, 9, 18),
      verified: true,
      verifiedByName: 'Dr. Ramos',
      verifiedByRole: 'Emergency physician',
    );

    final restored = CachedMaterial.fromJson(entry.toJson())!;

    expect(restored.toMaterial().verifierLabel, 'Dr. Ramos — Emergency physician');
  });

  test('an index entry written before names were carried reads as unnamed', () {
    final restored = CachedMaterial.fromJson({
      'id': 1,
      'path': '/documents/materials/1.pdf',
      'verified': true,
    })!;

    expect(restored.verified, isTrue);
    expect(restored.toMaterial().verifierLabel, isNull);
  });
}
