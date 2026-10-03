import 'package:flutter/material.dart';

import '../models/borrow_models.dart';
import '../models/request_models.dart';
import '../state/request_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';

/// One open request as Home's "Your requests" and Track's "Borrowed items"
/// draw it: a short title and a plain-language status.
class RequestSummary {
  final String title;
  final String status;

  /// Amber "waiting on MDRRMO" rather than green "under way".
  final bool waiting;
  final DateTime? createdAt;

  /// A loan opens Borrow → My requests; a service request opens Track.
  final bool isBorrow;

  const RequestSummary({
    required this.title,
    required this.status,
    required this.waiting,
    required this.createdAt,
    required this.isBorrow,
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
        if (isOpenRequest(r))
          RequestSummary(
            title: requestTitle(r, f),
            status: switch (r.status) {
              ReqStatus.review => tr(f, 'home.req.waiting'),
              ReqStatus.scheduled when !r.isProgram => tr(f, 'timeline.responding'),
              _ => r.statusLabelFor(f),
            },
            waiting: r.status == ReqStatus.review,
            createdAt: r.createdAt,
            isBorrow: false,
          ),
    ]);

/// Open loans (pending, approved, released), newest first. Home and Track both
/// read this, so "See all (N)" counts exactly what Track lists.
List<RequestSummary> openLoanSummaries(AppState state, bool f) => _newestFirst([
      for (final b in state.borrowRequests)
        if (!b.status.isTerminal)
          RequestSummary(
            title: '${b.itemLabel} × ${b.quantity}',
            status: b.status == BorrowStatus.pending ? tr(f, 'home.req.waiting') : b.status.labelFor(f),
            waiting: b.status == BorrowStatus.pending,
            createdAt: b.createdAt,
            isBorrow: true,
          ),
    ]);

/// Every open request, services and loans together, newest first.
List<RequestSummary> openSummaries(AppState state, bool f) =>
    _newestFirst([...openServiceSummaries(state, f), ...openLoanSummaries(state, f)]);

class RequestSummaryRow extends StatelessWidget {
  final RequestSummary request;
  final VoidCallback onTap;

  const RequestSummaryRow({super.key, required this.request, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final (Color bg, Color fg, Color dot) = request.waiting
        ? (AppColors.amber50, AppColors.amberInk, AppColors.amberDot)
        : (AppColors.greenTonal, AppColors.green900, AppColors.green600);

    return InkWell(
      onTap: onTap,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 72),
        child: Padding(
          padding: const EdgeInsets.all(16),
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
                      style: AppText.display(size: 16, weight: FontWeight.w600),
                    ),
                    const SizedBox(height: 6),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(AppRadius.pill)),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Container(width: 8, height: 8, decoration: BoxDecoration(color: dot, shape: BoxShape.circle)),
                          const SizedBox(width: 6),
                          Flexible(
                            child: Text(request.status, style: AppText.display(size: 13.5, weight: FontWeight.w500, color: fg)),
                          ),
                        ],
                      ),
                    ),
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
        borderRadius: BorderRadius.circular(18),
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
