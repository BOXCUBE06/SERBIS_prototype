import 'package:flutter/material.dart';

import '../data/hotlines.dart';
import '../theme/app_theme.dart';
import 'request_summary.dart' show SummaryCard;
import 'shared_widgets.dart';

/// Every hotline, each number its own `tel:` row. Shared by the Library card
/// and the pre-login [HotlinesPage].
///
/// Contact name once, then every number that reaches it as its own tappable
/// 48dp row — a contact with several lines (the rescue hotline: landline, Globe,
/// Smart, Sun) is not one action, it is "pick the one that reaches you and
/// dial that one".
class HotlineList extends StatelessWidget {
  final List<Hotline> hotlines;
  final bool filipino;

  const HotlineList({super.key, required this.hotlines, required this.filipino});

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < hotlines.length; i++) ...[
          if (i > 0) const Divider(height: 1, thickness: 1, color: AppColors.cardDivider),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 8, 4),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  hotlines[i].labelFor(filipino: filipino),
                  style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600),
                ),
                for (final n in hotlines[i].numbers) _numberRow(n),
              ],
            ),
          ),
        ],
      ],
    );
  }

  Widget _numberRow(HotlineNumber n) {
    return InkWell(
      borderRadius: BorderRadius.circular(AppRadius.sm),
      onTap: () => callHotlineNumber(n.number),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 48),
        child: Row(
          children: [
            Expanded(
              child: Text(
                n.label == null ? n.number : '${n.label} · ${n.number}',
                style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w700, color: AppColors.green700),
              ),
            ),
            const SizedBox(width: 8),
            const Padding(
              padding: EdgeInsets.only(right: 8),
              child: Icon(Icons.call_rounded, size: 20, color: AppColors.green700),
            ),
          ],
        ),
      ),
    );
  }
}

/// Hotlines reachable from the login screen, before an account exists. The
/// login flow is English-only, so this is too.
class HotlinesPage extends StatelessWidget {
  final List<Hotline> hotlines;

  const HotlinesPage({super.key, required this.hotlines});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.paper,
      body: Column(
        children: [
          TabHeaderBar(
            title: 'Emergency hotlines',
            subtitle: 'Tap a number to call',
            filipino: false,
            onBack: () => Navigator.of(context).maybePop(),
          ),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
              children: [
                Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 600),
                    child: SummaryCard(children: [HotlineList(hotlines: hotlines, filipino: false)]),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
