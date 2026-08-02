library serbis.state.app_log;

import 'dart:collection';
import 'dart:developer' as developer;

import 'api_exception.dart';

/// Severity of a log line. Only [error] is expected to matter in a bug report;
/// the other two exist to give an error line context about what led to it.
enum LogLevel { info, warn, error }

extension LogLevelX on LogLevel {
  /// `dart:developer` uses the same numeric scale as `package:logging`.
  int get value {
    switch (this) {
      case LogLevel.info:
        return 800;
      case LogLevel.warn:
        return 900;
      case LogLevel.error:
        return 1000;
    }
  }

  String get label {
    switch (this) {
      case LogLevel.info:
        return 'INFO';
      case LogLevel.warn:
        return 'WARN';
      case LogLevel.error:
        return 'ERROR';
    }
  }
}

/// One recorded line. Kept as a value rather than a formatted string so the
/// buffer can be asserted on field-by-field in tests.
class LogEntry {
  final DateTime at;
  final LogLevel level;

  /// Where in the app this happened — 'api', 'requests', 'cache', 'materials'.
  /// Deliberately coarse: this is for pointing at a subsystem, not a line.
  final String area;

  /// What was being attempted, in the imperative: 'GET /service-requests',
  /// 'save material', 'read token'.
  final String event;

  /// Why it failed, already reduced to something safe to write down.
  final String? reason;

  const LogEntry({
    required this.at,
    required this.level,
    required this.area,
    required this.event,
    this.reason,
  });

  String format() {
    final head = '${at.toIso8601String()} ${level.label} [$area] $event';
    return reason == null || reason!.isEmpty ? head : '$head — $reason';
  }
}

/// The app's only logger.
///
/// Two jobs, and the second is the one that matters in the field. It writes to
/// `dart:developer`'s `log()` so a connected debugger sees the line, and it
/// keeps the last [maxEntries] lines in memory so a resident can hand them to
/// MDRRMO from a device nobody can attach a debugger to. Before this existed
/// there was no artefact of any kind: every failure was either swallowed by a
/// bare `catch (_) {}` or turned into one sentence on screen and dropped.
///
/// `debugPrint` is not used. It throttles output and silently drops lines when
/// a burst exceeds its budget, which is exactly when the log is worth having.
///
/// ## What must never be written here
///
/// The auth token, any password, request bodies, and valid-ID bytes. This is a
/// system holding government ID scans, and the buffer is copied to a clipboard
/// and pasted into whatever the resident uses to reach MDRRMO — assume every
/// line ends up somewhere unencrypted.
///
/// That rule is enforced by [describeError] rather than left to each call site:
/// an arbitrary exception is recorded as its **type only**. The reason is
/// concrete — `jsonDecode` throws a `FormatException` whose `toString()` embeds
/// the source it choked on, so logging it verbatim would write a slice of the
/// server's response body, and a captive-portal interstitial is not the worst
/// thing that could be in there. [ApiException] is the one exception that is
/// logged in full: its message was written to be shown to the resident and is
/// already on their screen, so it discloses nothing new.
class AppLog {
  /// Enough to cover the failure and what led to it, small enough to paste into
  /// a message. At ~80 chars a line this is roughly 6 KB.
  static const int maxEntries = 80;

  static final ListQueue<LogEntry> _entries = ListQueue<LogEntry>();

  /// Newest last. Unmodifiable — the buffer is only appended to through [_add].
  static List<LogEntry> get entries => List.unmodifiable(_entries);

  static bool get isEmpty => _entries.isEmpty;

  static void info(String area, String event, {String? reason}) =>
      _add(LogLevel.info, area, event, reason);

  static void warn(String area, String event, {String? reason}) =>
      _add(LogLevel.warn, area, event, reason);

  /// [error] is reduced by [describeError]; [status] is appended when the
  /// caller knows the HTTP status and the exception does not carry it.
  static void error(
    String area,
    String event, {
    Object? error,
    int? status,
    String? reason,
  }) {
    final parts = <String>[
      if (reason != null && reason.isNotEmpty) reason,
      if (error != null) describeError(error),
      if (status != null) 'status $status',
    ];

    _add(LogLevel.error, area, event, parts.join(' · '));
  }

  static void _add(LogLevel level, String area, String event, String? reason) {
    final entry = LogEntry(
      at: DateTime.now(),
      level: level,
      area: area,
      event: event,
      reason: reason,
    );

    _entries.addLast(entry);
    while (_entries.length > maxEntries) {
      _entries.removeFirst();
    }

    developer.log(
      entry.reason == null ? entry.event : '${entry.event} — ${entry.reason}',
      name: 'serbis.$area',
      level: level.value,
      time: entry.at,
    );
  }

  /// Reduces [error] to something safe to write down. See the class comment for
  /// why anything that is not an [ApiException] loses its message.
  ///
  /// Not private: the rule is the security-relevant part of this file and is
  /// asserted directly in `test/app_log_test.dart`.
  static String describeError(Object error) {
    if (error is ApiException) {
      final status = error.statusCode;
      return status == null ? '$error' : '$error (status $status)';
    }

    return error.runtimeType.toString();
  }

  /// The buffer as text, oldest first, for the clipboard.
  ///
  /// The header names the app and the time so a pasted report is legible
  /// without the resident having to explain what it is.
  static String export() {
    final buffer = StringBuffer()
      ..writeln('SERBIS problem report')
      ..writeln('Generated ${DateTime.now().toIso8601String()}')
      ..writeln('${_entries.length} of the last $maxEntries events')
      ..writeln('---');

    if (_entries.isEmpty) {
      buffer.writeln('(no events recorded this session)');
    } else {
      for (final entry in _entries) {
        buffer.writeln(entry.format());
      }
    }

    return buffer.toString();
  }

  /// Dropped on logout along with the request cache: the lines name what the
  /// previous resident did, and the next one to use this phone must not get
  /// them.
  static void clear() => _entries.clear();
}
