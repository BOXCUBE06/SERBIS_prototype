// A service the office adds in the admin panel gets a code this build has never
// seen. The app must give it the generic Details form and file it like any
// other request, with no release: this is what lets staff add services.

import 'dart:typed_data';

import 'package:file_picker/file_picker.dart' as fp;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/models/service_forms.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/screens/service_drafts.dart';
import 'package:serbis/screens/service_request_form.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';

class _FakeApi extends ApiService {
  int? sentServiceId;
  String? sentDescription;
  List<int>? sentId;

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async => [
        {'service_id': 42, 'code': 'tarpaulin-lending', 'service_name': 'Tarpaulin Lending', 'category': 'relief'},
      ];

  @override
  Future<List<Map<String, dynamic>>> getRequests() async => [];

  @override
  Future<Map<String, dynamic>> submitRequest({
    required int? serviceId,
    required String description,
    required List<int> validIdFileBytes,
    required String validIdFileName,
    List<int>? sitePhotoBytes,
    String? sitePhotoFileName,
    String? landmark,
    String? fulfillmentMethod,
    String? deliveryAddress,
    DateTime? scheduledAt,
    AmbulanceIntake? intake,
    DateTime? preferredDate,
    List<int>? letterBytes,
    String? letterFileName,
  }) async {
    sentServiceId = serviceId;
    sentDescription = description;
    sentId = validIdFileBytes;
    return {
      'request_id': 901,
      'service_id': serviceId,
      'status': 'Pending',
      'description': description,
      'created_at': DateTime.now().toIso8601String(),
      'service': {'service_id': serviceId, 'code': 'tarpaulin-lending', 'service_name': 'Tarpaulin Lending'},
    };
  }
}

const _resident = AppUser(id: '1', firstName: 'Maria', lastName: 'Santos', address: 'San Fabian', phone: '09171234567');

void main() {
  test('an unknown code takes the generic form, the default badge and the plain name', () {
    final service = ServiceCatalogItem.fromJson(const {
      'service_id': 42,
      'code': 'tarpaulin-lending',
      'service_name': 'Tarpaulin Lending',
      'category': 'relief',
    });

    expect(service.formKind, ServiceFormKind.generic);
    expect(service.displayName(true), 'Tarpaulin Lending', reason: 'no Filipino entry, so the English name');
    expect(service.icon, isNotNull);
  });

  testWidgets('an unknown code shows the Details form and files the request', (tester) async {
    final api = _FakeApi();
    final state = AppState(api);
    await state.loadServices();
    final service = state.services.single;

    final drafts = ServiceDrafts(_resident)
      ..validId = fp.PlatformFile(name: 'id.jpg', size: 3, bytes: Uint8List.fromList(const [1, 2, 3]));

    await tester.binding.setSurfaceSize(const Size(430, 932));
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: ServiceRequestForm(
          appState: state,
          user: _resident,
          service: service,
          drafts: drafts,
          onSubmitted: () {},
        ),
      ),
    ));
    await tester.pumpAndSettle();

    // The generic form's one field, and none of the guided forms' fields.
    expect(find.text('Details'), findsWidgets);
    expect(find.textContaining('Obstruction'), findsNothing);
    expect(find.textContaining('Household'), findsNothing);

    await tester.enterText(find.byType(TextField).first, 'Two tarpaulins for a roof leak, Purok 2');
    await tester.tap(find.text('Submit request'));
    await tester.pumpAndSettle();

    expect(api.sentServiceId, 42);
    expect(api.sentDescription, contains('Tarpaulin Lending'));
    expect(api.sentDescription, contains('Two tarpaulins for a roof leak, Purok 2'));
    expect(api.sentId, [1, 2, 3]);
    expect(state.requests, hasLength(1));
    expect(find.text('Request submitted'), findsOneWidget, reason: 'the confirmation sheet');
  });
}
