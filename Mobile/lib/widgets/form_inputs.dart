import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../theme/app_theme.dart';

/// The app's labelled text field. Lived as `_Field` inside the services screen,
/// where nothing else could reach it.
class AppTextField extends StatelessWidget {
  final String label;
  final String hint;
  final int lines;
  final TextInputType keyboard;
  final TextEditingController controller;
  final int? maxLength;
  final List<TextInputFormatter>? inputFormatters;

  /// Message shown under the field, and the cue that turns its border red.
  /// Null when the field is fine — the profile edit sheet is the first caller
  /// that can reject what was typed.
  final String? errorText;

  /// Greys the field out while a save is in flight, so a second tap cannot
  /// edit a value that is already being sent.
  final bool enabled;

  const AppTextField({
    super.key,
    required this.label,
    required this.hint,
    required this.controller,
    this.lines = 1,
    this.keyboard = TextInputType.text,
    this.maxLength,
    this.inputFormatters,
    this.errorText,
    this.enabled = true,
  });

  /// The 11-digit numbers-only phone field, spelled once instead of at each of
  /// the four call sites that used to repeat the formatter and the length.
  factory AppTextField.phone({
    Key? key,
    String label = 'Contact number',
    required TextEditingController controller,
    String? errorText,
    bool enabled = true,
  }) =>
      AppTextField(
        key: key,
        label: label,
        hint: '09XXXXXXXXX',
        controller: controller,
        keyboard: TextInputType.phone,
        maxLength: 11,
        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
        errorText: errorText,
        enabled: enabled,
      );

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: AppText.display(size: 12, weight: FontWeight.w600)),
          const SizedBox(height: AppSpacing.xs),
          TextField(
            controller: controller,
            maxLines: lines,
            keyboardType: keyboard,
            maxLength: maxLength,
            inputFormatters: inputFormatters,
            enabled: enabled,
            style: AppText.body(size: 13),
            decoration: InputDecoration(
              hintText: hint,
              hintStyle: AppText.body(size: 13, color: AppColors.inkFaint),
              errorText: errorText,
              errorStyle: AppText.body(size: 11, color: AppColors.red600),
              counterText: '',
              filled: true,
              fillColor: AppColors.surface,
              contentPadding: const EdgeInsets.symmetric(horizontal: 13, vertical: 12),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppColors.line, width: 1.5),
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppColors.line, width: 1.5),
              ),
              disabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppColors.line, width: 1.5),
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppColors.green600, width: 1.5),
              ),
              errorBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppColors.red600, width: 1.5),
              ),
              focusedErrorBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppColors.red600, width: 1.5),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// The app's labelled dropdown. Was `_Dropdown` in the services screen.
/// Generic over the value so a dropdown can carry the object it selects rather
/// than its label. The service picker needs that: two services could be given
/// the same name in the admin panel, and a `List<String>` would make them the
/// same option. Existing `List<String>` call sites infer `T = String` and are
/// unchanged.
class AppDropdown<T> extends StatelessWidget {
  final String label;
  final List<T> items;
  final T value;
  final ValueChanged<T> onChanged;

  /// How to print an item. Defaults to `toString()`, which is what the
  /// `List<String>` callers were already relying on.
  final String Function(T)? itemLabel;

  const AppDropdown({
    super.key,
    required this.label,
    required this.items,
    required this.value,
    required this.onChanged,
    this.itemLabel,
  });

  String _label(T item) => itemLabel?.call(item) ?? '$item';

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: AppText.display(size: 12, weight: FontWeight.w600)),
          const SizedBox(height: AppSpacing.xs),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 13),
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: AppColors.line, width: 1.5),
            ),
            child: DropdownButtonHideUnderline(
              child: DropdownButton<T>(
                value: value,
                isExpanded: true,
                icon: const Icon(Icons.expand_more_rounded, color: AppColors.inkFaint),
                style: AppText.body(size: 13, color: AppColors.ink),
                items: items
                    .map((i) => DropdownMenuItem<T>(value: i, child: Text(_label(i))))
                    .toList(),
                onChanged: (v) {
                  if (v != null) onChanged(v);
                },
              ),
            ),
          ),
        ],
      ),
    );
  }
}
