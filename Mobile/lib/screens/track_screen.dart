import 'package:flutter/material.dart';
import '../state/translations.dart';
import '../data/hotlines.dart';
import '../models/request_models.dart';
import '../state/request_store.dart';
import '../theme/app_theme.dart';
import '../widgets/borrow_request_widgets.dart';
import '../widgets/offline_banner.dart';
import '../widgets/request_summary.dart';
import '../widgets/shared_widgets.dart';

/// How many finished requests show before "Show older requests".
const _pastShown = 5;

class TrackScreen extends StatefulWidget {
  final AppState appState;
  final VoidCallback onOpenNotifications;
  final VoidCallback onOpenProfile;

  /// Opens Borrow → My requests, for a row under "Borrowed items".
  final VoidCallback onOpenMyLoans;

  /// Opens the Services tab, from the empty state.
  final VoidCallback onBrowseServices;

  const TrackScreen({
    super.key,
    required this.appState,
    required this.onOpenNotifications,
    required this.onOpenProfile,
    required this.onOpenMyLoans,
    required this.onBrowseServices,
  });

  @override
  State<TrackScreen> createState() => _TrackScreenState();
}

class _TrackScreenState extends State<TrackScreen> {
  bool _showAllPast = false;

  /// Newest first; a row with no date sinks.
  List<ServiceRequest> _newestFirst(Iterable<ServiceRequest> rows) {
    final epoch = DateTime.fromMillisecondsSinceEpoch(0);
    return rows.toList()..sort((a, b) => (b.createdAt ?? epoch).compareTo(a.createdAt ?? epoch));
  }

  @override
  Widget build(BuildContext context) {
    final f = widget.appState.language == AppLanguage.filipino;
    final requests = widget.appState.requests;
    final active = _newestFirst(requests.where(isOpenRequest));
    final past = _newestFirst(requests.where((r) => !isOpenRequest(r)));
    final shownPast = _showAllPast ? past : past.take(_pastShown).toList();
    final loans = openLoanSummaries(widget.appState, f);
    final phone = mdrrmoNumber(widget.appState.hotlines);

    Widget card(ServiceRequest r) => Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.md),
          child: _RequestCard(
            request: r,
            filipino: f,
            onCall: phone == null ? null : () => callHotlineNumber(phone),
            onCancel: () => widget.appState.cancelRequest(r.id),
          ),
        );

    final empty = active.isEmpty && past.isEmpty && loans.isEmpty;

    return RefreshIndicator(
      color: AppColors.green700,
      edgeOffset: TabHeader.minHeight,
      // Not silent: the resident pulled, so a failure owes them an answer.
      onRefresh: () => Future.wait([
        widget.appState.loadRequests(),
        if (widget.appState.borrowingAllowed) widget.appState.loadBorrowRequests(silent: true),
      ]),
      child: CustomScrollView(
        // Stated rather than inherited: the default physics refuse to overscroll
        // a list that fits, which kills the pull on the empty state, the screen
        // where a refresh is most useful.
        physics: const AlwaysScrollableScrollPhysics(),
        slivers: [
          SliverPersistentHeader(
            pinned: true,
            delegate: TabHeader(
              title: tr(f, 'track.title'),
              subtitle: tr(f, 'track.subtitle'),
              filipino: f,
              onNotifications: widget.onOpenNotifications,
              onProfile: widget.onOpenProfile,
            ),
          ),
          SliverToBoxAdapter(
            // Phone-width on a tablet, the web build or a desktop window.
            child: Align(
              alignment: Alignment.topCenter,
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 600),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, AppLayout.navClearance),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      // These rows came off the device, not the server. The
                      // dispatcher may have moved any of them since; saying when
                      // they were last confirmed is the difference between stale
                      // information and wrong information.
                      if (requests.isNotEmpty && widget.appState.requestsFromCache) ...[
                        StaleDataNote(filipino: f, lastUpdated: widget.appState.requestsFetchedAt),
                        const SizedBox(height: AppSpacing.md),
                      ],
                      if (empty) _EmptyState(filipino: f, onBrowse: widget.onBrowseServices),
                      if (active.isNotEmpty) ...[
                        _SectionTitle(tr(f, 'track.in_progress')),
                        for (final r in active) card(r),
                      ],
                      // Open loans, so everything Home's "See all (N)" counts is listed here.
                      if (loans.isNotEmpty) ...[
                        _SectionTitle(tr(f, 'track.loans')),
                        SummaryCard(children: [
                          for (final loan in loans) RequestSummaryRow(request: loan, onTap: widget.onOpenMyLoans),
                        ]),
                        const SizedBox(height: AppSpacing.lg),
                      ],
                      if (past.isNotEmpty) ...[
                        _SectionTitle(tr(f, 'track.past')),
                        for (final r in shownPast) card(r),
                        if (!_showAllPast && past.length > _pastShown)
                          BorrowCardButton(
                            label: tr(f, 'track.show_older'),
                            icon: Icons.expand_more_rounded,
                            onPressed: () => setState(() => _showAllPast = true),
                          ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  final String text;
  const _SectionTitle(this.text);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
        child: Text(text, style: AppText.display(size: 17, weight: FontWeight.w700, color: AppColors.green900)),
      );
}

class _EmptyState extends StatelessWidget {
  final bool filipino;
  final VoidCallback onBrowse;

  const _EmptyState({required this.filipino, required this.onBrowse});

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: AppSpacing.lg),
        Text(
          tr(filipino, 'track.empty'),
          textAlign: TextAlign.center,
          style: AppText.body(size: 16, color: AppColors.inkMuted, height: 1.5),
        ),
        const SizedBox(height: AppSpacing.lg),
        BorrowCardButton(
          label: tr(filipino, 'track.browse'),
          icon: Icons.grid_view_rounded,
          primary: true,
          onPressed: onBrowse,
        ),
      ],
    );
  }
}

