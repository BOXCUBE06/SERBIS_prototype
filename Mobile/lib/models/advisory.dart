import 'package:flutter/foundation.dart';

/// One SMS blast the MDRRMO sent to this resident, served by
/// `GET /api/advisories`.
///
/// The feed is scoped to blasts the resident was a **recipient** of, not to
/// their barangay: someone who was Inactive when a warning went out did not
/// receive it, and showing it to them now would misrepresent what the agency
/// sent. Failed blasts are excluded server-side for the same reason — a send
/// that never left must never read as a warning that arrived.
@immutable
class Advisory {
  final int id;

  /// The text as it was texted. Not localized: the MDRRMO writes each blast in
  /// whatever language it chose, and translating it here would put words the
  /// agency did not send into an emergency message.
  final String message;

  /// The barangay the blast was addressed to, when the server sent one.
  final String? barangay;

  /// When the blast was recorded — the row's `created_at`. Null when the server
  /// sent none, and every surface showing a date must say so rather than print
  /// a stand-in.
  final DateTime? sentAt;

  const Advisory({
    required this.id,
    required this.message,
    this.barangay,
    this.sentAt,
  });

  factory Advisory.fromJson(Map<String, dynamic> json) {
    final barangay = json['barangay'];

    return Advisory(
      id: _intOf(json['sms_log_id'] ?? json['id']),
      message: (json['message_body'] as String?)?.trim() ?? '',
      // Serialized as a nested relation, so a row loaded without it must not
      // crash the feed — the message is what matters, the barangay is context.
      barangay: barangay is Map<String, dynamic>
          ? (barangay['barangay_name'] as String?)?.trim()
          : null,
      // Laravel serialises UTC. Without toLocal() a warning sent this morning
      // reads as yesterday evening in the Philippines.
      sentAt: _dateOf(json['created_at']),
    );
  }

  /// False for a row with nothing to show. A blast whose body did not survive
  /// serialization is not an advisory — an empty card in this list would read
  /// as an alert nobody can act on.
  bool get isReadable => message.isNotEmpty;

  static DateTime? _dateOf(Object? value) {
    if (value is! String || value.isEmpty) return null;
    return DateTime.tryParse(value)?.toLocal();
  }

  static int _intOf(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse('$value') ?? 0;
  }
}
