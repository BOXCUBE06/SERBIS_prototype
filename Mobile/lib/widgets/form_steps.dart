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
