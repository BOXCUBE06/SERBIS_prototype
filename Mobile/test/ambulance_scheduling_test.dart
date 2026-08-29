// A resident can pick a date and time for an ambulance. This app had never
// sent a datetime in a request body before this feature — there was no
// convention to establish it against, so these tests are what pins the
// format down: UTC ISO 8601 with a 'Z' suffix, never a naive local string.

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';

/// Records what `addRequest` forwarded, mirroring `_RecordingApi` in
/// site_photo_test.dart.
class _RecordingApi extends ApiService {
  DateTime? scheduledAt;
  String? requiredVehicleType;
  Object? error;

  @override
  Future<List<Map<String, dynamic>>> getRequests() async =>
      <Map<String, dynamic>>[];

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async =>
      <Map<String, dynamic>>[];

  @override
  Future<Map<String, dynamic>> submitRequest({
    required int serviceId,
    required String description,
    required List<int> validIdFileBytes,
    required String validIdFileName,
    String? requiredVehicleType,
    List<int>? sitePhotoBytes,
    String? sitePhotoFileName,
    DateTime? scheduledAt,
  }) async {
    this.scheduledAt = scheduledAt;
    this.requiredVehicleType = requiredVehicleType;

    if (error != null) {
      throw error!;
    }

    return <String, dynamic>{
      'request_id': 91,
      'service_id': serviceId,
      'description': description,
      'status': scheduledAt != null ? 'Booked' : 'Pending',
      if (scheduledAt != null)
        'scheduled_at': scheduledAt.toUtc().toIso8601String(),
    };
  }
}

final List<int> _idBytes = <int>[1, 2, 3];

ServiceRequest _draft({DateTime? scheduledAt}) => ServiceRequest(
      id: null,
      serviceId: 3,
      description: 'Ambulance/Medical Response\nPatient: Juan Dela Cruz',
      type: ServiceType.ambulance,
      refNo: '',
      status: ReqStatus.review,
      metaLines: const <String>[],
      scheduledAt: scheduledAt,
    );

