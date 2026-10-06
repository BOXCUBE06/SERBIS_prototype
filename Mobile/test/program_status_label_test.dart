// An approved program (training, drill, certification) says "Approved" where
// every other request says "Responding". Display only:
// the status the server sent and the app's ReqStatus are untouched.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/widgets/shared_widgets.dart';

ServiceRequest _request(String status, {String? category = 'programs'}) =>
    ServiceRequest.fromJson({
      'request_id': 55,
      'service_id': 8,
      'description': 'Preferred date: 8 Oct 2026',
      'status': status,
      'created_at': '2026-09-20T05:59:00Z',
      'updated_at': '2026-09-20T07:00:00Z',
      'service': {
        'code': 'drrm-trainings-and-seminars',
        'service_name': 'DRRM Trainings and Seminars',
        if (category != null) 'category': category,
      },
    });

void main() {
  group('an approved program', () {
    test('is read from the service category and says Approved', () {
      final request = _request('Responding');

      expect(request.isProgram, isTrue);
      expect(request.status, ReqStatus.scheduled, reason: 'the status itself does not change');
      expect(request.statusLabelFor(false), 'Approved');
      expect(request.statusLabelFor(true), 'Aprubado');
    });

    test('names the timeline step "Approved" instead of "Responding"', () {
      final titles = _request('Responding').timelineFor(false).map((s) => s.title).toList();

      expect(titles, contains('Approved'));
      expect(titles, isNot(contains('Responding')));
    });

    test('a program that is still waiting keeps its ordinary wording', () {
      expect(_request('Pending').statusLabelFor(false), 'Under review');
      expect(_request('Resolved').statusLabelFor(false), 'Completed');
      expect(_request('Disapproved').statusLabelFor(false), 'Not approved');
    });
  });

  group('every other request', () {
    test('keeps "Responding" on the status and the step', () {
      final request = _request('Responding', category: 'infrastructure');

      expect(request.isProgram, isFalse);
      expect(request.statusLabelFor(false), 'Responding');
      expect(
        request.timelineFor(false).map((s) => s.title),
        contains('Responding'),
      );
    });

    test('a server with no category column changes nothing', () {
      expect(_request('Responding', category: null).statusLabelFor(false), 'Responding');
    });
  });

  group('where the category comes from', () {
    test('the catalogue item reads it', () {
      final item = ServiceCatalogItem.fromJson({
        'service_id': 8,
        'code': 'drrm-trainings-and-seminars',
        'service_name': 'DRRM Trainings and Seminars',
        'category': 'programs',
      });

      expect(item.category, 'programs');
      expect(const ServiceCatalogItem.others().category, isNull);
    });

    test('a resolved request keeps it through the offline cache', () {
      final restored = ServiceRequestCache.fromCacheJson(_request('Responding').toCacheJson());

      expect(restored?.serviceCategory, 'programs');
      expect(restored?.statusLabelFor(false), 'Approved');
    });

    test('a row cached before the category existed reads as an ordinary request', () {
      final json = _request('Responding').toCacheJson()..remove('service_category');

      expect(ServiceRequestCache.fromCacheJson(json)?.statusLabelFor(false), 'Responding');
    });

    test('copyWith carries the category when the catalogue resolves the row', () {
      final resolved = _request('Responding', category: null).copyWith(serviceCategory: 'programs');

      expect(resolved.statusLabelFor(false), 'Approved');
    });
  });

  testWidgets('the badge shows the wording it is given', (tester) async {
    final request = _request('Responding');

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: StatusBadge(request.status, label: request.statusLabelFor(false)),
      ),
    ));

    expect(find.text('APPROVED'), findsOneWidget);
    expect(find.text('SCHEDULED'), findsNothing);
  });
}
