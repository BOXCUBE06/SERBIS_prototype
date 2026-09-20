library serbis.models.phone_number;

/// What this app considers a dialable Philippine mobile number.
///
/// Mirrors `PhoneNumber::REGEX` (`app/Support/PhoneNumber.php`) character for
/// character, and has to. The server applies that pattern to registration, to
/// the admin's own resident edits and to `PATCH /me`, and `PhoneNumber::normalize()`
/// drops anything that does not match before a send — so a number this app
/// accepts and the server does not is a number that either 422s at submit or
/// silently never receives an OTP.
///
/// Three rules used to disagree about this, all looser than the server's and
/// none the same as another:
///
///   register_screen.dart  `^[0-9+][0-9 \-]{6,19}$`  — accepted `1234567` and
///                         `+1 555-0100`; the form passed, the submit 422'd.
///   profile_screen.dart   length 11 and starts `09` — a strict subset, so it
///                         rejected the `+639…` shape the server accepts and
///                         registration allows.
///   the server            the pattern below.
///
/// One definition now, referenced by both screens. If the backend's regex ever
/// changes, this is the single line that has to follow it.
class PhoneNumber {
  const PhoneNumber._();

  /// The three shapes the vendor can dial: `09XXXXXXXXX`, `639XXXXXXXXX` and
  /// `+639XXXXXXXXX`.
  static final RegExp pattern = RegExp(r'^(09\d{9}|639\d{9}|\+639\d{9})$');

  /// Longest accepted shape — `+63` plus ten digits. Used as the input cap so
  /// a field cannot hold more than the longest thing that could ever pass.
  static const int maxLength = 13;

  /// Trims first, because a phone keyboard adds a trailing space after
  /// autocomplete and the caller is about to trim for the request body anyway.
  /// Nothing else is stripped: the server's rule rejects spaces and hyphens
  /// inside the number, so accepting them here would put the disagreement
  /// straight back.
  static bool isValid(String? value) => pattern.hasMatch((value ?? '').trim());

  /// How a number reads to a person here: `09171234567`.
  ///
  /// The server stores and returns E.164 (`+639171234567`) — it is the login and
  /// the form the SMS vendor takes — but a resident writes and dials the
  /// national form, so every place this app shows or edits a number goes through
  /// this. `639…` is handled too, since that is a shape the server accepts on
  /// the way in. Anything else is returned as it came, trimmed: an unfamiliar
  /// value is better shown than blanked.
  static String display(String? value) {
    final v = (value ?? '').trim();
    final e164 = RegExp(r'^\+?63(9\d{9})$').firstMatch(v);
    return e164 != null ? '0${e164.group(1)}' : v;
  }

  /// The last four digits, for "the number ending in 4567". Empty when there
  /// are fewer than four digits to show.
  static String lastFour(String? value) {
    final digits = (value ?? '').replaceAll(RegExp(r'\D'), '');
    return digits.length < 4 ? '' : digits.substring(digits.length - 4);
  }
}
