import 'package:flutter/material.dart';
import '../state/strings.dart';
import '../models/models.dart';
import '../state/app_state.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';

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
  final Set<String> _expanded = {};

  @override
  Widget build(BuildContext context) {
    final f = widget.appState.language == AppLanguage.filipino;
    final requests = widget.appState.requests;
    final filtered = _filter == null ? requests : requests.where((r) => r.status == _filter).toList();

    return ListView(
      padding: EdgeInsets.zero,
      children: [
        AppHeader(onNotificationsTap: widget.onOpenNotifications, onProfileTap: widget.onOpenProfile),
        const SizedBox(height: 22),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 22),
          child: SectionHeader(title: tr(f, 'track.title')),
        ),
        if (requests.isEmpty)
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
                            Text(tr(f, 'track.empty_title'), style: AppText.display(size: 14.5)),
                            const SizedBox(height: 2),
                            Text(
                              tr(f, 'track.empty_desc'),
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
                _filterChip('${tr(f, "track.filter.all")} (${requests.length})', null, f),
                _filterChip(tr(f, 'status.review'), ReqStatus.review, f),
                _filterChip(tr(f, 'status.scheduled'), ReqStatus.scheduled, f),
                _filterChip(tr(f, 'status.completed'), ReqStatus.completed, f),
                _filterChip(tr(f, 'status.cancelled'), ReqStatus.cancelled, f),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 18, 22, 0),
            child: Column(
              children: filtered.map((r) => _RequestCard(
                request: r,
                expanded: _expanded.contains(r.refNo),
                filipino: f,
                onToggle: () => setState(() {
                  if (_expanded.contains(r.refNo)) {
                    _expanded.remove(r.refNo);
                  } else {
                    _expanded.add(r.refNo);
                  }
                }),
                onCancel: () => widget.appState.cancelRequest(r.refNo),
              )).toList(),
            ),
          ),
        ],
        const SizedBox(height: 110),
      ],
    );
  }

  Widget _filterChip(String label, ReqStatus? status, bool f) {
    final active = _filter == status;
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: ChoiceChip(
        label: Text(label),
        selected: active,
        onSelected: (_) => setState(() => _filter = status),
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
  final VoidCallback onCancel;

  const _RequestCard({
    required this.request,
    required this.expanded,
    required this.filipino,
    required this.onToggle,
    required this.onCancel,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final accent = switch (request.status) {
      ReqStatus.completed => AppColors.green700,
      ReqStatus.cancelled => AppColors.inkFaint,
      ReqStatus.scheduled => AppColors.amber600,
      ReqStatus.review => AppColors.blue600,
    };

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
                IconBadge(icon: request.type.icon, bg: request.type.bg, fg: request.type.fg),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(request.type.titleFor(f), style: AppText.display(size: 14.5)),
                      const SizedBox(height: 2),
                      Text('Ref #${request.refNo}', style: AppText.body(size: 11.5, color: AppColors.inkMuted)),
                    ],
                  ),
                ),
                StatusBadge(request.status, filipino: f),
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
            if (request.timeline.isNotEmpty) ...[
              const SizedBox(height: 12),
              AppButton(
                label: expanded ? tr(f, 'common.hide_timeline') : tr(f, 'common.view_timeline'),
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
                  child: _Timeline(steps: request.timeline),
                ),
              ),
            ],
            if (request.cancellable) ...[
              const SizedBox(height: 10),
              AppButton(
                label: tr(f, 'common.cancel_request'),
                style: AppButtonStyle.ghostRed,
                onPressed: () => showCancelDialog(context, request.refNo, onCancel, filipino: f),
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
