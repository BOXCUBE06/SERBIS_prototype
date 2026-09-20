library serbis.state.verification_delivery;

/// Where the six-digit code actually went, and how long before another one can
/// be asked for.
///
/// The channel is not decided by the client and is not fixed per account: the
/// server texts the code when the vendor can dial the number and mails it when
/// it cannot, so the same resident can be sent an SMS one time and an email the
/// next. That makes this something the code screen is *told* after a send, not
/// something it can assume — which is why every endpoint that issues a code
/// returns it.
class VerificationDelivery {
  /// `sms` or `email`.
  final String channel;

  /// The last four digits of the phone number when [bySms], the full email
  /// address otherwise. Enough for a resident to recognise which of their own
  /// contacts to check.
  final String sentTo;

  /// Seconds left on the server's per-account resend cooldown.
  final int retryAfter;

  /// True when the server timed out talking to the SMS provider: the request
  /// left and no answer came back, so the text may or may not arrive. The code
  /// screen still opens, with Resend, and adds a "Didn't get a text?" hint.
  /// Absent from an older server, which reads as a normal send.
  final bool unknown;

  const VerificationDelivery({
    required this.channel,
    required this.sentTo,
    required this.retryAfter,
    this.unknown = false,
  });

  bool get bySms => channel == 'sms';

  /// Used when a response carried no cooldown of its own. It matches
  /// `Resident::RESEND_COOLDOWN_SECONDS` server-side; being wrong here only
  /// mislabels a countdown, because the server refuses an early resend anyway.
  static const fallbackCooldownSeconds = 60;

  /// Reads the `channel` / `sent_to` / `retry_after` trio the API adds to every
  /// response that issues a code. Returns null when they are absent, so an
  /// older server — or a response that sent no code at all — leaves the caller
  /// on its own defaults rather than inventing a channel.
  static VerificationDelivery? fromJson(Map<String, dynamic>? json) {
    final channel = json?['channel'];
    if (channel is! String || channel.isEmpty) return null;

    final sentTo = json?['sent_to'];
    final retryAfter = json?['retry_after'];

    return VerificationDelivery(
      channel: channel,
      sentTo: sentTo is String ? sentTo : '',
      retryAfter: retryAfter is int ? retryAfter : fallbackCooldownSeconds,
      unknown: json?['delivery'] == 'unknown',
    );
  }
}

/// The outcome of a sign-up attempt: either a message to show on the form, or
/// the delivery details for the code screen that comes next.
///
/// Registration reports failure by returning rather than throwing — the form
/// shows [error] inline — so the success half needs somewhere to live too.
class RegisterOutcome {
  final String? error;
  final VerificationDelivery? delivery;

  const RegisterOutcome.failed(String this.error) : delivery = null;
  const RegisterOutcome.sent(this.delivery) : error = null;

  bool get failed => error != null;
}
