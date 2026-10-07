import 'package:flutter/material.dart';
import '../state/translations.dart';
import '../data/hotlines.dart';
import '../models/request_models.dart';
import '../state/request_store.dart';
import '../theme/app_theme.dart';
import '../widgets/borrow_request_widgets.dart';
import '../widgets/feedback.dart';
import '../widgets/offline_banner.dart';
import '../widgets/request_summary.dart';
import '../widgets/shared_widgets.dart';
import '../widgets/status_line.dart';

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

  /// A request id to scroll to (a row tapped in the bell sheet). Cleared once
  /// taken, so tapping the same row again still lands.
  final ValueNotifier<int?>? focusRequest;

  const TrackScreen({
    super.key,
    required this.appState,
    required this.onOpenNotifications,
    required this.onOpenProfile,
    required this.onOpenMyLoans,
    required this.onBrowseServices,
    this.focusRequest,
  });

  @override
  State<TrackScreen> createState() => _TrackScreenState();
}

class _TrackScreenState extends State<TrackScreen> {
  bool _showAllPast = false;
  final Map<int, GlobalKey> _cardKeys = {};

  @override
  void initState() {
    super.initState();
    widget.focusRequest?.addListener(_focus);
    _focus();
  }

  @override
  void didUpdateWidget(TrackScreen old) {
    super.didUpdateWidget(old);
    if (old.focusRequest != widget.focusRequest) {
      old.focusRequest?.removeListener(_focus);
      widget.focusRequest?.addListener(_focus);
    }
  }

  @override
  void dispose() {
    widget.focusRequest?.removeListener(_focus);
    super.dispose();
  }

  /// Scrolls to the focused card after the frame that shows this tab. A card
  /// folded under "Show older requests" is unfolded first, then scrolled to.
  void _focus() {
    final id = widget.focusRequest?.value;
    if (id == null) return;
    widget.focusRequest!.value = null;

    bool reveal() {
      final target = _cardKeys[id]?.currentContext;
      if (target == null) return false;
      // Below the pinned header rather than under it.
      Scrollable.ensureVisible(target, alignment: .2, duration: const Duration(milliseconds: 300));
      return true;
    }

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || reveal() || _showAllPast) return;
      setState(() => _showAllPast = true);
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) reveal();
      });
    });
  }

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

    Key? keyOf(ServiceRequest r) => r.id == null ? null : _cardKeys.putIfAbsent(r.id!, GlobalKey.new);

    Widget card(ServiceRequest r) => Padding(
          key: keyOf(r),
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
                      if (empty)
                        EmptyState(
                          title: tr(f, 'track.empty'),
                          body: tr(f, 'track.empty_body'),
                          actionLabel: tr(f, 'track.browse'),
                          onAction: widget.onBrowseServices,
                        ),
                      if (active.isNotEmpty) ...[
                        SectionHeader(title: tr(f, 'track.in_progress'), count: active.length),
                        for (final r in active) card(r),
                      ],
                      // Open loans, so every request Home counts is listed here.
                      if (loans.isNotEmpty) ...[
                        SectionHeader(title: tr(f, 'track.loans'), count: loans.length),
                        SummaryCard(children: [
                          for (final loan in loans) RequestSummaryRow(request: loan, onTap: widget.onOpenMyLoans),
                        ]),
                        const SizedBox(height: AppSpacing.xl),
                      ],
                      if (past.isNotEmpty) ...[
                        SectionHeader(title: tr(f, 'track.past')),
                        SummaryCard(children: [
                          for (final r in shownPast) _PastRow(key: keyOf(r), request: r, filipino: f),
                          if (!_showAllPast && past.length > _pastShown)
                            _ShowOlderRow(
                              label: tr(f, 'track.show_older'),
                              onTap: () => setState(() => _showAllPast = true),
                            ),
                        ]),
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

/// An open request: what it is, where it stands and since when, what happens
/// next, its steps, and the two things the resident can do about it.
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
    final status = serviceStatus(r, f);

    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(requestTitle(r, f), style: AppText.display(size: AppTextSize.cardTitle, weight: FontWeight.w600, height: 1.3)),
          const SizedBox(height: AppSpacing.xs),
          Text(
            [
              r.refNo.isEmpty ? tr(f, 'track.ref_pending') : r.refNo,
              if (r.createdAt != null) tr(f, 'track.sent').replaceAll('{date}', formatStepTime(r.createdAt!)),
            ].join(' · '),
            style: AppText.detail(),
          ),
          const SizedBox(height: AppSpacing.lg),
          StatusLine(
            label: status.label,
            tone: status.tone,
            large: true,
            updatedAt: r.updatedAt ?? r.createdAt,
            filipino: f,
          ),
          const SizedBox(height: 6),
          Text(status.next, style: AppText.body(color: AppColors.inkMuted, height: 1.5)),
          if (r.responders.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.md),
            Text(
              tr(f, 'request.responders_heading'),
              style: AppText.body(size: 15, weight: FontWeight.w600, color: AppColors.inkMuted),
            ),
            const SizedBox(height: 6),
            ...r.responders.map((responder) => _ResponderTile(responder: responder)),
          ],
          const SizedBox(height: AppSpacing.lg),
          _Steps(steps: r.timelineFor(f)),
          if (onCall != null) ...[
            const SizedBox(height: AppSpacing.lg),
            AppButton(label: tr(f, 'track.call'), icon: Icons.call_rounded, onPressed: onCall),
          ],
          if (r.cancellable) ...[
            const SizedBox(height: AppSpacing.sm),
            AppButton(
              label: tr(f, 'common.cancel_request'),
              style: AppButtonStyle.cancel,
              onPressed: () => showCancelDialog(context, r.refNo, onCancel, filipino: f),
            ),
          ],
        ],
      ),
    );
  }
}

/// A finished request, one row: "Completed · Sep 28 · TXN-000282", plus
/// MDRRMO's reason when it was not approved or the trip did not happen.
class _PastRow extends StatelessWidget {
  final ServiceRequest request;
  final bool filipino;

  const _PastRow({super.key, required this.request, required this.filipino});

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final r = request;
    final status = serviceStatus(r, f);
    final at = r.updatedAt ?? r.createdAt;
    final explains = r.status == ReqStatus.disapproved || r.isNotTransported;

    return ConstrainedBox(
      constraints: const BoxConstraints(minHeight: 68),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(requestTitle(r, f), style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600, height: 1.3)),
            const SizedBox(height: AppSpacing.xs),
            Text(
              [
                status.label,
                if (at != null) '${formatMonthShort(at)} ${at.toLocal().day}',
                if (r.refNo.isNotEmpty) r.refNo,
              ].join(' · '),
              style: AppText.detail(),
            ),
            if (explains) ...[
              const SizedBox(height: AppSpacing.xs),
              Text(status.next, style: AppText.detail()),
            ],
          ],
        ),
      ),
    );
  }
}

/// The last row of Past requests while some are still folded away.
class _ShowOlderRow extends StatelessWidget {
  final String label;
  final VoidCallback onTap;

  const _ShowOlderRow({required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 52),
        child: Center(
          child: Text(label, style: AppText.display(size: AppTextSize.body, weight: FontWeight.w600, color: AppColors.green700)),
        ),
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
