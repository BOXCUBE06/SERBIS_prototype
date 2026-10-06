import 'package:flutter/material.dart';

import '../data/hotlines.dart';
import '../models/borrow_models.dart';
import '../models/request_models.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'status_line.dart';

/// Body text on the borrow cards never goes below this.
const double _bodySize = 15;

/// MDRRMO's first number from the hotline list, or null when none matches.
/// The admin-editable list has no "this is MDRRMO" flag, so it goes by label:
/// an "MDRRMO" entry first, then the Echague Rescue Hotline (MDRRMO's desk).
String? mdrrmoNumber(List<Hotline> hotlines) {
  for (final key in const ['mdrrmo', 'rescue']) {
    for (final h in hotlines) {
      if (h.label.toLowerCase().contains(key) && h.numbers.isNotEmpty) return h.numbers.first.number;
    }
  }
  return null;
}

bool _isDelivery(BorrowRequest r) => r.fulfillmentMethod == 'Delivery';

/// A status as every screen words it: one label, its colour, and one line on
/// what happens next. Track, Borrow, Home and the bell all read these, so a
/// state is never "Waiting for MDRRMO" in one place and "Under review" in
/// another.
typedef StatusInfo = ({String label, StatusTone tone, String next});

StatusInfo borrowStatus(BorrowRequest r, bool f) {
  final delivery = _isDelivery(r);
  return switch (r.status) {
    BorrowStatus.pending => (
        label: tr(f, 'status.review'),
        tone: StatusTone.amber,
        next: trEn(f, 'MDRRMO is checking if they can lend this. Updates appear here and under the bell.'),
      ),
    BorrowStatus.approved => (
        label: tr(f, delivery ? 'status.out_delivery' : 'status.ready_pickup'),
        tone: StatusTone.green,
        next: trEn(f, delivery ? 'MDRRMO will bring it to your address.' : 'Pick it up at the MDRRMO office.'),
      ),
    BorrowStatus.released => (
        label: tr(f, delivery ? 'status.delivered' : 'status.picked_up'),
        tone: StatusTone.green,
        next: r.dueDate == null
            ? trEn(f, "Return it to MDRRMO when you're done.")
            : '${trEn(f, 'Please return it by {date}.').replaceAll('{date}', formatDueDate(r.dueDate!))}'
                ' (${dueLabel(r.dueDate!, null, f)})',
      ),
    BorrowStatus.returned => (
        label: tr(f, 'status.returned'),
        tone: StatusTone.green,
        next: trEn(f, 'Thank you for returning it.'),
      ),
    BorrowStatus.denied => (
        label: tr(f, 'status.disapproved'),
        tone: StatusTone.red,
        next: r.denialReason?.trim().isNotEmpty == true
            ? '${trEn(f, "MDRRMO's reason:")} ${r.denialReason!.trim()}'
            : trEn(f, 'MDRRMO could not approve this request.'),
      ),
    BorrowStatus.cancelled => (
        label: tr(f, 'status.cancelled'),
        tone: StatusTone.grey,
        next: tr(f, 'track.box.cancelled.next'),
      ),
  };
}

StatusInfo serviceStatus(ServiceRequest r, bool f) {
  final reason = r.note?.trim() ?? '';
  return switch (r.status) {
    ReqStatus.review => (
        label: tr(f, 'status.review'),
        tone: StatusTone.amber,
        next: tr(f, 'track.box.review.next'),
      ),
    ReqStatus.booked when r.isOverdue => (
        label: tr(f, 'track.box.overdue.title'),
        tone: StatusTone.red,
        next: tr(f, 'common.booking_overdue'),
      ),
    ReqStatus.booked => (
        label: tr(f, 'status.booked'),
        tone: StatusTone.green,
        next: r.scheduledAt == null
            ? tr(f, 'track.box.booked.next')
            : tr(f, 'track.box.booked.next_dated').replaceAll('{date}', formatBookingConfirmationTime(r.scheduledAt!, f)),
      ),
    ReqStatus.scheduled when r.isProgram => (
        label: tr(f, 'status.approved'),
        tone: StatusTone.green,
        next: tr(f, 'track.box.approved.next'),
      ),
    ReqStatus.scheduled => (
        label: tr(f, 'status.scheduled'),
        tone: StatusTone.green,
        next: tr(f, 'track.box.responding.next'),
      ),
    ReqStatus.completed when r.isNotTransported => (
        label: tr(f, 'status.not_transported'),
        tone: StatusTone.amber,
        next: r.noArrivalReason!.trim(),
      ),
    ReqStatus.completed => (
        label: tr(f, 'status.completed'),
        tone: StatusTone.green,
        next: tr(f, 'track.box.completed.next'),
      ),
    ReqStatus.cancelled => (
        label: tr(f, 'status.cancelled'),
        tone: StatusTone.grey,
        next: tr(f, 'track.box.cancelled.next'),
      ),
    ReqStatus.disapproved => (
        label: tr(f, 'status.disapproved'),
        tone: StatusTone.red,
        next: reason.isEmpty
            ? tr(f, 'track.box.disapproved.next')
            : tr(f, 'track.box.reason').replaceAll('{reason}', reason),
      ),
  };
}

