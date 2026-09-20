import 'package:flutter/material.dart';

import '../models/service_forms.dart';
import '../theme/app_theme.dart';

/// A calendar day for the MDRRMO programs, at least [ServiceFormField.minDaysAhead]
/// days from today. Not a text box: the office requires a real date, and the
/// server checks the same lead time, so the picker simply cannot offer a day
/// that would be refused.
class ProgramDateField extends StatelessWidget {
  final ServiceFormField field;
  final DateTime? value;
  final ValueChanged<DateTime> onPicked;

  const ProgramDateField({
    super.key,
    required this.field,
    required this.value,
    required this.onPicked,
  });

  Future<void> _pick(BuildContext context) async {
    final today = DateTime.now();
    final earliest = DateTime(today.year, today.month, today.day)
        .add(Duration(days: field.minDaysAhead));

    final picked = await showDatePicker(
      context: context,
      initialDate: value != null && !value!.isBefore(earliest) ? value! : earliest,
      firstDate: earliest,
      lastDate: earliest.add(const Duration(days: 365)),
    );

    if (picked != null) onPicked(picked);
  }

  @override
  Widget build(BuildContext context) {
    final picked = value;

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(field.label, style: AppText.display(size: 12, weight: FontWeight.w600)),
          const SizedBox(height: AppSpacing.xs),
          Semantics(
            button: true,
            label: '${field.label}, ${picked == null ? 'not chosen' : formatProgramDate(picked)}',
            child: InkWell(
              borderRadius: BorderRadius.circular(10),
              onTap: () => _pick(context),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 14),
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: AppColors.line, width: 1.5),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.calendar_month_rounded, size: 18, color: AppColors.inkFaint),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        picked == null ? 'Choose a date' : formatProgramDate(picked),
                        style: AppText.body(
                          size: 13,
                          color: picked == null ? AppColors.inkFaint : AppColors.ink,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          const SizedBox(height: AppSpacing.xs),
          Text(
            'At least ${field.minDaysAhead} days from today, so MDRRMO can plan.',
            style: AppText.body(size: 11, color: AppColors.inkMuted),
          ),
        ],
      ),
    );
  }
}
