import 'package:flutter/material.dart';

import '../data/hotlines.dart';
import '../models/borrow_models.dart';
import '../models/request_models.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';

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

/// Colored box: icon, plain-language title, one line on what happens next.
class BorrowStatusBox extends StatelessWidget {
  final BorrowRequest request;
  final bool filipino;

  const BorrowStatusBox({super.key, required this.request, required this.filipino});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final r = request;
    final delivery = _isDelivery(r);
    final (IconData icon, Color bg, Color fg, String title, String next) = switch (r.status) {
      BorrowStatus.pending => (
          Icons.hourglass_top_rounded,
          AppColors.amber50,
          AppColors.amberInk,
          trEn(f, 'Waiting for MDRRMO'),
          trEn(f, 'MDRRMO will review your request and update it here.'),
        ),
      BorrowStatus.approved => (
          Icons.check_circle_rounded,
          AppColors.green50,
          AppColors.green700,
          trEn(f, delivery ? 'Approved — out for delivery' : 'Approved — ready to pick up'),
          trEn(f, delivery ? 'MDRRMO will bring it to your address.' : 'Pick it up at the MDRRMO office.'),
        ),
      BorrowStatus.released => (
          Icons.inventory_2_rounded,
          AppColors.green50,
          AppColors.green700,
          trEn(f, delivery ? 'Delivered to you' : 'You have the item'),
          r.dueDate == null
              ? trEn(f, "Return it to MDRRMO when you're done.")
              : '${trEn(f, 'Please return it by {date}.').replaceAll('{date}', formatDueDate(r.dueDate!))}'
                  ' (${dueLabel(r.dueDate!, null, f)})',
        ),
      BorrowStatus.returned => (
          Icons.task_alt_rounded,
          AppColors.green50,
          AppColors.green700,
          trEn(f, 'Returned'),
          trEn(f, 'Thank you for returning it.'),
        ),
      BorrowStatus.denied => (
          Icons.block_rounded,
          AppColors.red50,
          AppColors.red600,
          trEn(f, 'Not approved'),
          r.denialReason?.trim().isNotEmpty == true
              ? '${trEn(f, "MDRRMO's reason:")} ${r.denialReason!.trim()}'
              : trEn(f, 'MDRRMO could not approve this request.'),
        ),
      BorrowStatus.cancelled => (
          Icons.cancel_outlined,
          AppColors.grey50,
          AppColors.inkMuted,
          trEn(f, 'Cancelled'),
          trEn(f, 'You cancelled this request.'),
        ),
    };

    return StatusBox(icon: icon, bg: bg, fg: fg, title: title, next: next);
  }
}

/// The box itself, shared with Track so a service request reads like a loan.
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
                Text(title, style: AppText.display(size: 16, weight: FontWeight.w700, color: fg)),
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

