import 'package:flutter/material.dart';

import '../state/account_store.dart';
import '../state/translations.dart';
import '../theme/app_theme.dart';
import 'form_inputs.dart';

/// Barangay picker, styled to match the app's fields. Used by sign-up, where
/// it is required, and by Edit my details. A failed fetch gets its own retry
/// rather than a silently empty list.
class BarangayField extends StatelessWidget {
  final List<BarangayOption> barangays;
  final int? value;
  final bool loading;
  final bool failed;
  final VoidCallback onRetry;
  final ValueChanged<int?> onChanged;

  /// Sign-up has no language yet, so it stays on the English default.
  final bool filipino;
  final bool enabled;

  const BarangayField({
    super.key,
    required this.barangays,
    required this.value,
    required this.loading,
    required this.failed,
    required this.onRetry,
    required this.onChanged,
    this.filipino = false,
    this.enabled = true,
  });

  @override
  Widget build(BuildContext context) {
    final f = filipino;
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(tr(f, 'barangay.label'), style: AppText.fieldLabel()),
          const SizedBox(height: AppSpacing.xs),
          if (loading)
            _shell(
              child: Row(
                children: [
                  const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
                  const SizedBox(width: 10),
                  Flexible(
                    child: Text(tr(f, 'barangay.loading'),
                        style: AppText.body(size: AppTextSize.bodyLg, color: AppColors.inkMuted)),
                  ),
                ],
              ),
            )
          else if (failed)
            _shell(
              child: Row(
                children: [
                  const Icon(Icons.wifi_off_rounded, size: 20, color: AppColors.inkMuted),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      tr(f, 'barangay.failed'),
                      style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted),
                    ),
                  ),
                  TextButton(
                    onPressed: onRetry,
                    style: TextButton.styleFrom(minimumSize: const Size(64, 48)),
                    child: Text(tr(f, 'barangay.retry'),
                        style: AppText.display(size: AppTextSize.body, weight: FontWeight.w700, color: AppColors.green700)),
                  ),
                ],
              ),
            )
          else
            // Typable: 64 barangays is too long to scroll. The FormField keeps
            // the inline "Select your barangay" error sign-up relies on.
            FormField<int>(
              validator: (_) => value == null ? tr(f, 'barangay.required') : null,
              builder: (state) => Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  IgnorePointer(
                    ignoring: !enabled,
                    child: AppSearchField(
                      hint: tr(f, 'barangay.search'),
                      icon: Icons.location_on_outlined,
                      value: _nameOf(value),
                      items: [for (final b in barangays) b.name],
                      onChanged: (name) => onChanged(barangays.firstWhere((b) => b.name == name).id),
                    ),
                  ),
                  if (state.hasError)
                    Text(state.errorText!, style: AppText.body(size: AppTextSize.small, color: AppColors.red600)),
                ],
              ),
            ),
        ],
      ),
    );
  }

  String? _nameOf(int? id) {
    for (final b in barangays) {
      if (b.id == id) return b.name;
    }
    return null;
  }

  Widget _shell({required Widget child}) {
    return Container(
      constraints: const BoxConstraints(minHeight: 48),
      padding: const EdgeInsets.symmetric(horizontal: 14),
      decoration: BoxDecoration(
        color: AppColors.fieldFill,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: AppColors.fieldBorder),
      ),
      alignment: Alignment.centerLeft,
      child: child,
    );
  }
}
