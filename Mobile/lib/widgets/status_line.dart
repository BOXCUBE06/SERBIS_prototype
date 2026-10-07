import 'package:flutter/material.dart';

import '../models/request_models.dart' show formatTimelineTime;
import '../state/translations.dart';
import '../theme/app_theme.dart';

/// The four colours a status can be. Amber: waiting on MDRRMO. Green: moving
/// or done. Grey: closed by the resident. Red: refused or failed.
enum StatusTone { amber, green, grey, red }

/// A status as a coloured dot and words: "● Under review".
///
/// [large] is the one main status in a card: 20/600 with a 12px dot, plus an
/// "Updated <time>" line when [updatedAt] is given. Every main status says
/// when it last changed.
class StatusLine extends StatelessWidget {
  final String label;
  final StatusTone tone;
  final bool large;
  final DateTime? updatedAt;
  final bool filipino;

  /// White words on the green header; the dot keeps its colour.
  final bool onDark;

  const StatusLine({
    super.key,
    required this.label,
    required this.tone,
    this.large = false,
    this.updatedAt,
    this.filipino = false,
    this.onDark = false,
  });

  /// (dot, text) per tone, on white.
  static (Color, Color) colorsOf(StatusTone tone) => switch (tone) {
        StatusTone.amber => (AppColors.amberDot, AppColors.amberInk),
        StatusTone.green => (AppColors.green600, AppColors.green700),
        StatusTone.grey => (AppColors.greyDot, AppColors.inkMuted),
        StatusTone.red => (AppColors.redDot, AppColors.red600),
      };

  /// "Updated today, 9:14 AM" / "Updated Aug 1, 9:14 AM".
  static String updatedText(DateTime at, bool filipino) {
    final when = formatTimelineTime(at, filipino);
    final today = tr(filipino, 'timeline.today');
    final phrase = when.startsWith(today) ? today.toLowerCase() + when.substring(today.length) : when;
    return '${tr(filipino, 'status.updated')} $phrase';
  }

  @override
  Widget build(BuildContext context) {
    final (dot, toneInk) = colorsOf(tone);
    final ink = onDark ? Colors.white : toneInk;
    final dotSize = large ? 12.0 : 8.0;
    final line = Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(width: dotSize, height: dotSize, decoration: BoxDecoration(color: dot, shape: BoxShape.circle)),
        SizedBox(width: large ? 10 : 8),
        Flexible(
          child: Text(
            label,
            style: large
                ? AppText.display(size: AppTextSize.pageTitle, color: ink, height: 1.25)
                : AppText.body(size: AppTextSize.detail, weight: FontWeight.w500, color: ink, height: 1.3),
          ),
        ),
      ],
    );
    final at = updatedAt;
    if (at == null) return line;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        line,
        const SizedBox(height: AppSpacing.xs),
        Text(
          updatedText(at, filipino),
          style: onDark ? AppText.detail(color: Colors.white.withValues(alpha: .85)) : AppText.detail(),
        ),
      ],
    );
  }
}
