import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/borrow_models.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/state/translations.dart';

void main() {
  test('trEn translates in Filipino and leaves English untouched', () {
    expect(trEn(true, 'Pickup'), 'Kukunin');
    expect(trEn(false, 'Pickup'), 'Pickup');
    expect(trEn(true, 'No such string'), 'No such string');
  });

  test('borrowing statuses have Filipino labels', () {
    expect(BorrowStatus.pending.labelFor(true), 'Sinusuri');
    expect(BorrowStatus.pending.labelFor(false), 'Pending review');
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
