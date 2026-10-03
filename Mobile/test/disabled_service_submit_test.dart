// A service the office disables while a resident has its form open: the
// server refuses the submit with a `service_id` error. The store keeps the
// error for the shell's snackbar, reloads the catalogue so the tile goes, and
// names the refused service so the form can close.

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/models/service_forms.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';

class _FakeApi extends ApiService {
  _FakeApi(this.error);

  final ApiException error;
  bool disabled = false;
  int serviceLoads = 0;

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async {
    serviceLoads++;
    return [
      if (!disabled) {'service_id': 5, 'code': 'road-clearing', 'service_name': 'Road Clearing'},
      {'service_id': 6, 'code': 'debris-removal', 'service_name': 'Debris Removal'},
    ];
  }

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
    disabled = true;
    throw error;
  }
}

ServiceRequest _request() => const ServiceRequest(
      serviceId: 5,
      description: 'Tree down on the access road',
      type: ServiceType.road,
      refNo: '',
      status: ReqStatus.review,
      metaLines: [],
    );

Future<(AppState, _FakeApi)> _submit(ApiException error) async {
  final api = _FakeApi(error);
  final state = AppState(api);
  await state.loadServices();

  final result = await state.addRequest(_request(), validIdFileBytes: const [1], validIdFileName: 'id.jpg');

  expect(result, isNull);
  expect(state.requests, isEmpty, reason: 'the optimistic row is rolled back');
  return (state, api);
}

void main() {
  test('a service_id refusal reloads the catalogue and names the service', () async {
    final (state, api) = await _submit(const ApiException(
      'This service is no longer accepting new requests.',
      statusCode: 422,
      fieldErrors: {'service_id': 'This service is no longer accepting new requests.'},
    ));

    expect(api.serviceLoads, 2);
    expect(state.services.map((s) => s.id), [6]);
    expect(state.unavailableServiceId, 5);
    expect(state.takeError(), 'This service is no longer accepting new requests.');
  });

  test('any other refusal leaves the catalogue and the form alone', () async {
    final (state, api) = await _submit(const ApiException('No available vehicles at this time.', statusCode: 422));

    expect(api.serviceLoads, 1);
    expect(state.unavailableServiceId, isNull);
    expect(state.takeError(), 'No available vehicles at this time.');
  });
}
