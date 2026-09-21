import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../models/phone_number.dart';
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

  /// Masks what is typed. The reveal toggle is deliberately NOT built in —
  /// this widget stays stateless, so a caller that wants one passes its own
  /// [suffixIcon] and holds the flag. `AuthTextField` owns the state itself
  /// because it is already stateful for its validator.
  final bool obscure;

  /// Rendered inside the field's right edge. Only the password reveal uses it
  /// so far.
  final Widget? suffixIcon;

  /// Shown in a tap/long-press tooltip next to the label, via a small "?"
  /// icon. Null draws no icon at all — most fields need no explanation
  /// beyond their [hint].
  final String? helpText;

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
    this.obscure = false,
    this.suffixIcon,
    this.helpText,
  });

  /// The phone field, spelled once instead of at each of the call sites that
  /// used to repeat the formatter and the length.
  ///
  /// Accepts the three shapes the backend does — `09XXXXXXXXX`,
  /// `639XXXXXXXXX` and `+639XXXXXXXXX` — so `+` is allowed through and the
  /// cap is [PhoneNumber.maxLength], not 11. It was digits-only and capped at
  /// 11, which made the two country-code shapes impossible to type: a resident
  /// who registered as `+639171234567` could see that number in their profile
  /// and not retype it after clearing the field.
  ///
  /// This only bounds what can be entered. Whether it is a real number is the
  /// caller's validator, because not every caller wants the same answer — the
  /// account's own number must match the server's rule exactly, while the
  /// ambulance form's patient contact is free text (`max:32`) server-side and
  /// belongs to whoever is being carried, not to the account.
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
        maxLength: PhoneNumber.maxLength,
        inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9+]'))],
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
          Row(
            children: [
              // Flexible: at this size a long label wraps instead of pushing the
              // help icon off the edge of a 320dp screen.
              Flexible(child: Text(label, style: AppText.fieldLabel())),
              if (helpText != null) ...[
                const SizedBox(width: 4),
                Tooltip(
                  message: helpText,
                  triggerMode: TooltipTriggerMode.tap,
                  child: const Icon(Icons.help_outline_rounded, size: 14, color: AppColors.inkFaint),
                ),
              ],
            ],
          ),
          const SizedBox(height: AppSpacing.xs),
          TextField(
            controller: controller,
            // A masked field cannot be multi-line: Flutter asserts on
            // obscureText with maxLines > 1, and no caller wants both.
            maxLines: obscure ? 1 : lines,
            keyboardType: keyboard,
            maxLength: maxLength,
            inputFormatters: inputFormatters,
            enabled: enabled,
            obscureText: obscure,
            style: AppText.body(size: 14),
            decoration: InputDecoration(
              hintText: hint,
              hintStyle: AppText.body(size: 14, color: AppColors.inkFaint),
              errorText: errorText,
              errorStyle: AppText.body(size: 11, color: AppColors.red600),
              suffixIcon: suffixIcon,
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
          Text(label, style: AppText.fieldLabel()),
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
                style: AppText.body(size: 14, color: AppColors.ink),
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
