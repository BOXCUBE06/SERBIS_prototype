import 'package:flutter/material.dart';

import '../models/borrow_models.dart';
import '../models/request_models.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'borrow_request_widgets.dart' show borrowStatus, serviceStatus;
import 'status_line.dart';

/// One open request as Home's latest-request panel and Track's "Borrowed
/// items" draw it: a short title and its status in the shared wording.
class RequestSummary {
  final String title;
  final String status;
  final StatusTone tone;
  final DateTime? createdAt;

  /// When the row last changed, for "Updated <time>".
  final DateTime? updatedAt;

  /// Steps done so far out of [steps], for Home's progress bar.
  final int step;
  final int steps;

  /// A loan opens Borrow → My requests; a service request opens Track.
  final bool isBorrow;

  const RequestSummary({
    required this.title,
    required this.status,
    required this.tone,
    required this.createdAt,
    required this.isBorrow,
    this.updatedAt,
    this.step = 0,
    this.steps = 0,
  });
}

/// Newest first; a row with no date sinks.
List<RequestSummary> _newestFirst(List<RequestSummary> rows) {
  final epoch = DateTime.fromMillisecondsSinceEpoch(0);
  return rows..sort((a, b) => (b.createdAt ?? epoch).compareTo(a.createdAt ?? epoch));
}

/// Pending, Booked or Responding: the requests Home and Track call open.
bool isOpenRequest(ServiceRequest r) =>
    r.status == ReqStatus.review || r.status == ReqStatus.booked || r.status == ReqStatus.scheduled;

/// "Ambulance to <destination>" for a trip, the service name for anything else.
String requestTitle(ServiceRequest r, bool f) =>
    (r.type == ServiceType.ambulance || r.type == ServiceType.transfer) && (r.destination ?? '').trim().isNotEmpty
        ? tr(f, 'home.req.ambulance_to').replaceAll('{place}', r.destination!.trim())
        : r.displayTitle(f);

/// Open service requests (Pending, Booked, Responding), newest first.
List<RequestSummary> openServiceSummaries(AppState state, bool f) => _newestFirst([
      for (final r in state.requests)
        if (isOpenRequest(r)) _serviceSummary(r, f),
    ]);

RequestSummary _serviceSummary(ServiceRequest r, bool f) {
  final status = serviceStatus(r, f);
  final timeline = r.timelineFor(f);
  final current = timeline.indexWhere((s) => s.state == RequestStepState.current);
  return RequestSummary(
    title: requestTitle(r, f),
    status: status.label,
    tone: status.tone,
    createdAt: r.createdAt,
    updatedAt: r.updatedAt ?? r.createdAt,
    step: current < 0 ? timeline.length : current,
    steps: timeline.length,
    isBorrow: false,
  );
}

/// Open loans (pending, approved, released), newest first. Home and Track both
/// read this, so what Home counts is exactly what Track lists.
List<RequestSummary> openLoanSummaries(AppState state, bool f) => _newestFirst([
      for (final b in state.borrowRequests)
        if (!b.status.isTerminal)
          RequestSummary(
            title: '${b.itemLabel} × ${b.quantity}',
            status: borrowStatus(b, f).label,
            tone: borrowStatus(b, f).tone,
            createdAt: b.createdAt,
            updatedAt: b.updatedAt ?? b.createdAt,
            // Sent, Under review, Ready/Out for delivery, Returned: the step in
            // progress, as BorrowProgressSteps draws it.
            step: switch (b.status) {
              BorrowStatus.pending => 1,
              BorrowStatus.approved => 2,
              _ => 3,
            },
            steps: 4,
            isBorrow: true,
          ),
    ]);

/// Every open request, services and loans together, newest first.
List<RequestSummary> openSummaries(AppState state, bool f) =>
    _newestFirst([...openServiceSummaries(state, f), ...openLoanSummaries(state, f)]);

/// Title over a small status line, with a chevron.
class RequestSummaryRow extends StatelessWidget {
  final RequestSummary request;
  final VoidCallback onTap;

  const RequestSummaryRow({super.key, required this.request, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 68),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      request.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600, height: 1.3),
                    ),
                    const SizedBox(height: AppSpacing.xs),
                    StatusLine(label: request.status, tone: request.tone),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              const Icon(Icons.chevron_right_rounded, color: AppColors.inkMuted),
            ],
          ),
        ),
      ),
    );
  }
}

/// White rounded card with hairlines between its rows.
class SummaryCard extends StatelessWidget {
  final List<Widget> children;

  const SummaryCard({super.key, required this.children});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.surface,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(AppRadius.lg),
        side: const BorderSide(color: AppColors.cardBorder),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var i = 0; i < children.length; i++) ...[
            if (i > 0) const Divider(height: 1, thickness: 1, color: AppColors.cardDivider),
            children[i],
          ],
        ],
      ),
    );
  }
}
