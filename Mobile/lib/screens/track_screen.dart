import 'package:flutter/material.dart';
import '../state/translations.dart';
import '../models/request_models.dart';
import '../state/request_store.dart';
import '../theme/app_theme.dart';
import '../widgets/offline_banner.dart';
import '../widgets/shared_widgets.dart';

class TrackScreen extends StatefulWidget {
  final AppState appState;
  final VoidCallback onOpenNotifications;
  final VoidCallback onOpenProfile;

  const TrackScreen({
    super.key,
    required this.appState,
    required this.onOpenNotifications,
    required this.onOpenProfile,
  });

  @override
  State<TrackScreen> createState() => _TrackScreenState();
}

class _TrackScreenState extends State<TrackScreen> {
  ReqStatus? _filter;
  final Set<int> _expanded = {};

  /// Keyed on the server's request id. `refNo` is empty for a row that has not
  /// been confirmed yet, so every in-flight row shared one key and expanding
  /// any of them expanded all of them.
  int _keyFor(ServiceRequest request) => request.id ?? identityHashCode(request);

  void _changeFilter(ReqStatus? status) {
    setState(() {
      _filter = status;
    });
  }

  void _toggleExpanded(int key) {
    setState(() {
      if (_expanded.contains(key)) {
        _expanded.remove(key);
      } else {
        _expanded.add(key);
      }
    });
  }

  List<ServiceRequest> _getFilteredRequests(List<ServiceRequest> allRequests) {
    if (_filter == null) {
      return allRequests;
    }

    final filtered = <ServiceRequest>[];
    for (final request in allRequests) {
      if (request.status == _filter) {
        filtered.add(request);
      }
    }
    return filtered;
  }

  @override
  Widget build(BuildContext context) {
    final isFilipino = widget.appState.language == AppLanguage.filipino;
    final requests = widget.appState.requests;
    final filtered = _getFilteredRequests(requests);
    final hasRequests = requests.isNotEmpty;

    final list = ListView(
      padding: EdgeInsets.zero,
      // Stated rather than inherited. A `ListView` gets this for free only
      // while it is `primary`, which it stops being the moment anyone gives it
      // a controller — and the default physics refuse to overscroll a list that
      // fits, which kills the pull on the empty state, the screen where a
      // refresh is most useful.
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        AppHeader(onNotificationsTap: widget.onOpenNotifications, onProfileTap: widget.onOpenProfile),
        const SizedBox(height: 22),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 22),
          child: SectionHeader(title: tr(isFilipino, 'track.title')),
        ),
        // These rows came off the device, not the server. The dispatcher may
        // have moved any of them since; saying when they were last confirmed is
        // the difference between stale information and wrong information.
        if (hasRequests && widget.appState.requestsFromCache)
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 12, 22, 0),
            child: StaleDataNote(
              filipino: isFilipino,
              lastUpdated: widget.appState.requestsFetchedAt,
            ),
          ),
        if (!hasRequests)
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 18, 22, 0),
            child: AppCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const IconBadge(
                        icon: Icons.fact_check_outlined,
                        bg: AppColors.green50,
                        fg: AppColors.green700,
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(tr(isFilipino, 'track.empty_title'), style: AppText.display(size: 14.5)),
                            const SizedBox(height: 2),
                            Text(
                              tr(isFilipino, 'track.empty_desc'),
                              style: AppText.body(size: 12, color: AppColors.inkMuted, height: 1.5),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          )
        else ...[
          SizedBox(
            height: 40,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 22),
              children: [
                _filterChip('${tr(isFilipino, "track.filter.all")} (${requests.length})', null, isFilipino),
                _filterChip(tr(isFilipino, 'status.review'), ReqStatus.review, isFilipino),
                _filterChip(tr(isFilipino, 'status.booked'), ReqStatus.booked, isFilipino),
                _filterChip(tr(isFilipino, 'status.scheduled'), ReqStatus.scheduled, isFilipino),
                _filterChip(tr(isFilipino, 'status.completed'), ReqStatus.completed, isFilipino),
                _filterChip(tr(isFilipino, 'status.cancelled'), ReqStatus.cancelled, isFilipino),
                _filterChip(tr(isFilipino, 'status.disapproved'), ReqStatus.disapproved, isFilipino),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 18, 22, 0),
            child: Column(
              children: filtered.map((request) => _RequestCard(
                request: request,
                expanded: _expanded.contains(_keyFor(request)),
                filipino: isFilipino,
                onToggle: () => _toggleExpanded(_keyFor(request)),
                onCancel: () => widget.appState.cancelRequest(request.id),
              )).toList(),
            ),
          ),
        ],
        const SizedBox(height: 110),
      ],
    );

    return RefreshIndicator(
      color: AppColors.green700,
      // Not silent: the resident pulled, so a failure owes them an answer.
      onRefresh: () => widget.appState.loadRequests(),
      child: list,
    );
  }

  Widget _filterChip(String label, ReqStatus? status, bool f) {
    final active = _filter == status;
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: ChoiceChip(
        label: Text(label),
        selected: active,
        onSelected: (_) => _changeFilter(status),
        labelStyle: AppText.display(
          size: 12,
          weight: FontWeight.w600,
          color: active ? Colors.white : AppColors.inkMuted,
        ),
        backgroundColor: AppColors.surface,
        selectedColor: AppColors.green700,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(30),
          side: BorderSide(color: active ? AppColors.green700 : AppColors.line, width: 1.5),
        ),
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
        showCheckmark: false,
      ),
    );
  }
}