/// Tinted box: icon, title, one line under it. The confirmation sheet and the
/// full-page notices use it; request statuses use [StatusLine].
class StatusBox extends StatelessWidget {
  final IconData icon;
  final Color bg;
  final Color fg;
  final String title;
  final String next;

  const StatusBox({
    super.key,
    required this.icon,
    required this.bg,
    required this.fg,
    required this.title,
    required this.next,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(AppRadius.md)),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 24, color: fg),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: AppText.display(size: 16, weight: FontWeight.w600, color: fg)),
                const SizedBox(height: 2),
                Text(next, style: AppText.body(size: _bodySize, color: AppColors.ink, height: 1.4)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Sent, Under review, Ready to pick up/Out for delivery, Returned — for an
/// open request. Done = filled check, current = amber ring, future = grey ring.
class BorrowProgressSteps extends StatelessWidget {
  final BorrowRequest request;
  final bool filipino;

  const BorrowProgressSteps({super.key, required this.request, required this.filipino});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final r = request;
    final delivery = _isDelivery(r);
    final released = r.status == BorrowStatus.released;
    // Index of the step in progress: pending 1, approved 2, released 3.
    final current = switch (r.status) {
      BorrowStatus.pending => 1,
      BorrowStatus.approved => 2,
      _ => 3,
    };
    final steps = <(String, DateTime?)>[
      (tr(f, 'timeline.submitted'), r.createdAt),
      // No approved_at on the API, so this step never shows a time.
      (tr(f, 'timeline.review'), null),
      (
        tr(f, delivery ? (released ? 'status.delivered' : 'status.out_delivery') : (released ? 'status.picked_up' : 'status.ready_pickup')),
        r.releasedAt,
      ),
      (tr(f, 'status.returned'), null),
    ];

    return Column(
      children: [
        for (var i = 0; i < steps.length; i++)
          ProgressStep(
            label: steps[i].$1,
            detail: i < current && steps[i].$2 != null ? formatStepTime(steps[i].$2!) : null,
            state: i < current ? ProgressStepState.done : (i == current ? ProgressStepState.current : ProgressStepState.future),
            last: i == steps.length - 1,
          ),
      ],
    );
  }
}

enum ProgressStepState { done, current, future }

/// One row of a vertical progress list; Track draws its requests with it too.
class ProgressStep extends StatelessWidget {
  final String label;
  final String? detail;
  final ProgressStepState state;
  final bool last;

  const ProgressStep({super.key, required this.label, required this.detail, required this.state, required this.last});

  @override
  Widget build(BuildContext context) {
    final done = state == ProgressStepState.done;
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 24,
            child: Column(
              children: [
                Container(
                  width: 24,
                  height: 24,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: done ? AppColors.green700 : AppColors.surface,
                    border: done
                        ? null
                        : Border.all(
                            color: state == ProgressStepState.current ? AppColors.amberDot : AppColors.fieldBorder,
                            width: 3,
                          ),
                  ),
                  child: done ? const Icon(Icons.check_rounded, size: 16, color: AppColors.surface) : null,
                ),
                if (!last)
                  Expanded(
                    child: Container(width: 2, color: done ? AppColors.green700 : AppColors.line),
                  ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(bottom: last ? 0 : AppSpacing.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    label,
                    style: AppText.body(
                      size: _bodySize,
                      weight: state == ProgressStepState.future ? FontWeight.w400 : FontWeight.w600,
                      color: state == ProgressStepState.future ? AppColors.inkMuted : AppColors.ink,
                    ),
                  ),
                  if (detail != null) Text(detail!, style: AppText.body(size: _bodySize, color: AppColors.inkMuted)),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
