import 'package:flutter/material.dart';

import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'shared_widgets.dart';

/// Back (hidden on the first step) and Next / Submit, pinned under a form.
///
/// [stepNames] are English keys for [trEn]. One name means a single-page form:
/// no Back, and the button submits. The ambulance flow and the relief form both
/// use it, so a resident meets the same bar on every long form.
class FormStepFooter extends StatelessWidget {
  final int step;
  final List<String> stepNames;
  final bool filipino;
  final bool submitting;
  final VoidCallback onBack;
  final VoidCallback onNext;

  /// The last step's button, when "Submit request" is not what it does.
  final String? submitLabel;

  const FormStepFooter({
    super.key,
    required this.step,
    required this.stepNames,
    required this.filipino,
    required this.submitting,
    required this.onBack,
    required this.onNext,
    this.submitLabel,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final last = step == stepNames.length - 1;
    return Container(
      width: double.infinity,
      padding: EdgeInsets.fromLTRB(
        AppLayout.gutter,
        AppSpacing.md,
        AppLayout.gutter,
        AppSpacing.md + MediaQuery.paddingOf(context).bottom,
      ),
      decoration: const BoxDecoration(
        color: AppColors.surface,
        border: Border(top: BorderSide(color: AppColors.line)),
      ),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 600),
          child: Row(
            children: [
              if (step > 0) ...[
                Expanded(child: AppButton(label: trEn(f, 'Back'), style: AppButtonStyle.outline, onPressed: onBack)),
                const SizedBox(width: AppSpacing.sm),
              ],
              Expanded(
                flex: 2,
                child: AppButton(
                  label: last
                      ? (submitLabel ?? tr(f, 'common.submit_request'))
                      : trEn(f, 'Next: {step}').replaceAll('{step}', trEn(f, stepNames[step + 1])),
                  onPressed: onNext,
                  loading: submitting,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// "Step 2 of 3" as a row of segments, the rail the ambulance header draws in
/// white, here in primary on paper for a form under the shared title header.
class FormStepProgress extends StatelessWidget {
  final int step;
  final int total;

  const FormStepProgress({super.key, required this.step, required this.total});

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        for (var i = 0; i < total; i++) ...[
          if (i > 0) const SizedBox(width: AppSpacing.xs),
          Expanded(
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 200),
              height: 4,
              decoration: BoxDecoration(
                color: i <= step ? AppColors.green700 : AppColors.line,
                borderRadius: BorderRadius.circular(AppRadius.pill),
              ),
            ),
          ),
        ],
      ],
    );
  }
}

/// Green header of a stepped form: a round button (close or back), the title,
/// "Step N of M · name", and one white segment per step. The ambulance flow
/// and the relief form both draw it.
///
/// [stepNames] are English keys for [trEn].
class FormStepHeader extends StatelessWidget {
  final String title;
  final List<String> stepNames;
  final int step;
  final bool filipino;

  final IconData leadingIcon;
  final String leadingLabel;
  final VoidCallback onLeading;

  /// The bell, on a form opened from a tab that had one.
  final VoidCallback? onNotifications;

  const FormStepHeader({
    super.key,
    required this.title,
    required this.stepNames,
    required this.step,
    required this.filipino,
    required this.leadingIcon,
    required this.leadingLabel,
    required this.onLeading,
    this.onNotifications,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    final total = stepNames.length;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(AppSpacing.lg, AppLayout.readerHeaderTop, AppSpacing.lg, 18),
      decoration: const BoxDecoration(
        color: AppColors.header,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(AppRadius.header)),
      ),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 600),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Material(
                    color: Colors.white.withValues(alpha: .14),
                    shape: CircleBorder(side: BorderSide(color: Colors.white.withValues(alpha: .3))),
                    child: IconButton(
                      onPressed: onLeading,
                      tooltip: leadingLabel,
                      icon: Icon(leadingIcon, color: Colors.white),
                      constraints: const BoxConstraints.tightFor(width: 44, height: 44),
                    ),
                  ),
                  const SizedBox(width: AppSpacing.md),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          title,
                          style: AppText.display(size: AppTextSize.title, weight: FontWeight.w600, color: Colors.white),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          '${trEn(f, 'Step {n} of {total}').replaceAll('{n}', '${step + 1}').replaceAll('{total}', '$total')}'
                          ' · ${trEn(f, stepNames[step])}',
                          style: AppText.body(size: AppTextSize.small, color: Colors.white.withValues(alpha: .88)),
                        ),
                      ],
                    ),
                  ),
                  if (onNotifications != null)
                    HeaderButton(icon: Icons.notifications_outlined, label: tr(f, 'nav.notifications'), onTap: onNotifications!),
                ],
              ),
              const SizedBox(height: AppSpacing.md),
              Row(
                children: [
                  for (var i = 0; i < total; i++) ...[
                    if (i > 0) const SizedBox(width: AppSpacing.xs),
                    Expanded(
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 200),
                        height: 4,
                        decoration: BoxDecoration(
                          color: Colors.white.withValues(alpha: i <= step ? 1 : .3),
                          borderRadius: BorderRadius.circular(AppRadius.pill),
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A step's own heading in the body: "Who is this for?" over one line.
class FormStepIntro extends StatelessWidget {
  final String title;
  final String body;

  const FormStepIntro({super.key, required this.title, required this.body});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Semantics(header: true, child: Text(title, style: AppText.display(size: AppTextSize.pageTitle))),
          const SizedBox(height: AppSpacing.xs),
          Text(body, style: AppText.body(color: AppColors.inkMuted, height: 1.5)),
        ],
      ),
    );
  }
}

/// The answers before sending, one row per step: what it was, the answer, and
/// Edit back to it.
class ReviewList extends StatelessWidget {
  final List<({String label, String value, VoidCallback onEdit})> rows;
  final bool filipino;

  const ReviewList({super.key, required this.rows, required this.filipino});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.line),
      ),
      child: Column(
        children: [
          for (var i = 0; i < rows.length; i++)
            Container(
              constraints: const BoxConstraints(minHeight: 68),
              padding: const EdgeInsets.fromLTRB(16, 12, 6, 12),
              decoration: i == 0 ? null : const BoxDecoration(border: Border(top: BorderSide(color: AppColors.divider))),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(rows[i].label, style: AppText.detail()),
                        const SizedBox(height: 2),
                        Text(rows[i].value, style: AppText.body(size: AppTextSize.bodyLg, color: AppColors.ink, height: 1.4)),
                      ],
                    ),
                  ),
                  TextButton(
                    onPressed: rows[i].onEdit,
                    style: TextButton.styleFrom(
                      foregroundColor: AppColors.green700,
                      minimumSize: const Size(44, 44),
                      textStyle: AppText.display(size: AppTextSize.body, weight: FontWeight.w600),
                    ),
                    child: Text(trEn(filipino, 'Edit')),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