class _RequestCard extends StatelessWidget {
  final ServiceRequest request;
  final bool expanded;
  final bool filipino;
  final VoidCallback onToggle;
  /// Resolves to `true` only when the server confirmed the cancellation, so the
  /// dialog can hold its success message until then.
  final Future<bool> Function() onCancel;

  const _RequestCard({
    required this.request,
    required this.expanded,
    required this.filipino,
    required this.onToggle,
    required this.onCancel,
  });

  Color _getAccentColor() {
    if (request.isOverdue) {
      return AppColors.red600;
    }
    if (request.status == ReqStatus.completed) {
      return AppColors.green700;
    }
    if (request.status == ReqStatus.cancelled) {
      return AppColors.inkFaint;
    }
    if (request.status == ReqStatus.disapproved) {
      return AppColors.red600;
    }
    if (request.status == ReqStatus.booked) {
      return const Color(0xFF6A1B9A);
    }
    if (request.status == ReqStatus.scheduled) {
      return AppColors.amber600;
    }
    return AppColors.blue600;
  }

  @override
  Widget build(BuildContext context) {
    final accent = _getAccentColor();

    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: AppCard(
        leftAccent: accent,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                IconBadge(icon: request.displayIcon, bg: request.displayBg, fg: request.displayFg),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(request.displayTitle(filipino), style: AppText.display(size: 14.5)),
                      const SizedBox(height: 2),
                      Text(
                        request.refNo.isEmpty
                            ? (filipino ? 'Naghihintay ng reference number' : 'Reference number pending')
                            : 'Ref #${request.refNo}',
                        style: AppText.body(size: 11.5, color: AppColors.inkMuted),
                      ),
                    ],
                  ),
                ),
                StatusBadge(request.status, filipino: filipino),
              ],
            ),
            const SizedBox(height: 12),
            ...request.metaLines.map((m) => Padding(
                  padding: const EdgeInsets.only(bottom: 5),
                  child: Row(
                    children: [
                      const Icon(Icons.place_outlined, size: 14, color: AppColors.inkFaint),
                      const SizedBox(width: 8),
                      Expanded(child: Text(m, style: AppText.body(size: 12.5))),
                    ],
                  ),
                )),
            if (request.note != null)
              Container(
                margin: const EdgeInsets.only(top: 6),
                width: double.infinity,
                padding: const EdgeInsets.all(11),
                decoration: BoxDecoration(color: AppColors.paper, borderRadius: BorderRadius.circular(10)),
                child: Text(request.note!, style: AppText.body(size: 12, color: AppColors.inkMuted, height: 1.6)),
              ),
            if (request.isOverdue)
              Container(
                margin: const EdgeInsets.only(top: 6),
                width: double.infinity,
                padding: const EdgeInsets.all(11),
                decoration: BoxDecoration(color: AppColors.red50, borderRadius: BorderRadius.circular(10)),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Icon(Icons.warning_amber_rounded, size: 16, color: AppColors.red600),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        tr(filipino, 'common.booking_overdue'),
                        style: AppText.body(size: 12, color: AppColors.red600, height: 1.6),
                      ),
                    ),
                  ],
                ),
              ),
            // No isNotEmpty guard any more: the timeline is derived from the
            // request's own status and timestamps, so every card has one --
            // including the server-loaded rows that used to lose the button
            // entirely after a relaunch.
            const SizedBox(height: 12),
            AppButton(
              label: expanded ? tr(filipino, 'common.hide_timeline') : tr(filipino, 'common.view_timeline'),
              icon: expanded ? Icons.expand_less_rounded : Icons.expand_more_rounded,
              style: AppButtonStyle.outline,
              onPressed: onToggle,
            ),
            AnimatedCrossFade(
              duration: const Duration(milliseconds: 200),
              crossFadeState: expanded ? CrossFadeState.showSecond : CrossFadeState.showFirst,
              firstChild: const SizedBox(width: double.infinity),
              secondChild: Padding(
                padding: const EdgeInsets.only(top: 16),
                child: _Timeline(steps: request.timelineFor(filipino)),
              ),
            ),
            if (request.cancellable) ...[
              const SizedBox(height: 10),
              AppButton(
                label: tr(filipino, 'common.cancel_request'),
                style: AppButtonStyle.ghostRed,
                onPressed: () => showCancelDialog(context, request.refNo, onCancel, filipino: filipino),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _Timeline extends StatelessWidget {
  final List<TimelineStep> steps;
  const _Timeline({required this.steps});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: List.generate(steps.length, (i) {
        final step = steps[i];
        final isLast = i == steps.length - 1;
        final dotDone = step.state != RequestStepState.pending;
        final dotColor = switch (step.state) {
          RequestStepState.done => AppColors.green700,
          RequestStepState.current => AppColors.amber600,
          RequestStepState.pending => AppColors.inkFaint,
        };
        final dotBg = switch (step.state) {
          RequestStepState.done => AppColors.green50,
          RequestStepState.current => AppColors.amber50,
          RequestStepState.pending => AppColors.surface,
        };

        return IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Column(
                children: [
                  Container(
                    width: 22,
                    height: 22,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: dotBg,
                      border: Border.all(color: step.state == RequestStepState.pending ? AppColors.line : dotColor, width: 2),
                    ),
                    alignment: Alignment.center,
                    child: step.state == RequestStepState.done
                        ? Icon(Icons.check, size: 11, color: dotColor)
                        : step.state == RequestStepState.current
                            ? Icon(Icons.calendar_today_rounded, size: 10, color: dotColor)
                            : null,
                  ),
                  if (!isLast)
                    Expanded(
                      child: Container(
                        width: 2,
                        color: dotDone ? AppColors.green700 : AppColors.line,
                      ),
                    ),
                ],
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Padding(
                  padding: EdgeInsets.only(bottom: isLast ? 0 : 22),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        step.title,
                        style: AppText.display(
                          size: 13,
                          weight: FontWeight.w600,
                          color: step.state == RequestStepState.pending ? AppColors.inkFaint : AppColors.ink,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(step.time, style: AppText.body(size: 11, color: AppColors.inkFaint)),
                    ],
                  ),
                ),
              ),
            ],
          ),
        );
      }),
    );
  }
}
