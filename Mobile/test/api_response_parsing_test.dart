// Covers the JSON parsing after the compatibility fallbacks were removed
// (M31). Those fallbacks guessed at keys the backend has never emitted, and one
// of them — `values.first` in the list unwrapper — crashed on any non-JSON body
// (M22). The removals are only safe if the real response shapes still parse, so
// the fixtures below are the shapes the controllers actually return:
//
//   GET /api/service-requests   {"data": [...]}          (paginated by hand)
//   GET /api/services           {"data": [...]}          (resource collection)
//   GET /api/info-materials     bare array, wrapped to {"data": [...]} by _decode
//   GET /api/barangays          bare array, same wrapping
//   GET /api/me                 the resident model with its `barangay` relation

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/state/account_store.dart';
import 'package:serbis/state/api_service.dart';

void main() {
  group('ApiService.listFrom', () {
    test('unwraps the rows under data', () {
      final rows = ApiService.listFrom(<String, dynamic>{
        'data': <dynamic>[
          <String, dynamic>{'request_id': 1},
          <String, dynamic>{'request_id': 2},
        ],
      });

      expect(rows, hasLength(2));
      expect(rows.first['request_id'], 1);
    });

    test('returns empty for a body that decoded to {}', () {
      // What `_decode` produces for an nginx error page or a captive-portal
      // interstitial. The old `values.first` threw `Bad state: No element`.
      expect(ApiService.listFrom(<String, dynamic>{}), isEmpty);
    });

    test('returns empty when data is absent', () {
      expect(
        ApiService.listFrom(<String, dynamic>{'message': 'Unauthenticated.'}),
        isEmpty,
      );
    });

    test('returns empty when data is not a list', () {
      expect(ApiService.listFrom(<String, dynamic>{'data': 'nope'}), isEmpty);
    });

    test('drops malformed rows instead of the whole list', () {
      final rows = ApiService.listFrom(<String, dynamic>{
        'data': <dynamic>[
          <String, dynamic>{'service_id': 1},
          'garbage',
          null,
          <String, dynamic>{'service_id': 3},
        ],
      });

      expect(rows.map((r) => r['service_id']), <int>[1, 3]);
    });
  });

  group('ServiceRequest.fromJson', () {
    test('reads the id from request_id', () {
      final request = ServiceRequest.fromJson(<String, dynamic>{
        'request_id': 42,
        'service_id': 3,
        'description': 'Tree down on the access road',
        'status': 'Responding',
        'service': <String, dynamic>{'service_name': 'Road Clearing'},
      });

      expect(request.id, 42);
      expect(request.refNo, 'SR-42');
      expect(request.status, ReqStatus.scheduled);
      expect(request.cancellable, isTrue);
    });

    test('leaves the id null when request_id is missing', () {
      // The removed `?? json['id']` used to mask this. A null id must stay
      // visible: cancel and track both address the row by it.
      final request = ServiceRequest.fromJson(<String, dynamic>{
        'id': 42,
        'status': 'Pending',
      });

      expect(request.id, isNull);
      expect(request.refNo, '');
    });
  });

  group('AppUser.fromJson', () {
    test('reads the email from email_address and the address from barangay', () {
      final user = AppUser.fromJson(<String, dynamic>{
        'resident_id': 9,
        'first_name': 'Maria',
        'last_name': 'Santos',
        'email_address': 'maria@example.test',
        'barangay': <String, dynamic>{'barangay_name': 'Bagumbayan'},
      });

      expect(user.id, '9');
      expect(user.fullName, 'Maria Santos');
      expect(user.email, 'maria@example.test');
      expect(user.address, 'Bagumbayan');
    });

    test('falls back to empty strings when the relation is not loaded', () {
      final user = AppUser.fromJson(<String, dynamic>{
        'resident_id': 9,
        'first_name': 'Maria',
        'last_name': 'Santos',
        'email_address': 'maria@example.test',
      });

      expect(user.address, '');
    });
  });
}
