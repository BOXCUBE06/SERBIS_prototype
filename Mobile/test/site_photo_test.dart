// The backend has accepted an optional `site_photo` on POST /service-requests
// since the endpoint work landed, and no client ever sent one — the multipart
// body carried `valid_id` and nothing else. These cover the part that was
// missing: which files end up attached, and that the optional one survives the
// trip from the form through the store to the request body.

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/models/service_forms.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';

/// Records what `addRequest` forwarded, and answers with a row the store can
/// treat as confirmed.
class _RecordingApi extends ApiService {
  List<int>? sitePhotoBytes;
  String? sitePhotoFileName;
  List<int>? validIdFileBytes;
  Object? error;

  @override
  Future<List<Map<String, dynamic>>> getRequests() async =>
      <Map<String, dynamic>>[];

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async =>
      <Map<String, dynamic>>[];

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
    this.validIdFileBytes = validIdFileBytes;
    this.sitePhotoBytes = sitePhotoBytes;
    this.sitePhotoFileName = sitePhotoFileName;

    if (error != null) {
      throw error!;
    }

    return <String, dynamic>{
      'request_id': 77,
      'service_id': serviceId,
      'description': description,
      'status': 'Pending',
    };
  }
}

ServiceRequest _draft() => const ServiceRequest(
      id: null,
      serviceId: 1,
      description: 'Tree across the barangay road',
      type: ServiceType.road,
      refNo: '',
      status: ReqStatus.review,
      metaLines: <String>[],
    );

final List<int> _idBytes = <int>[1, 2, 3];
final List<int> _photoBytes = <int>[9, 9, 9, 9];

/// Field names of every file part on a built request, in the order attached.
List<String> _fields(ApiService api, {List<int>? photo, String? photoName}) =>
    api
        .buildSubmitRequest(
          serviceId: 1,
          description: 'Tree across the barangay road',
          validIdFileBytes: _idBytes,
          validIdFileName: 'id.jpg',
          sitePhotoBytes: photo,
          sitePhotoFileName: photoName,
        )
        .files
        .map((file) => file.field)
        .toList();

void main() {
  group('the multipart body', () {
    test('carries only the valid ID when no site photo was attached', () {
      // The behaviour before this change, and still the common case: the
      // upload is optional and most requests are filed without one.
      expect(_fields(ApiService()), <String>['valid_id']);
    });

    test('carries the site photo when one was attached', () {
      final request = ApiService().buildSubmitRequest(
        serviceId: 1,
        description: 'Tree across the barangay road',
        validIdFileBytes: _idBytes,
        validIdFileName: 'id.jpg',
        sitePhotoBytes: _photoBytes,
        sitePhotoFileName: 'scene.png',
      );

      final photo =
          request.files.singleWhere((file) => file.field == 'site_photo');

      // The field name is the contract with `store()`'s validator; a typo here
      // is a silently ignored upload, not an error.
      expect(photo.filename, 'scene.png');
      expect(photo.length, _photoBytes.length);
      expect(request.files.map((f) => f.field), contains('valid_id'));
    });

    test('omits the part rather than sending an empty one', () {
      // `site_photo` is `nullable|file` server-side. A zero-byte or nameless
      // part is not "no photo" to Laravel — it is a file that fails `mimes:`,
      // so the whole request 422s over an attachment nobody required.
      expect(_fields(ApiService(), photo: <int>[], photoName: 'scene.png'),
          <String>['valid_id']);
      expect(_fields(ApiService(), photo: _photoBytes, photoName: null),
          <String>['valid_id']);
      expect(_fields(ApiService(), photo: _photoBytes, photoName: ''),
          <String>['valid_id']);
      expect(_fields(ApiService(), photo: null, photoName: 'scene.png'),
          <String>['valid_id']);
    });
  });

  group('addRequest', () {
    test('forwards an attached site photo to the API', () async {
      final api = _RecordingApi();
      final state = AppState(api);

      final confirmed = await state.addRequest(
        _draft(),
        validIdFileBytes: _idBytes,
        validIdFileName: 'id.jpg',
        sitePhotoBytes: _photoBytes,
        sitePhotoFileName: 'scene.png',
      );

      expect(confirmed, isNotNull);
      expect(api.sitePhotoBytes, _photoBytes);
      expect(api.sitePhotoFileName, 'scene.png');
    });

    test('sends nothing extra when no site photo was attached', () async {
      final api = _RecordingApi();
      final state = AppState(api);

      await state.addRequest(
        _draft(),
        validIdFileBytes: _idBytes,
        validIdFileName: 'id.jpg',
      );

      expect(api.sitePhotoBytes, isNull);
      expect(api.sitePhotoFileName, isNull);
      // The required half is unaffected by the new optional one.
      expect(api.validIdFileBytes, _idBytes);
    });

    test('still rolls the optimistic row back when a submit with a photo fails',
        () async {
      // The rollback is what stops a 422 from leaving a row on the Track screen
      // that MDRRMO has no record of. A second file makes a partial failure
      // more likely, not less, so it is asserted on the photo path too.
      final api = _RecordingApi()
        ..error = const ApiException('No available vehicles at this time.');
      final state = AppState(api);

      final confirmed = await state.addRequest(
        _draft(),
        validIdFileBytes: _idBytes,
        validIdFileName: 'id.jpg',
        sitePhotoBytes: _photoBytes,
        sitePhotoFileName: 'scene.png',
      );

      expect(confirmed, isNull);
      expect(state.requests, isEmpty);
      expect(state.lastError, 'No available vehicles at this time.');
      // The photo still reached the API — the failure is the server's answer,
      // not a dropped attachment.
      expect(api.sitePhotoFileName, 'scene.png');
    });
  });
}