void main() {
  group('the multipart body', () {
    test('carries no scheduled_at field when nothing was picked', () {
      final request = ApiService().buildSubmitRequest(
        serviceId: 3,
        description: 'x',
        validIdFileBytes: _idBytes,
        validIdFileName: 'id.jpg',
      );

      expect(request.fields.containsKey('scheduled_at'), isFalse);
    });

    test('sends the picked time as UTC ISO 8601 with a Z suffix, not a naive local string', () {
      // A local wall-clock pick — exactly what showDatePicker/showTimePicker
      // hand back, with no timezone attached of its own.
      final picked = DateTime(2026, 9, 1, 17, 0);

      final request = ApiService().buildSubmitRequest(
        serviceId: 3,
        description: 'x',
        validIdFileBytes: _idBytes,
        validIdFileName: 'id.jpg',
        scheduledAt: picked,
      );

      final sent = request.fields['scheduled_at'];

      // The contract itself: picked.toUtc().toIso8601String(), not the naive
      // local string DateTime.toString() would produce. Computed the same
      // way here rather than hardcoded, so this test does not depend on
      // which timezone it happens to run in.
      expect(sent, picked.toUtc().toIso8601String());
      expect(sent, endsWith('Z'));
      expect(sent, isNot(contains(picked.toString())));

      // The exact shape the backend's Carbon::parse(..., 'Asia/Manila')->utc()
      // depends on: an offset marker, so it is honoured as sent rather than
      // re-read as Manila wall clock a second time.
      expect(sent, matches(RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$')));
    });
  });

  group('addRequest', () {
    test('forwards the picked schedule to the API', () async {
      final api = _RecordingApi();
      final state = AppState(api);
      final picked = DateTime.now().add(const Duration(days: 2));

      final confirmed = await state.addRequest(
        _draft(scheduledAt: picked),
        validIdFileBytes: _idBytes,
        validIdFileName: 'id.jpg',
      );

      expect(confirmed, isNotNull);
      expect(api.scheduledAt, picked);
    });

    test('sends required_vehicle_type only when the caller asks for it', () async {
      final api = _RecordingApi();
      final state = AppState(api);

      await state.addRequest(
        _draft(),
        validIdFileBytes: _idBytes,
        validIdFileName: 'id.jpg',
        requiredVehicleType: 'Ambulance',
      );

      expect(api.requiredVehicleType, 'Ambulance');
    });

    test('an unscheduled request still sends nothing extra', () async {
      final api = _RecordingApi();
      final state = AppState(api);

      await state.addRequest(
        _draft(),
        validIdFileBytes: _idBytes,
        validIdFileName: 'id.jpg',
      );

      expect(api.scheduledAt, isNull);
    });

    test('rolls back a scheduled booking the server refused, same as any other request', () async {
      final api = _RecordingApi()
        ..error = const ApiException('No ambulance is available for that time.');
      final state = AppState(api);

      final confirmed = await state.addRequest(
        _draft(scheduledAt: DateTime.now().add(const Duration(days: 1))),
        validIdFileBytes: _idBytes,
        validIdFileName: 'id.jpg',
      );

      // The one guarantee offline_banner.dart's own comment names: a resident
      // must never be shown a confirmation for a booking that did not reach
      // MDRRMO.
      expect(confirmed, isNull);
      expect(state.requests, isEmpty);
      expect(state.lastError, 'No ambulance is available for that time.');
    });
  });

  group('ServiceRequest.fromJson', () {
    test('parses scheduled_at when the server sends it', () {
      final request = ServiceRequest.fromJson(<String, dynamic>{
        'request_id': 5,
        'service_id': 3,
        'status': 'Booked',
        'scheduled_at': '2026-09-01T09:00:00.000000Z',
      });

      expect(request.scheduledAt, isNotNull);
      expect(request.scheduledAt!.toUtc(), DateTime.utc(2026, 9, 1, 9, 0, 0));
    });

    test('is null for an ordinary request that carries no scheduled_at', () {
      final request = ServiceRequest.fromJson(<String, dynamic>{
        'request_id': 6,
        'service_id': 1,
        'status': 'Pending',
      });

      expect(request.scheduledAt, isNull);
    });
  });

  group('the cache round trip', () {
    test('a scheduled request survives toCacheJson/fromCacheJson', () {
      final scheduled = DateTime.utc(2026, 9, 1, 9, 0, 0);
      final original = _draft(scheduledAt: scheduled).copyWith(id: 42);

      final restored = ServiceRequestCache.fromCacheJson(original.toCacheJson());

      expect(restored, isNotNull);
      expect(restored!.scheduledAt?.toUtc(), scheduled);
    });

    test('a cache row written before this feature existed reads back as unscheduled', () {
      // No 'scheduled_at' key at all — what every row cached by an older
      // build looks like, not a row with the key present and null.
      final legacy = <String, dynamic>{
        'id': 7,
        'service_id': 1,
        'type': 'ambulance',
        'ref_no': 'SR-7',
        'status': 'review',
        'meta_lines': <String>[],
        'cancellable': true,
      };

      final restored = ServiceRequestCache.fromCacheJson(legacy);

      expect(restored, isNotNull);
      expect(restored!.scheduledAt, isNull);
    });
  });

  group('formatBookingConfirmationTime', () {
    test('includes the year, unlike formatTimelineTime', () {
      final at = DateTime(2026, 8, 1, 15, 4);

      final confirmation = formatBookingConfirmationTime(at, false);
      final timeline = formatTimelineTime(at, false);

      expect(confirmation, contains('2026'));
      expect(timeline, isNot(contains('2026')));
      expect(confirmation, 'Aug 1, 2026, 3:04 PM');
    });
  });
}
