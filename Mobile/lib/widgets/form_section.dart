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
          Text(
            label.toUpperCase(),
            style: AppText.display(
              size: 11,
              weight: FontWeight.w600,
              color: AppColors.inkFaint,
              letterSpacing: 0.6,
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
        borderRadius: BorderRadius.circular(10),
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
          borderRadius: BorderRadius.circular(7),
        ),
        alignment: Alignment.center,
        child: Text(
          label,
          textAlign: TextAlign.center,
          style: AppText.body(
            size: 12.5,
            weight: FontWeight.w600,
            color: selected ? AppColors.surface : AppColors.inkMuted,
          ),
        ),
      ),
    );
  }
}
