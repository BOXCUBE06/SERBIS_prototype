// Covers `ServiceCatalogItem.fromJson` — `models/request_models.dart:247-271`
// — which the coverage run of 2026-08-06 measured at 0 of 13 lines. The
// catalogue is parsed on every launch and again on every language switch, and
// it is the single source of truth for `service_id`. The map it replaced filed
// an ambulance request as Flood Evacuation, silently, because every id passed
// the server's `exists` rule.
//
// The two fixtures below are the real `GET /api/services` rows, read off the
// running backend on 2026-08-07 (service 1, `?locale=en` and `?locale=fil`),
// not shapes invented here. Everything else in this file is a degraded version
// of one of them.

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';

/// `GET /api/services?locale=en`, service 1, verbatim. The English locale still
/// fills both localized keys — the server resolves them and falls back to the
/// English column, so they are never absent and never blank.
Map<String, dynamic> englishRow() => <String, dynamic>{
      'service_id': 1,
      'service_name': 'Flood Evacuation',
      'name_localized': 'Flood Evacuation',
      'description': 'Assistance and evacuation services during floods.',
      'description_localized':
          'Assistance and evacuation services during floods.',
      'created_at': '2026-07-22T04:52:57.000000Z',
      'updated_at': '2026-07-22T04:52:57.000000Z',
    };

/// The same row at `?locale=fil`. Note what does *not* change: `service_name`
/// and `description` stay English, and only the `_localized` pair moves.
Map<String, dynamic> filipinoRow() => <String, dynamic>{
      'service_id': 1,
      'service_name': 'Flood Evacuation',
      'name_localized': 'Paglikas sa Baha',
      'description': 'Assistance and evacuation services during floods.',
      'description_localized': 'Tulong at paglikas tuwing may baha.',
      'created_at': '2026-07-22T04:52:57.000000Z',
      'updated_at': '2026-07-22T04:52:57.000000Z',
    };

void main() {
  group('the shapes the API actually sends', () {
    test('an English row parses whole', () {
      final item = ServiceCatalogItem.fromJson(englishRow());

      expect(item.id, 1);
      expect(item.name, 'Flood Evacuation');
      expect(item.nameLocalized, 'Flood Evacuation');
      expect(
        item.displayDescription,
        'Assistance and evacuation services during floods.',
      );
    });

    test('a Filipino row shows Tagalog and keeps English underneath', () {
      final item = ServiceCatalogItem.fromJson(filipinoRow());

      expect(item.nameLocalized, 'Paglikas sa Baha');
      expect(item.displayDescription, 'Tulong at paglikas tuwing may baha.');
      // The English pair is what the form and icon are keyed on, so it has to
      // survive the translation.
      expect(item.name, 'Flood Evacuation');
      expect(
        item.description,
        'Assistance and evacuation services during floods.',
      );
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

  group('the form and icon follow the English name', () {
    test('a translated ambulance row still gets the ambulance form', () {
      // The regression this guards: keyed on `nameLocalized`, a Tagalog label
      // matches none of the English substrings and every translated service
      // silently drops to the generic description form. The resident would
      // lose the guided fields in exactly the language they chose.
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 3,
        'service_name': 'Ambulance Service',
        'name_localized': 'Serbisyong Ambulansya',
        'description': 'Emergency medical transport.',
        'description_localized': 'Pang-emerhensiyang transportasyong medikal.',
      });

      expect(item.formKind, ServiceFormKind.ambulance);
      expect(item.icon, isNotNull);
    });

    test('a translated flood row is not an ambulance', () {
      final item = ServiceCatalogItem.fromJson(filipinoRow());

      expect(item.formKind, isNot(ServiceFormKind.ambulance));
    });

    test('a service nobody hardcoded gets the generic form', () {
      // The point of deriving this from the name: the MDRRMO can add a service
      // in the admin panel and the app files it without a code change.
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 11,
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
      expect(item.formKind, ServiceFormKind.road);
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
          'service_name': 'Flood Evacuation',
        });

        expect(item.id, 0, reason: 'id from ${value.runtimeType}');
      }
    });

    test('a row with no name at all parses to an empty name', () {
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 2,
      });

      expect(item.name, '');
      expect(item.nameLocalized, '');
      expect(item.formKind, ServiceFormKind.generic);
    });

    test('missing localized fields fall back to the English ones', () {
      // What a build talking to an older API sees. The name must never come
      // back blank — it is the label on the tile the resident taps.
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 1,
        'service_name': 'Flood Evacuation',
        'description': 'Assistance and evacuation services during floods.',
      });

      expect(item.nameLocalized, 'Flood Evacuation');
      expect(item.descriptionLocalized, isNull);
      expect(
        item.displayDescription,
        'Assistance and evacuation services during floods.',
      );
    });

    test('an empty localized name is treated as absent, not as a blank tile',
        () {
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 1,
        'service_name': 'Flood Evacuation',
        'name_localized': '',
      });

      expect(item.nameLocalized, 'Flood Evacuation');
    });

    test('an empty localized description falls back independently', () {
      // The two fall back separately on purpose: a locale can have a
      // translated name and no translated blurb.
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 1,
        'service_name': 'Flood Evacuation',
        'name_localized': 'Paglikas sa Baha',
        'description': 'Assistance and evacuation services during floods.',
        'description_localized': '',
      });

      expect(item.nameLocalized, 'Paglikas sa Baha');
      expect(item.descriptionLocalized, isNull);
      expect(
        item.displayDescription,
        'Assistance and evacuation services during floods.',
      );
    });

    test('a row with no description reads as empty, never null', () {
      // displayDescription is rendered straight into a Text widget.
      final item = ServiceCatalogItem.fromJson(<String, dynamic>{
        'service_id': 1,
        'service_name': 'Flood Evacuation',
      });

      expect(item.displayDescription, '');
    });
  });
}