/// Icon, plain-language title and one line on what happens next, in the colours
/// the Borrow "My requests" boxes use.
StatusBox serviceStatusBox(ServiceRequest r, bool f) {
  final reason = r.note?.trim() ?? '';
  final (IconData icon, Color bg, Color fg, String title, String next) = switch (r.status) {
    ReqStatus.review => (
        Icons.hourglass_top_rounded,
        AppColors.amber50,
        AppColors.amberInk,
        tr(f, 'track.box.review.title'),
        tr(f, 'track.box.review.next'),
      ),
    ReqStatus.booked when r.isOverdue => (
        Icons.warning_amber_rounded,
        AppColors.red50,
        AppColors.red600,
        tr(f, 'track.box.overdue.title'),
        tr(f, 'common.booking_overdue'),
      ),
    ReqStatus.booked => (
        Icons.event_available_rounded,
        AppColors.green50,
        AppColors.green700,
        tr(f, 'track.box.booked.title'),
        r.scheduledAt == null
            ? tr(f, 'track.box.booked.next')
            : tr(f, 'track.box.booked.next_dated').replaceAll('{date}', formatBookingConfirmationTime(r.scheduledAt!, f)),
      ),
    ReqStatus.scheduled when r.isProgram => (
        Icons.check_circle_rounded,
        AppColors.green50,
        AppColors.green700,
        tr(f, 'status.approved'),
        tr(f, 'track.box.approved.next'),
      ),
    ReqStatus.scheduled => (
        Icons.directions_run_rounded,
        AppColors.green50,
        AppColors.green700,
        tr(f, 'timeline.responding'),
        tr(f, 'track.box.responding.next'),
      ),
    ReqStatus.completed when r.isNotTransported => (
        Icons.info_outline_rounded,
        AppColors.amber50,
        AppColors.amberInk,
        tr(f, 'status.not_transported'),
        r.noArrivalReason!.trim(),
      ),
    ReqStatus.completed => (
        Icons.task_alt_rounded,
        AppColors.green50,
        AppColors.green700,
        tr(f, 'status.completed'),
        tr(f, 'track.box.completed.next'),
      ),
    ReqStatus.cancelled => (
        Icons.cancel_outlined,
        AppColors.grey50,
        AppColors.inkMuted,
        tr(f, 'status.cancelled'),
        tr(f, 'track.box.cancelled.next'),
      ),
    ReqStatus.disapproved => (
        Icons.block_rounded,
        AppColors.red50,
        AppColors.red600,
        tr(f, 'status.disapproved'),
        reason.isEmpty
            ? tr(f, 'track.box.disapproved.next')
            : tr(f, 'track.box.reason').replaceAll('{reason}', reason),
      ),
  };
  return StatusBox(icon: icon, bg: bg, fg: fg, title: title, next: next);
}

/// Request sent, Reviewed by MDRRMO, Ready/Out for delivery, Returned — for an
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
      (trEn(f, 'Request sent'), r.createdAt),
      // No approved_at on the API, so this step never shows a time.
      (trEn(f, 'Reviewed by MDRRMO'), null),
      (
        trEn(f, delivery ? (released ? 'Delivered' : 'Out for delivery') : (released ? 'Picked up' : 'Ready to pick up')),
        r.releasedAt,
      ),
      (trEn(f, 'Returned'), null),
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

/// Grey "Cancelled" chip with an X, for a past cancelled request.
class CancelledChip extends StatelessWidget {
  final bool filipino;

  const CancelledChip({super.key, required this.filipino});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(color: AppColors.grey50, borderRadius: BorderRadius.circular(AppRadius.pill)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.close_rounded, size: 18, color: AppColors.inkMuted),
          const SizedBox(width: 4),
          Text(
            trEn(filipino, 'Cancelled'),
            style: AppText.body(size: _bodySize, weight: FontWeight.w600, color: AppColors.inkMuted),
          ),
        ],
      ),
    );
  }
}

/// 52px-tall card action: filled primary, or white outlined (red text for Cancel).
class BorrowCardButton extends StatelessWidget {
  final String label;
  final IconData icon;
  final VoidCallback onPressed;
  final bool primary;
  final Color? textColor;

  const BorrowCardButton({
    super.key,
    required this.label,
    required this.icon,
    required this.onPressed,
    this.primary = false,
    this.textColor,
  });

  @override
  Widget build(BuildContext context) {
    final fg = primary ? AppColors.surface : (textColor ?? AppColors.green700);
    final style = AppText.display(size: 16, weight: FontWeight.w600, color: fg);
    final shape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md));
    return SizedBox(
      width: double.infinity,
      height: 52,
      child: primary
          ? ElevatedButton.icon(
              onPressed: onPressed,
              icon: Icon(icon, size: 20),
              label: Text(label, style: style),
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.green700,
                foregroundColor: fg,
                elevation: 0,
                shape: shape,
              ),
            )
          : OutlinedButton.icon(
              onPressed: onPressed,
              icon: Icon(icon, size: 20),
              label: Text(label, style: style),
              style: OutlinedButton.styleFrom(
                backgroundColor: AppColors.surface,
                foregroundColor: fg,
                side: const BorderSide(color: AppColors.fieldBorder, width: 1.5),
                shape: shape,
              ),
            ),
    );
  }
}
