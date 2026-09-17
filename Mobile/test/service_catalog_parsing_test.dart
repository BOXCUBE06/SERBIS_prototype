// Covers `ServiceCatalogItem.fromJson` — `models/request_models.dart:247-271`
// — which the coverage run of 2026-08-06 measured at 0 of 13 lines. The
// catalogue is parsed on every launch and again on every language switch, and
// it is the single source of truth for `service_id`. The map it replaced filed
// an ambulance request as Road Clearing, silently, because every id passed
// the server's `exists` rule.
//
// The two fixtures below are the real `GET /api/services` rows, read off the
// running backend on 2026-08-07 (service 1, `?locale=en` and `?locale=fil`),
// not shapes invented here. Everything else in this file is a degraded version
// of one of them.

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';

/// `GET /api/services?locale=en`, a catalogue row verbatim. The English locale still
/// fills both localized keys — the server resolves them and falls back to the
/// English column, so they are never absent and never blank.
Map<String, dynamic> englishRow() => <String, dynamic>{
      'service_id': 1,
      'code': 'road-clearing',
      'service_name': 'Road Clearing',
      'name_localized': 'Road Clearing',
      'description': 'Clearing roads of debris and obstacles after natural calamities.',
      'description_localized':
          'Clearing roads of debris and obstacles after natural calamities.',
      'created_at': '2026-07-22T04:52:57.000000Z',
      'updated_at': '2026-07-22T04:52:57.000000Z',
    };

/// The same row at `?locale=fil`. Note what does *not* change: `service_name`
/// and `description` stay English, and only the `_localized` pair moves.
Map<String, dynamic> filipinoRow() => <String, dynamic>{
      'service_id': 1,
      'code': 'road-clearing',
      'service_name': 'Road Clearing',
      'name_localized': 'Paglinis ng Daan',
      'description': 'Clearing roads of debris and obstacles after natural calamities.',
      'description_localized': 'Paglilinis ng mga daan mula sa debris at balakid pagkatapos ng kalamidad.',
      'created_at': '2026-07-22T04:52:57.000000Z',
      'updated_at': '2026-07-22T04:52:57.000000Z',
    };

