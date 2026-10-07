import 'package:flutter/material.dart';

import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'shared_widgets.dart' show AppButton, AppButtonStyle;
import 'status_line.dart';

/// The red line under a field that needs fixing: a warning sign, then what
/// to do ("Enter all 11 digits, starting with 09.").
class FieldError extends StatelessWidget {
  final String message;
  const FieldError(this.message, {super.key});

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Padding(
          padding: EdgeInsets.only(top: 1),
          child: Icon(Icons.warning_amber_rounded, size: 16, color: AppColors.red600),
        ),
        const SizedBox(width: 6),
        Expanded(
          child: Text(
            message,
            style: AppText.body(size: AppTextSize.detail, weight: FontWeight.w500, color: AppColors.red600, height: 1.4),
          ),
        ),
      ],
    );
  }
}

/// Above a form that was sent with mistakes: how many fields need attention,
/// and where to look.
///
/// [FormErrorSummary.one] is for a form that stops at the first problem and so
/// cannot know how many there are: it names that field instead of a count.
class FormErrorSummary extends StatelessWidget {
  /// Null for [FormErrorSummary.one].
  final int? count;

  /// The field to check, when the form can name it.
  final String? field;
  final bool filipino;

  const FormErrorSummary({super.key, required int this.count, this.filipino = false}) : field = null;

  const FormErrorSummary.one({super.key, this.field, this.filipino = false}) : count = null;

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final n = count;
    final lead = n == null
        ? (field == null ? tr(f, 'feedback.check_one') : tr(f, 'feedback.check_field').replaceAll('{field}', field!))
        : n == 1
            ? tr(f, 'feedback.summary_one')
            : tr(f, 'feedback.summary_many').replaceAll('{n}', '$n');
    return Semantics(
      liveRegion: true,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color: AppColors.red50,
          borderRadius: BorderRadius.circular(AppRadius.md),
          border: Border.all(color: const Color(0xFFF0C9C3)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Icon(Icons.warning_amber_rounded, size: 20, color: AppColors.red600),
            const SizedBox(width: 10),
            Expanded(
              child: Text.rich(
                TextSpan(children: [
                  TextSpan(text: n == null ? lead : '$lead ', style: const TextStyle(fontWeight: FontWeight.w600)),
                  // "Check the fields marked in red" only follows a count.
                  if (n != null) TextSpan(text: tr(f, 'feedback.summary_hint')),
                ]),
                style: AppText.body(size: AppTextSize.detail, color: const Color(0xFF7E2A21), height: 1.45),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A card in place of something that could not load: a red status line, what
/// to do, and Try again.
class LoadErrorBox extends StatelessWidget {
  final String title;
  final String body;
  final VoidCallback onRetry;
  final bool filipino;

  const LoadErrorBox({
    super.key,
    required this.title,
    required this.body,
    required this.onRetry,
    this.filipino = false,
  });

  @override
  Widget build(BuildContext context) {
    return _Card(
      children: [
        StatusLine(label: title, tone: StatusTone.red),
        Text(body, style: AppText.body(color: AppColors.inkMuted, height: 1.5)),
        AppButton(
          label: tr(filipino, 'feedback.try_again'),
          icon: Icons.refresh_rounded,
          style: AppButtonStyle.outline,
          onPressed: onRetry,
        ),
      ],
    );
  }
}

/// A list with nothing in it yet: what will appear here, and the one thing to
/// do about it.
class EmptyState extends StatelessWidget {
  final String title;
  final String body;
  final String? actionLabel;
  final VoidCallback? onAction;

  const EmptyState({super.key, required this.title, required this.body, this.actionLabel, this.onAction});

  @override
  Widget build(BuildContext context) {
    return _Card(
      gap: AppSpacing.sm,
      children: [
        Semantics(header: true, child: Text(title, style: AppText.display(size: AppTextSize.title))),
        Text(body, style: AppText.body(color: AppColors.inkMuted, height: 1.5)),
        if (actionLabel != null && onAction != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Align(
              alignment: Alignment.centerLeft,
              child: ElevatedButton(
                onPressed: onAction,
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.green700,
                  foregroundColor: Colors.white,
                  minimumSize: const Size(0, 52),
                  padding: const EdgeInsets.symmetric(horizontal: 20),
                  elevation: 0,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md)),
                  textStyle: AppText.display(size: AppTextSize.bodyLg),
                ),
                child: Text(actionLabel!),
              ),
            ),
          ),
      ],
    );
  }
}

/// White card, thin warm border, 14 radius.
class _Card extends StatelessWidget {
  final List<Widget> children;
  final double gap;
  const _Card({required this.children, this.gap = 10});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.line),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (var i = 0; i < children.length; i++) ...[
            if (i > 0) SizedBox(height: gap),
            children[i],
          ],
        ],
      ),
    );
  }
}
