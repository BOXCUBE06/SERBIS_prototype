// `Disapproved` used to be folded into `ReqStatus.cancelled`, so a request the
// MDRRMO refused was reported to the resident as one they had cancelled
// themselves — the wrong actor, and it made the remarks explaining the refusal
// look like a note attached to the resident's own withdrawal.
//
// These tests pin the two apart: the parse, the labels in both languages, the
// timeline's closing step, and the fact that neither the cancel action nor the
// "active request" banner treats a refusal as something still in progress.

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/theme/app_theme.dart';

ServiceRequest _requestWithStatus(String status) =>
    ServiceRequest.fromJson(<String, dynamic>{
      'request_id': 7,
      'service_id': 3,
      'description': 'Tree down on the access road',
      'status': status,
      'remarks': 'Barangay crew already cleared this section.',
      'created_at': '2026-08-01T02:00:00.000000Z',
      'updated_at': '2026-08-01T04:00:00.000000Z',
      'service': <String, dynamic>{'service_name': 'Road Clearing'},
    });

void main() {
  group('getStatusFromText', () {
    test('maps disapproved to its own status, not cancelled', () {
      expect(getStatusFromText('disapproved'), ReqStatus.disapproved);
    });

    test('still maps cancelled to cancelled', () {
      expect(getStatusFromText('cancelled'), ReqStatus.cancelled);
    });

    test('the two are distinct values', () {
      expect(ReqStatus.disapproved, isNot(ReqStatus.cancelled));
    });
  });

  group('ServiceRequest.fromJson', () {
    test("reads the backend's Disapproved through the lowercasing", () {
      final request = _requestWithStatus('Disapproved');

      expect(request.status, ReqStatus.disapproved);
    });

    test('a refused request is not cancellable', () {
      // `cancellable` gates the button in the track card. Offering "cancel" on
      // a request the agency already refused is an action with nothing to do.
      expect(_requestWithStatus('Disapproved').cancellable, isFalse);
    });

    test('the refusal keeps the remarks that explain it', () {
      expect(
        _requestWithStatus('Disapproved').note,
        'Barangay crew already cleared this section.',
      );
    });
  });

  group('labels', () {
    test('English says the request was not approved, not cancelled', () {
      expect(ReqStatus.disapproved.label, 'Not approved');
      expect(ReqStatus.disapproved.labelFor(false), 'Not approved');
    });

    test('Filipino says hindi inaprubahan, not kinansela', () {
      expect(ReqStatus.disapproved.labelFor(true), 'Hindi inaprubahan');
      expect(ReqStatus.cancelled.labelFor(true), 'Kinansela');
    });

    test('every status has a label in both languages', () {
      // A missing key would surface as the raw key string on screen.
      for (final status in ReqStatus.values) {
        expect(status.labelFor(false), isNot(contains('status.')));
        expect(status.labelFor(true), isNot(contains('status.')));
      }
    });

    test('refusal reads as a refusal, not as the grey of a withdrawal', () {
      expect(ReqStatus.disapproved.fg, AppColors.red600);
      expect(ReqStatus.disapproved.bg, AppColors.red50);
      expect(ReqStatus.cancelled.fg, AppColors.inkFaint);
    });
  });

  group('timeline', () {
    test('closes with the MDRRMO refusing, timestamped', () {
      final steps = _requestWithStatus('Disapproved').timelineFor(false);

      expect(steps, hasLength(2));
      expect(steps.last.title, 'Not approved by MDRRMO');
      expect(steps.last.state, RequestStepState.done);
      expect(steps.last.time, isNot('Time not recorded'));
    });

    test('the Filipino timeline names the agency too', () {
      final steps = _requestWithStatus('Disapproved').timelineFor(true);

      expect(steps.last.title, 'Hindi inaprubahan ng MDRRMO');
    });

    test('a cancelled request still closes with its own step', () {
      final steps = _requestWithStatus('Cancelled').timelineFor(false);

      expect(steps.last.title, 'Cancelled');
    });

    test('every status produces a timeline', () {
      // The switch in timelineFor is exhaustive; this fails loudly if a future
      // status is added to the enum and not to the switch.
      for (final status in ReqStatus.values) {
        expect(_requestWithStatus(status.name).timelineFor(false), isNotEmpty);
      }
    });
  });

  group('cache round trip', () {
    test('a refused request survives as refused, not as cancelled', () {
      final cached = _requestWithStatus('Disapproved').toCacheJson();
      final restored = ServiceRequestCache.fromCacheJson(cached);

      expect(cached['status'], 'disapproved');
      expect(restored, isNotNull);
      expect(restored!.status, ReqStatus.disapproved);
    });
  });
}