void main() {
  group('the shapes the API actually sends', () {
    test('an English row parses whole', () {
      final item = ServiceCatalogItem.fromJson(englishRow());

      expect(item.id, 1);
      expect(item.code, 'road-clearing');
      expect(item.name, 'Road Clearing');
      expect(item.displayName(false), 'Road Clearing');
      expect(
        item.displayDescription(false),
        'Clearing roads of debris and obstacles after natural calamities.',
      );
    });

    test('Tagalog comes from the app, not from the payload', () {
      // The English row on purpose: it carries no Tagalog anywhere, and the
      // Filipino label still resolves. That is the whole move — the strings
      // are a property of this build, not of whatever the server happens to
      // hold in its translations table.
      final item = ServiceCatalogItem.fromJson(englishRow());

      expect(item.displayName(true), 'Paglinis ng Daan');
      expect(item.displayDescription(true),
          'Paglilinis ng mga daan mula sa debris at balakid pagkatapos ng kalamidad.');
      // The English column is untouched underneath.
      expect(item.name, 'Road Clearing');
    });

    test('a name_localized the server still sends is ignored', () {
      // The API keeps emitting it for now. If this build read it, a row could
      // be labelled from the database again — which is what the move exists to
      // stop.
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 1,
        'code': 'road-clearing',
        'service_name': 'Road Clearing',
        'name_localized': 'SERVER SAYS SOMETHING ELSE',
        'description_localized': 'SERVER BLURB',
      });

      expect(item.displayName(true), 'Paglinis ng Daan');
      expect(item.displayName(false), 'Road Clearing');
      expect(item.displayDescription(true),
          'Paglilinis ng mga daan mula sa debris at balakid pagkatapos ng kalamidad.');
    });

    test('the id is the server row id, not the list position', () {
      // The whole reason this class exists. A wrong id here files the request
      // against another service and the server accepts it.
      final rows = <Map<String, dynamic>>[
        <String, dynamic>{...filipinoRow(), 'service_id': 3},
        <String, dynamic>{...filipinoRow(), 'service_id': 6},
      ];

      final ids = rows.map((r) => ServiceCatalogItem.fromJson(r).id).toList();

      expect(ids, <int>[3, 6]);
    });
  });

  group('the form and icon follow the service code', () {
    test('a translated ambulance row still gets the ambulance form', () {
      // Neither name is consulted any more, which is the point: the code is
      // the same string in every locale, so a Tagalog label cannot drop the
      // resident to the generic form in the language they chose.
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 3,
        'code': 'ambulance-medical-response',
        'service_name': 'Ambulance Service',
        'name_localized': 'Serbisyong Ambulansya',
        'description': 'Emergency medical transport.',
        'description_localized': 'Pang-emerhensiyang transportasyong medikal.',
      });

      expect(item.code, 'ambulance-medical-response');
      expect(item.formKind, ServiceFormKind.ambulance);
      expect(item.icon, isNotNull);
    });

    test('a renamed service keeps the form its code says it has', () {
      // The bug this whole change exists to kill. An admin editing
      // `service_name` in the panel used to move the service to another form
      // — "Road Clearing" to "Street Clearing" lost the road form outright,
      // because the old lookup matched the substring "road".
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 5,
        'code': 'road-clearing',
        'service_name': 'Street Sweeping',
      });

      expect(item.formKind, ServiceFormKind.road);
    });

    test('a name that reads like another service does not borrow its form', () {
      // The mirror of the case above: the name says ambulance, the code says
      // animal rescue, and the code wins.
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 9,
        'code': 'animal-rescue',
        'service_name': 'Animal Ambulance and Medical Transfer',
      });

      expect(item.formKind, ServiceFormKind.generic);
    });

    test('a translated row is not an ambulance', () {
      final item = ServiceCatalogItem.fromJson(filipinoRow());

      expect(item.formKind, isNot(ServiceFormKind.ambulance));
    });

    test('a service nobody hardcoded gets the generic form', () {
      // The MDRRMO can add a service in the admin panel and the app files it
      // without a code change; it just gets the generic form until one of the
      // switches above learns its code.
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 11,
        'code': 'livestock-rescue',
        'service_name': 'Livestock Rescue',
      });

      expect(item.formKind, ServiceFormKind.generic);
    });
  });

  group('older and degraded payloads', () {
    test('falls back to id and name when the prefixed keys are absent', () {
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'id': 5,
        'name': 'Road Clearing',
      });

      expect(item.id, 5);
      expect(item.name, 'Road Clearing');
    });

    test('a payload with no code gets the generic form, not one guessed from the name',
        () {
      // An API too old to send a code. The generic form is the honest answer:
      // guessing "road" out of the name is the behaviour that let a rename
      // change a resident's form, and it is not coming back as a fallback.
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'id': 5,
        'name': 'Road Clearing',
      });

      expect(item.code, '');
      expect(item.formKind, ServiceFormKind.generic);
    });

    test('an id sent as a string is still a number', () {
      // MySQL drivers and hand-written fixtures both do this.
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': '6',
        'service_name': 'Search and Rescue',
      });

      expect(item.id, 6);
    });

    test('an unusable id becomes 0 rather than throwing', () {
      // 0 is the sentinel, and it is the safer failure: no service row has id
      // 0, so the server rejects the request instead of filing it against
      // whatever service happens to sit at another id.
      for (final value in <dynamic>[null, 'abc', <String>[]]) {
        final item = ServiceCatalogItem.fromJson(<String, dynamic>{
          'service_id': value,
          'service_name': 'Road Clearing',
        });

        expect(item.id, 0, reason: 'id from ${value.runtimeType}');
      }
    });

    test('a row with no name at all parses to an empty name', () {
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 2,
      });

      expect(item.name, '');
      expect(item.displayName(false), '');
      expect(item.formKind, ServiceFormKind.generic);
    });

    test('a service this build has never heard of keeps its English name', () {
      // The MDRRMO adds a service in the panel. There is no entry for its code
      // here, so the tile shows what the server called it — in both languages,
      // because inventing Tagalog for it is not this app's job.
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 11,
        'code': 'livestock-rescue',
        'service_name': 'Livestock Rescue',
        'description': 'Rescue for farm animals.',
      });

      expect(item.displayName(false), 'Livestock Rescue');
      expect(item.displayName(true), 'Livestock Rescue');
      expect(item.displayDescription(true), 'Rescue for farm animals.');
    });

    test('a row with no description reads as empty, never null', () {
      // displayDescription is rendered straight into a Text widget.
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 1,
        'code': 'unknown-service',
        'service_name': 'Road Clearing',
      });

      expect(item.displayDescription(false), '');
    });
  });

  group('the client-only "Others" tile', () {
    test('is flagged and localised, unlike a real catalogue row', () {
      const others = ServiceCatalogItem.others();

      expect(others.isOthers, isTrue);
      expect(ServiceCatalogItem.fromJson({
        'service_id': 1,
        'code': 'road-clearing',
        'service_name': 'Road Clearing',
      }).isOthers, isFalse);
      expect(others.formKind, ServiceFormKind.generic);
      expect(others.displayName(false), 'Others');
      expect(others.displayName(true), 'Iba pa');
    });
  });
}
