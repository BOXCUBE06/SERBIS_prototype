import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/borrow_models.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/state/translations.dart';
import 'package:serbis/widgets/borrow_request_widgets.dart' show borrowStatus, serviceStatus;

void main() {
  test('trEn translates in Filipino and leaves English untouched', () {
    expect(trEn(true, 'Pickup'), 'Kukunin');
    expect(trEn(false, 'Pickup'), 'Pickup');
    expect(trEn(true, 'No such string'), 'No such string');
  });

  // One word per state, the same on a service request, a loan and the steps.
  test('a state reads the same everywhere, in both languages', () {
    ServiceRequest service(ReqStatus status) => ServiceRequest(
          serviceId: 1,
          description: '',
          type: ServiceType.road,
          refNo: 'TXN-1',
          status: status,
          metaLines: const [],
          createdAt: DateTime(2026, 9, 23, 8, 24),
        );
    BorrowRequest loan(BorrowStatus status) => BorrowRequest(id: 1, equipmentId: 1, quantity: 1, status: status);

    for (final f in [false, true]) {
      expect(serviceStatus(service(ReqStatus.review), f).label, f ? 'Sinusuri' : 'Under review');
      expect(borrowStatus(loan(BorrowStatus.pending), f).label, f ? 'Sinusuri' : 'Under review');
      expect(serviceStatus(service(ReqStatus.completed), f).label, f ? 'Natapos' : 'Completed');
      expect(serviceStatus(service(ReqStatus.cancelled), f).label, f ? 'Kinansela mo' : 'Cancelled by you');
      expect(borrowStatus(loan(BorrowStatus.cancelled), f).label, f ? 'Kinansela mo' : 'Cancelled by you');
      expect(borrowStatus(loan(BorrowStatus.returned), f).label, f ? 'Naibalik' : 'Returned');

      final steps = service(ReqStatus.review).timelineFor(f).map((s) => s.title).toList();
      expect(steps, f ? ['Naipadala', 'Sinusuri', 'Natapos'] : ['Sent', 'Under review', 'Completed']);
    }
  });

  // Phase 3a: the forms' new headings, hints and buttons all have Filipino.
  test('the restyled forms read in Filipino', () {
    for (final key in [
      'relief.step1.title', 'relief.step1.body', 'relief.step2.title',
      'relief.step2.body', 'relief.step3.title', 'relief.step3.body', 'services.confirm.title',
      'feedback.check_one', 'feedback.check_field',
    ]) {
      expect(tr(true, key), isNot(tr(false, key)), reason: key);
      expect(tr(true, key), isNot(key), reason: key);
    }
    expect(tr(true, 'services.confirm.title'), 'Naipadala ang kahilingan');
    expect(tr(false, 'feedback.check_one'), 'Please check the highlighted field.');
    expect(tr(true, 'feedback.check_field').replaceAll('{field}', 'Valid ID'), 'Pakitingnan: Valid ID');
    for (final english in [
      'Done', 'Choose another file', 'To an address', 'Up to {n}',
      'PDF, JPG or PNG, up to 4 MB', 'A photo of a nearby landmark. JPG or PNG, up to 4 MB',
    ]) {
      expect(trEn(true, english), isNot(english), reason: english);
    }
  });

  // Phase 3b: the ambulance steps' headings and labels, and the one submit
  // label every form now shares.
  test('the ambulance flow and the send button read in Filipino', () {
    for (final key in [
      'ambulance.step2.title', 'ambulance.step2.body', 'ambulance.step3.title', 'ambulance.step3.body',
      'ambulance.step4.title', 'ambulance.step4.body', 'ambulance.step5.title', 'ambulance.step5.body',
    ]) {
      expect(tr(true, key), isNot(tr(false, key)), reason: key);
      expect(tr(true, key), isNot(key), reason: key);
    }
    expect(tr(false, 'common.submit_request'), 'Send request');
    expect(tr(true, 'common.submit_request'), 'Ipadala ang kahilingan');
    // The same words the borrow sheet's own Send button uses.
    expect(tr(true, 'common.submit_request'), trEn(true, 'Send request'));
    for (final english in [
      'Full name', 'Relative to contact', "We'll call them if we can't reach the patient.", 'Helps the driver find you.',
    ]) {
      expect(trEn(true, english), isNot(english), reason: english);
    }
  });

  // Group A: the edit sheet's hint under a grey Save.
  test('the profile edit hint reads in Filipino', () {
    expect(tr(false, 'profile.save_hint'), 'Change a detail above to save.');
    expect(tr(true, 'profile.save_hint'), isNot(tr(false, 'profile.save_hint')));
  });

  test('countdowns translate but keep their English wording', () {
    final today = DateTime(2026, 9, 30, 9);
    expect(dueLabel(DateTime(2026, 9, 30), today), 'Due today');
    expect(dueLabel(DateTime(2026, 9, 30), today, true), 'Ibalik ngayong araw');
    expect(dueLabel(DateTime(2026, 9, 27), today, true), 'Lampas na ng 3 araw');
    expect(scheduledCountdownLabel(DateTime(2026, 10, 3), today, true), 'Naka-iskedyul sa loob ng 3 araw');
  });

  test('the programs and the account label read in Filipino', () {
    expect(serviceNameFor(true, 'mdrrmo-certification', 'x'), 'Sertipikasyon ng MDRRMO');
    expect(tr(true, 'account.individual'), 'Pinuno ng Pamilya');
    expect(tr(false, 'account.individual'), 'Head of the Family');
  });
}
