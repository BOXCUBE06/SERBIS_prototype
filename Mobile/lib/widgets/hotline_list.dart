import 'package:flutter/material.dart';

import '../data/hotlines.dart';
import '../theme/app_theme.dart';

/// Every hotline, each number its own `tel:` row. Shared by the Library card
/// and the pre-login [HotlinesPage].
///
/// Contact name once, then every number that reaches it as its own tappable
/// row — a contact with several lines (the rescue hotline: landline, Globe,
/// Smart, Sun) is not one action, it is "pick the one that reaches you and
/// dial that one".
class HotlineList extends StatelessWidget {
  final List<Hotline> hotlines;
  final bool filipino;

  const HotlineList({super.key, required this.hotlines, required this.filipino});

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final hotline in hotlines)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(hotline.labelFor(filipino: filipino),
                    style: AppText.display(size: AppTextSize.small, weight: FontWeight.w600)),
                const SizedBox(height: 2),
                for (final n in hotline.numbers) _numberRow(n),
              ],
            ),
          ),
      ],
    );
  }

  Widget _numberRow(HotlineNumber n) {
    return InkWell(
      borderRadius: BorderRadius.circular(AppRadius.sm),
      onTap: () => callHotlineNumber(n.number),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          children: [
            Expanded(
              child: Text(
                n.label == null ? n.number : '${n.label} · ${n.number}',
                style: AppText.display(
                    size: AppTextSize.small,
                    weight: FontWeight.w700,
                    color: AppColors.green700),
              ),
            ),
            const SizedBox(width: 6),
            const Icon(Icons.call_rounded, size: 13, color: AppColors.green700),
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
      appBar: AppBar(title: const Text('Emergency Hotlines')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(AppLayout.gutter, 12, AppLayout.gutter, 22),
        children: [HotlineList(hotlines: hotlines, filipino: false)],
      ),
    );
  }
}
