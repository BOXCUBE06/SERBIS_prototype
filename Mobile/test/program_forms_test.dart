// The MDRRMO programs — DRRM Trainings and Seminars, Simulation Drills / NSED,
// MDRRMO Certification — are requested by a barangay or an organization. They
// differ from the response services in three ways this file pins: a real date
// field with a lead time, a request letter instead of a valid ID, and the
// fields that go into the description.

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/models/service_forms.dart';
import 'package:serbis/state/api_service.dart';

List<String> _lines(StructuredFormData form) =>
    form.metaLines(serviceName: 'DRRM Trainings and Seminars', submittedLabel: 'Today, 9:00 AM');

void main() {
  group('DRRM Trainings and Seminars', () {
    test('is recognised by its service code', () {
      expect(formKindForServiceCode('drrm-trainings-and-seminars'), ServiceFormKind.training);
    });

    test('asks for a required letter and no valid ID', () {
      final form = StructuredFormData.training();
      expect(form.spec.attachments, ServiceAttachments.letterRequired);
    });

    test('has a date field with the 14 day lead time', () {
      final form = StructuredFormData.training();
      final dates = form.spec.fields.where((field) => field.isDate).toList();

      expect(dates, hasLength(1));
      expect(dates.single.key, 'preferred_date');
      expect(dates.single.minDaysAhead, kProgramLeadDays);
      expect(kProgramLeadDays, 14);
    });

    test('an unset date reads as not specified and sends no preferred_date', () {
      final form = StructuredFormData.training();

      expect(form.preferredDate, isNull);
      expect(_lines(form), contains('Preferred date: Not specified'));
    });

    test('a picked date goes into the description and out as preferred_date', () {
      final form = StructuredFormData.training()
        ..setDate('preferred_date', DateTime(2026, 10, 5, 14, 30));

      // The time of day is dropped: the resident names a day.
      expect(form.preferredDate, DateTime(2026, 10, 5));
      expect(_lines(form), contains('Preferred date: 5 Oct 2026'));
    });

    test('every field the resident fills in reaches the description', () {
      final form = StructuredFormData.training(contactNumber: '09171234567')
        ..setDate('preferred_date', DateTime(2026, 10, 5));
      form.field('location').text = 'Barangay hall, Purok 3';
      form.field('participants').text = '40';
      form.field('topic').text = 'Basic life support';

      expect(_lines(form), [
        'DRRM Trainings and Seminars',
        'Preferred date: 5 Oct 2026',
        'Location: Barangay hall, Purok 3',
        'Participants: 40',
        'Topic: Basic life support',
        'Contact: 09171234567',
        'Submitted Today, 9:00 AM',
      ]);
    });

    test('asking for a date on a form that has none throws rather than guessing', () {
      expect(() => StructuredFormData.generic().date('preferred_date'), throwsArgumentError);
    });
  });

  group('Simulation Drills / NSED', () {
    test('is recognised by its service code', () {
      expect(formKindForServiceCode('simulation-drills-nsed'), ServiceFormKind.drill);
    });

    test('has the same date rule and letter as the training form', () {
      final form = StructuredFormData.drill();
      final date = form.spec.fields.singleWhere((field) => field.isDate);

      expect(date.minDaysAhead, 14);
      expect(form.spec.attachments, ServiceAttachments.letterRequired);
    });

    test('every field the resident fills in reaches the description', () {
      final form = StructuredFormData.drill(contactNumber: '09171234567')
        ..setDate('preferred_date', DateTime(2026, 11, 2));
      form.field('location').text = 'Echague Central School';
      form.select('drill_type', 'Fire');
      form.field('participants').text = '250';

      expect(
        form.metaLines(serviceName: 'Simulation Drills / NSED', submittedLabel: 'Today, 9:00 AM'),
        [
          'Simulation Drills / NSED',
          'Preferred date: 2 Nov 2026',
          'Location: Echague Central School',
          'Drill type: Fire',
          'Participants: 250',
          'Contact: 09171234567',
          'Submitted Today, 9:00 AM',
        ],
      );
    });

    test('the drill type starts on the NSED earthquake drill', () {
      expect(StructuredFormData.drill().choice('drill_type'), 'Earthquake (NSED)');
    });
  });

  group('the submit request', () {
    test('leaves the valid ID out when there is none and carries the letter and date', () {
      final request = ApiService().buildSubmitRequest(
        serviceId: 8,
        description: 'DRRM Trainings and Seminars',
        validIdFileBytes: const <int>[],
        validIdFileName: '',
        preferredDate: DateTime(2026, 10, 5),
        letterBytes: const [1, 2, 3],
        letterFileName: 'letter.pdf',
      );

      expect(request.files.map((file) => file.field), ['letter']);
      expect(request.fields['preferred_date'], '2026-10-05');
    });

    test('a response service still sends its valid ID and no program fields', () {
      final request = ApiService().buildSubmitRequest(
        serviceId: 1,
        description: 'Tree across the road',
        validIdFileBytes: const [1, 2, 3],
        validIdFileName: 'id.jpg',
      );

      expect(request.files.map((file) => file.field), ['valid_id']);
      expect(request.fields.containsKey('preferred_date'), isFalse);
    });
  });
}