/// Icon, plain-language title and one line on what happens next, in the colours
/// the Borrow "My requests" boxes use.
StatusBox _statusBox(ServiceRequest r, bool f) {
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

class _RequestCard extends StatelessWidget {
  final ServiceRequest request;
  final bool filipino;

  /// Dials MDRRMO; null hides the button (no number in the hotline list).
  final VoidCallback? onCall;

  /// Resolves to `true` only when the server confirmed the cancellation, so the
  /// dialog can hold its success message until then.
  final Future<bool> Function() onCancel;

  const _RequestCard({
    required this.request,
    required this.filipino,
    required this.onCall,
    required this.onCancel,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final r = request;
    final open = isOpenRequest(r);

    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(requestTitle(r, f), style: AppText.display(size: 18, weight: FontWeight.w600)),
          const SizedBox(height: 2),
          Text(
            r.refNo.isEmpty ? tr(f, 'track.ref_pending') : r.refNo,
            style: AppText.body(size: 14, color: AppColors.inkMuted),
          ),
          const SizedBox(height: AppSpacing.md),
          _statusBox(r, f),
          if (r.createdAt != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(
              tr(f, 'track.filed').replaceAll('{date}', formatStepTime(r.createdAt!)),
              style: AppText.body(size: 15, color: AppColors.inkMuted),
            ),
          ],
          if (open && r.responders.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.md),
            Text(
              tr(f, 'request.responders_heading'),
              style: AppText.body(size: 15, weight: FontWeight.w700, color: AppColors.inkMuted),
            ),
            const SizedBox(height: 6),
            ...r.responders.map((responder) => _ResponderTile(responder: responder)),
          ],
          if (open) ...[
            const SizedBox(height: AppSpacing.lg),
            _Steps(steps: r.timelineFor(f)),
          ],
          if (open && onCall != null) ...[
            const SizedBox(height: AppSpacing.lg),
            BorrowCardButton(label: tr(f, 'track.call'), icon: Icons.call_rounded, primary: true, onPressed: onCall!),
          ],
          if (r.cancellable) ...[
            const SizedBox(height: AppSpacing.sm),
            BorrowCardButton(
              label: tr(f, 'common.cancel_request'),
              icon: Icons.close_rounded,
              textColor: AppColors.red600,
              onPressed: () => showCancelDialog(context, r.refNo, onCancel, filipino: f),
            ),
          ],
        ],
      ),
    );
  }
}

/// The request's own timeline (derived from its real status) as vertical steps.
class _Steps extends StatelessWidget {
  final List<TimelineStep> steps;
  const _Steps({required this.steps});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        for (var i = 0; i < steps.length; i++)
          ProgressStep(
            label: steps[i].title,
            detail: steps[i].time,
            state: switch (steps[i].state) {
              RequestStepState.done => ProgressStepState.done,
              RequestStepState.current => ProgressStepState.current,
              RequestStepState.pending => ProgressStepState.future,
            },
            last: i == steps.length - 1,
          ),
      ],
    );
  }
}

class _ResponderTile extends StatelessWidget {
  final RequestResponder responder;
  const _ResponderTile({required this.responder});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Container(
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(AppRadius.sm)),
        child: Row(
          children: [
            CircleAvatar(
              radius: 18,
              backgroundColor: AppColors.blue50,
              backgroundImage: responder.photoUrl != null ? NetworkImage(responder.photoUrl!) : null,
              child: responder.photoUrl == null
                  ? Text(
                      responder.name.isEmpty ? '?' : responder.name[0].toUpperCase(),
                      style: AppText.display(size: AppTextSize.bodyLg, color: AppColors.blue600),
                    )
                  : null,
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(responder.name, style: AppText.display(size: 15)),
                  Text(responder.position, style: AppText.body(size: 15, color: AppColors.inkMuted)),
                ],
              ),
            ),
            if (responder.contactNo.isNotEmpty)
              IconButton(
                icon: const Icon(Icons.call_outlined, size: 20, color: AppColors.blue600),
                onPressed: () => callHotlineNumber(responder.contactNo),
              ),
          ],
        ),
      ),
    );
  }
}
