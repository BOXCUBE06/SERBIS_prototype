import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/state/api_exception.dart';
import 'package:serbis/state/app_log.dart';

/// The redaction rules are the security-relevant half of `AppLog`, so they are
/// asserted directly rather than inferred from call sites. If one of these
/// fails, something that must never leave the device is about to be pasted into
/// a message to MDRRMO.
void main() {
  setUp(AppLog.clear);

  group('buffer', () {
    test('keeps newest entries and drops the oldest past the cap', () {
      for (var i = 0; i < AppLog.maxEntries + 25; i++) {
        AppLog.info('test', 'event $i');
      }

      final entries = AppLog.entries;
      expect(entries, hasLength(AppLog.maxEntries));
      // Oldest survivor is entry 25; entries 0-24 fell off the front.
      expect(entries.first.event, 'event 25');
      expect(entries.last.event, 'event ${AppLog.maxEntries + 24}');
    });

    test('clear empties it, so logout leaves nothing for the next resident', () {
      AppLog.error('requests', 'submit request', reason: 'rolled back');
      expect(AppLog.isEmpty, isFalse);

      AppLog.clear();

      expect(AppLog.isEmpty, isTrue);
      expect(AppLog.export(), contains('no events recorded'));
    });

    test('entries cannot be mutated through the getter', () {
      AppLog.info('test', 'event');
      expect(
        () => AppLog.entries.add(
          LogEntry(at: DateTime.now(), level: LogLevel.info, area: 'x', event: 'y'),
        ),
        throwsUnsupportedError,
      );
    });
  });

  group('redaction', () {
    test('a FormatException does not carry the text it failed to parse', () {
      // The real path: jsonDecode over a response body. Before AppLog reduced
      // errors to their type, this line would have written a slice of whatever
      // the server sent — an error page, a captive-portal interstitial, or a
      // JSON body with the resident's own details in it.
      const body = '{"resident":"Maria Santos","token":"9|SECRETVALUE"}truncated';

      Object? thrown;
      try {
        jsonDecode(body);
      } catch (error) {
        thrown = error;
      }

      expect(thrown, isA<FormatException>());
      // Guard the premise: the exception really does embed the source, so this
      // test would catch a regression rather than passing vacuously.
      expect('$thrown', contains('SECRETVALUE'));

      final described = AppLog.describeError(thrown!);
      expect(described, 'FormatException');
      expect(described, isNot(contains('SECRETVALUE')));
      expect(described, isNot(contains('Maria Santos')));
    });

    test('an arbitrary exception is reduced to its type', () {
      final described = AppLog.describeError(
        Exception('valid_id bytes: 89504e470d0a1a0a'),
      );

      expect(described, isNot(contains('89504e470d0a1a0a')));
      expect(described, startsWith('_Exception'));
    });

    test('a logged error writes only the reduced form to the buffer', () {
      Object? thrown;
      try {
        jsonDecode('{"password":"Hunter2!"');
      } catch (error) {
        thrown = error;
      }

      AppLog.error('api', 'POST /resident/login', error: thrown);

      expect(AppLog.export(), isNot(contains('Hunter2!')));
      expect(AppLog.export(), contains('FormatException'));
    });

    test('ApiException is kept in full — it is already on the resident\'s screen',
        () {
      // The exception to the rule, and the reason it is safe: this message was
      // written to be shown, and the resident has already read it.
      const error = ApiException(
        'No available vehicles at this time.',
        statusCode: 422,
      );

      expect(
        AppLog.describeError(error),
        'No available vehicles at this time. (status 422)',
      );
    });

    test('a network ApiException has no status to append', () {
      const error = ApiException('Cannot connect to server.');
      expect(AppLog.describeError(error), 'Cannot connect to server.');
    });
  });

  group('export', () {
    test('reads oldest first, so the sequence of failures is legible', () {
      AppLog.info('requests', 'first');
      AppLog.warn('api', 'second');
      AppLog.error('cache', 'third');

      final text = AppLog.export();
      expect(
        text.indexOf('first'),
        lessThan(text.indexOf('second')),
      );
      expect(
        text.indexOf('second'),
        lessThan(text.indexOf('third')),
      );
    });

    test('labels each line with its level and area', () {
      AppLog.error('api', 'GET /service-requests', status: 500, reason: 'boom');

      final line = AppLog.entries.single.format();
      expect(line, contains('ERROR'));
      expect(line, contains('[api]'));
      expect(line, contains('GET /service-requests'));
      expect(line, contains('boom'));
      expect(line, contains('status 500'));
    });

    test('a header names the app and how much of the buffer is included', () {
      AppLog.info('test', 'event');
      final text = AppLog.export();

      expect(text, contains('SERBIS problem report'));
      expect(text, contains('1 of the last ${AppLog.maxEntries} events'));
    });
  });
}
