// A resident can pick a date and time for an ambulance. This app had never
// sent a datetime in a request body before this feature — there was no
// convention to establish it against, so these tests are what pins the
// format down: UTC ISO 8601 with a 'Z' suffix, never a naive local string.

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/models/service_forms.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';

/// Records what `addRequest` forwarded, mirroring `_RecordingApi` in
/// site_photo_test.dart.
class _RecordingApi extends ApiService {
  DateTime? scheduledAt;
  String? requiredVehicleType;
  AmbulanceIntake? intake;
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
    String? landmark,
    DateTime? scheduledAt,
    AmbulanceIntake? intake,
  }) async {
    this.scheduledAt = scheduledAt;
    this.requiredVehicleType = requiredVehicleType;
    this.intake = intake;

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

    test('an ambulance body carries the structured fields and no description', () {
      final request = ApiService().buildSubmitRequest(
        serviceId: 3,
        // Deliberately non-empty: the builder must drop it for an ambulance
        // request rather than pass it along, because the server composes its
        // own and a client copy would be a second composer.
        description: 'a client-composed description',
        validIdFileBytes: _idBytes,
        validIdFileName: 'id.jpg',
        intake: const AmbulanceIntake(
          patientName: 'Juan Dela Cruz',
          destination: 'Echague District Hospital',
          patientAge: '62',
          patientSex: 'female',
          patientAddress: 'Purok 2, San Fabian',
          patientContactNumber: '09189999999',
          pickupLocation: 'Purok 2, San Fabian',
          conditionNotes: 'Chest pains',
          relatives: ['Lalaine Ferrer', 'Rosa Dela Cruz'],
        ),
      );

      expect(request.fields['patient_name'], 'Juan Dela Cruz');
      expect(request.fields['destination'], 'Echague District Hospital');
      expect(request.fields['patient_age'], '62');
      expect(request.fields['patient_sex'], 'female');
      expect(request.fields['patient_address'], 'Purok 2, San Fabian');
      expect(request.fields['patient_contact_number'], '09189999999');
      expect(request.fields['pickup_location'], 'Purok 2, San Fabian');
      expect(request.fields['condition_notes'], 'Chest pains');

      // Indexed keys, which is what PHP parses back into the array the
      // `patient_relatives.*` rule validates.
      expect(request.fields['patient_relatives[0]'], 'Lalaine Ferrer');
      expect(request.fields['patient_relatives[1]'], 'Rosa Dela Cruz');

      expect(request.fields.containsKey('description'), isFalse);
    });

    test('an unset optional field is absent, never an empty string', () {
      // `nullable|integer` rejects '', and `in:male,female` rejects ''. An
      // untouched field has to be missing from the body, not present and
      // blank, or an optional field becomes a 422.
      final request = ApiService().buildSubmitRequest(
        serviceId: 3,
        description: 'x',
        validIdFileBytes: _idBytes,
        validIdFileName: 'id.jpg',
        intake: const AmbulanceIntake(
          patientName: 'Juan Dela Cruz',
          destination: 'Echague District Hospital',
        ),
      );

      for (final key in const [
        'patient_age',
        'patient_sex',
        'patient_address',
        'patient_contact_number',
        'pickup_location',
        'condition_notes',
        'patient_relatives[0]',
      ]) {
        expect(request.fields.containsKey(key), isFalse,
            reason: '$key should be absent, not empty');
      }
    });

    test('a non-ambulance body still carries a description and no patient fields', () {
      final request = ApiService().buildSubmitRequest(
        serviceId: 3,
        description: 'Fallen tree blocking the road',
        validIdFileBytes: _idBytes,
        validIdFileName: 'id.jpg',
      );

      expect(request.fields['description'], 'Fallen tree blocking the road');
      expect(request.fields.containsKey('patient_name'), isFalse);
      expect(request.fields.containsKey('destination'), isFalse);
    });

    test('a scheduled ambulance request keeps both the schedule and the fields', () {
      final picked = DateTime(2026, 9, 4, 9, 0);

      final request = ApiService().buildSubmitRequest(
        serviceId: 3,
        description: 'x',
        validIdFileBytes: _idBytes,
        validIdFileName: 'id.jpg',
        scheduledAt: picked,
        intake: const AmbulanceIntake(
          patientName: 'Juan Dela Cruz',
          destination: 'Echague District Hospital',
        ),
      );

      expect(request.fields['scheduled_at'], picked.toUtc().toIso8601String());
      expect(request.fields['scheduled_at'], endsWith('Z'));
      expect(request.fields['patient_name'], 'Juan Dela Cruz');
      expect(request.fields.containsKey('description'), isFalse);
    });
  });

  group('AmbulanceIntake.from', () {
    test('maps the dropdown\'s "Not specified" to null, not the label', () {
      final form = AmbulanceFormData();
      addTearDown(form.dispose);
      form.patient.text = 'Juan Dela Cruz';
      form.destination.text = 'Echague District Hospital';

      final intake = AmbulanceIntake.from(form);

      expect(intake.patientSex, isNull);
      expect(intake.toFields().containsKey('patient_sex'), isFalse);
    });

    test('maps a picked sex to the lowercase value the API takes', () {
      final form = AmbulanceFormData();
      addTearDown(form.dispose);
      form.patient.text = 'Juan Dela Cruz';
      form.destination.text = 'Echague District Hospital';
      form.sex = 'Female';

      expect(AmbulanceIntake.from(form).patientSex, 'female');
    });

    test('an untouched age is null, not an empty string', () {
      final form = AmbulanceFormData();
      addTearDown(form.dispose);
      form.patient.text = 'Juan Dela Cruz';
      form.destination.text = 'Echague District Hospital';

      final intake = AmbulanceIntake.from(form);

      expect(intake.patientAge, isNull);
      expect(intake.toFields().containsKey('patient_age'), isFalse);
    });

    test('reads the diagnosis controller into condition_notes', () {
      // The label says "Medical diagnosis", the column is condition_notes, and
      // this is the one place the two names meet.
      final form = AmbulanceFormData();
      addTearDown(form.dispose);
      form.patient.text = 'Juan Dela Cruz';
      form.destination.text = 'Echague District Hospital';
      form.diagnosis.text = 'Chest pains';

      expect(AmbulanceIntake.from(form).toFields()['condition_notes'], 'Chest pains');
    });

    test('drops blank relative slots', () {
      final form = AmbulanceFormData();
      addTearDown(form.dispose);
      form.patient.text = 'Juan Dela Cruz';
      form.destination.text = 'Echague District Hospital';
      form.relatives[0].text = '   ';
      form.addRelative();
      form.relatives[1].text = 'Lalaine Ferrer';

      expect(AmbulanceIntake.from(form).relatives, ['Lalaine Ferrer']);
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

  group('formatDueDate', () {
    test('names the month and keeps the year, with no clock', () {
      expect(formatDueDate(DateTime(2026, 9, 12, 15, 4)), 'Sep 12, 2026');
    });

    // The borrow screen used to hand-assemble 9/12/2026, which a reader has
    // to know is M/D and not D/M. A named month cannot be misread.
    test('is unambiguous on a day the two orders would swap', () {
      expect(formatDueDate(DateTime(2026, 3, 4)), 'Mar 4, 2026');
    });
  });
}
