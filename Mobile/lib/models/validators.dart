library serbis.models.validators;

import 'package:flutter/services.dart';

/// What each kind of field may hold. Mirrors the server's `App\Rules\Charset` /
/// `FieldRules` (the source of truth) and the admin panel's `inputRules.ts`, so
/// a value this app lets through is one the server accepts.
enum FieldKind { name, phone, age, address, label, note }

class Validators {
  const Validators._();

  // `\p{L}` takes ñ/Ñ and other accented letters.
  static const _chars = {
    FieldKind.name: r"\p{L} .'’-",
    FieldKind.address: r'\p{L}\p{N} ,.\-#/',
    FieldKind.label: r"\p{L}\p{N} .,\-&'’()",
  };

  static const _limits = {
    FieldKind.name: 50,
    FieldKind.phone: 11,
    FieldKind.age: 3,
    FieldKind.address: 150,
    FieldKind.label: 100,
    FieldKind.note: 500,
  };

  static final RegExp _controlOrTag = RegExp(r'[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F<>]', unicode: true);
  static final RegExp _digit = RegExp(r'\d');
  static final RegExp _mobile = RegExp(r'^09\d{9}$');

  static int maxLength(FieldKind kind) => _limits[kind]!;

  static RegExp _only(FieldKind kind) => RegExp('^[${_chars[kind]}]+\$', unicode: true);

  /// Drops what does not belong as it is typed or pasted, then caps the length.
  static List<TextInputFormatter> formatters(FieldKind kind, {int? max}) => [
        switch (kind) {
          FieldKind.phone || FieldKind.age => FilteringTextInputFormatter.digitsOnly,
          FieldKind.note => FilteringTextInputFormatter.deny(_controlOrTag),
          _ => FilteringTextInputFormatter.allow(RegExp('[${_chars[kind]}]', unicode: true)),
        },
        LengthLimitingTextInputFormatter(max ?? maxLength(kind)),
      ];

  static TextInputType keyboard(FieldKind kind) => switch (kind) {
        FieldKind.phone => TextInputType.phone,
        FieldKind.age => TextInputType.number,
        _ => TextInputType.text,
      };

  /// Notes and places start a sentence; names capitalize each word.
  static TextCapitalization capitalization(FieldKind kind) => switch (kind) {
        FieldKind.name => TextCapitalization.words,
        FieldKind.phone || FieldKind.age => TextCapitalization.none,
        _ => TextCapitalization.sentences,
      };

  /// A plain, field-specific message, or null when the value is fine. A blank
  /// value passes unless [required]. Run on blur and again on submit.
  static String? check(FieldKind kind, String? value, {required String label, bool required = false, int? max}) {
    final v = (value ?? '').trim();
    if (v.isEmpty) return required ? '$label is required.' : null;

    switch (kind) {
      case FieldKind.name:
        if (!_only(kind).hasMatch(v)) return '$label can only contain letters, spaces, hyphens, apostrophes and periods.';
        if (v.length < 2) return '$label must be at least 2 letters.';
      case FieldKind.phone:
        if (!_mobile.hasMatch(v)) return '$label must be 11 digits and start with 09.';
        return null;
      case FieldKind.age:
        final n = int.tryParse(v);
        if (!_digit.hasMatch(v) || n == null || n > 120) return '$label must be a whole number from 0 to 120.';
        return null;
      case FieldKind.address:
        if (!_only(kind).hasMatch(v)) return '$label can only contain letters, numbers, spaces and , . - # /';
      case FieldKind.label:
        if (!_only(kind).hasMatch(v)) return "$label can only contain letters, numbers, spaces and . , - & ' ( )";
        if (v.length < 2) return '$label must be at least 2 characters.';
      case FieldKind.note:
        break;
    }

    final limit = max ?? maxLength(kind);
    return v.length <= limit ? null : '$label must be $limit characters or fewer.';
  }
}
