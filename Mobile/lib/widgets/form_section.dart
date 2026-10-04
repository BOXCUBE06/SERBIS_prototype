import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// Groups fields that answer the same question ("who", "where", "when") under
/// one small label, so a form reads as sections instead of one flat list of
/// identically-spaced fields. Deliberately not a bordered card — a label plus
/// [AppSpacing.xl] of separation from the next section is enough to read as a
/// group; a drawn box around every group would be a card wrapping a card
/// wrapping a field.
class FormSection extends StatelessWidget {
  final String label;
  final List<Widget> children;

  const FormSection({super.key, required this.label, required this.children});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.xl),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Semantics(
            header: true,
            child: Text(
              label,
              style: AppText.display(size: AppTextSize.title, weight: FontWeight.w600, color: AppColors.sectionInk),
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          ...children,
        ],
      ),
    );
  }
}

/// Two-way "now or later" switch for the ambulance form, replacing a box that
/// changed shape and colour depending on which mode was active — clear once
/// you were in it, but nothing on screen showed the other option existed
/// until you dug for it. Both choices are visible together at all times.
class ModeToggle extends StatelessWidget {
  final String leftLabel;
  final String rightLabel;
  final bool rightSelected;
  final ValueChanged<bool> onChanged;

  const ModeToggle({
    super.key,
    required this.leftLabel,
    required this.rightLabel,
    required this.rightSelected,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.sm),
        border: Border.all(color: AppColors.line, width: 1.5),
      ),
      child: Row(
        children: [
          Expanded(child: _segment(leftLabel, selected: !rightSelected, onTap: () => onChanged(false))),
          Expanded(child: _segment(rightLabel, selected: rightSelected, onTap: () => onChanged(true))),
        ],
      ),
    );
  }

  Widget _segment(String label, {required bool selected, required VoidCallback onTap}) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(vertical: 10),
        decoration: BoxDecoration(
          color: selected ? AppColors.green700 : Colors.transparent,
          borderRadius: BorderRadius.circular(AppRadius.sm - 3),
        ),
        alignment: Alignment.center,
        child: Text(
          label,
          textAlign: TextAlign.center,
          style: AppText.body(
            size: AppTextSize.small,
            weight: FontWeight.w600,
            color: selected ? AppColors.surface : AppColors.inkMuted,
          ),
        ),
      ),
    );
  }
}

/// A numbered form card: "1  Patient" over its fields.
class NumberedCard extends StatelessWidget {
  final int number;
  final String title;

  /// Drawn after the title, e.g. a required mark.
  final Widget? trailing;
  final List<Widget> children;

  const NumberedCard({
    super.key,
    required this.number,
    required this.title,
    required this.children,
    this.trailing,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: AppSpacing.lg),
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.xl),
        border: Border.all(color: AppColors.line),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 24,
                height: 24,
                alignment: Alignment.center,
                decoration: const BoxDecoration(color: AppColors.green50, shape: BoxShape.circle),
                child: Text(
                  '$number',
                  style: AppText.display(size: AppTextSize.small, weight: FontWeight.w700, color: AppColors.green700),
                ),
              ),
              const SizedBox(width: 10),
              Flexible(
                child: Text(
                  title,
                  style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600, color: AppColors.green900),
                ),
              ),
              if (trailing != null) trailing!,
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          ...children,
        ],
      ),
    );
  }
}

/// Two-option segmented control: grey track, white selected segment.
class SegmentedChoice extends StatelessWidget {
  final String leftLabel;
  final String rightLabel;

  /// True when the right-hand option is selected.
  final bool rightSelected;
  final ValueChanged<bool> onChanged;

  const SegmentedChoice({
    super.key,
    required this.leftLabel,
    required this.rightLabel,
    required this.rightSelected,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.xs),
      decoration: BoxDecoration(color: AppColors.grey50, borderRadius: BorderRadius.circular(AppRadius.md)),
      child: Row(
        children: [
          Expanded(child: _option(leftLabel, !rightSelected, () => onChanged(false))),
          const SizedBox(width: AppSpacing.xs),
          Expanded(child: _option(rightLabel, rightSelected, () => onChanged(true))),
        ],
      ),
    );
  }

  Widget _option(String label, bool selected, VoidCallback onTap) {
    return Semantics(
      selected: selected,
      button: true,
      child: GestureDetector(
        onTap: onTap,
        behavior: HitTestBehavior.opaque,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 150),
          constraints: const BoxConstraints(minHeight: 48),
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: selected ? AppColors.surface : Colors.transparent,
            borderRadius: BorderRadius.circular(AppRadius.md - AppSpacing.xs),
            boxShadow: selected
                ? [BoxShadow(color: Colors.black.withValues(alpha: .08), blurRadius: 3, offset: const Offset(0, 1))]
                : null,
          ),
          child: Text(
            label,
            style: AppText.body(
              size: AppTextSize.bodyLg,
              weight: selected ? FontWeight.w600 : FontWeight.w400,
              color: selected ? AppColors.green700 : AppColors.inkMuted,
            ),
          ),
        ),
      ),
    );
  }
}

/// A label (and optional hint) with a switch on the right; the whole row taps.
class SwitchRow extends StatelessWidget {
  final String label;
  final String? hint;
  final bool value;
  final ValueChanged<bool> onChanged;

  const SwitchRow({super.key, required this.label, required this.value, required this.onChanged, this.hint});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: () => onChanged(!value),
      borderRadius: BorderRadius.circular(AppRadius.sm),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 44),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(label, style: AppText.fieldLabel()),
                  if (hint != null)
                    Text(hint!, style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted)),
                ],
              ),
            ),
            Switch(
              value: value,
              onChanged: onChanged,
              activeThumbColor: AppColors.surface,
              activeTrackColor: AppColors.green700,
            ),
          ],
        ),
      ),
    );
  }
}

/// One choice drawn as a card: icon, title, hint. Selected = 2px primary border.
class OptionCard extends StatelessWidget {
  final IconData icon;
  final String title;
  final String hint;
  final bool selected;
  final VoidCallback? onTap;

  const OptionCard({
    super.key,
    required this.icon,
    required this.title,
    required this.hint,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Semantics(
      selected: selected,
      button: true,
      child: Material(
        color: selected ? AppColors.greenSelected : AppColors.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.lg),
          side: BorderSide(
            color: selected ? AppColors.green700 : AppColors.fieldBorder,
            width: selected ? 2 : 1,
          ),
        ),
        child: InkWell(
          onTap: onTap,
          canRequestFocus: false,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(icon, size: 20, color: selected ? AppColors.green700 : AppColors.inkMuted),
                const SizedBox(height: 6),
                Text(title, style: AppText.display(size: AppTextSize.bodyLg, weight: FontWeight.w600)),
                const SizedBox(height: 2),
                Text(hint, style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
