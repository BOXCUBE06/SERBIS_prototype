// Covers the failure path of `AppState.cancelRequest` — `request_store.dart`
// :584-585 — which the coverage run of 2026-08-06 found uncovered. The happy
// path already runs through the fake ApiService in `request_timeline_test.dart`;
// what nobody tested is what happens when the DELETE fails.
//
// That is the branch that matters. The row is marked cancelled on screen the
// instant the resident taps, before the server has agreed. If the call fails and
// the row is left showing "Cancelled", the resident believes they withdrew a
// request the MDRRMO still has open — and the card offers no way back, because
// a cancelled request is not cancellable.

import 'package:flutter_test/flutter_test.dart';
import 'package:serbis/models/request_models.dart';
import 'package:serbis/state/api_service.dart';
import 'package:serbis/state/request_store.dart';

class FakeApi extends ApiService {
  FakeApi({this.rows = const <Map<String, dynamic>>[], this.error, this.onCancel});

  final List<Map<String, dynamic>> rows;

  /// Thrown by [cancelRequest] when set. Null means the cancellation succeeds.
  final Object? error;

  /// Runs inside the in-flight call, before it resolves — the seam for
  /// simulating something else touching the list while the DELETE is out.
  final void Function()? onCancel;

  int cancelCalls = 0;

  @override
  Future<List<Map<String, dynamic>>> getRequests() async => rows;

  @override
  Future<List<Map<String, dynamic>>> getServices({String locale = 'en'}) async =>
      <Map<String, dynamic>>[];

  @override
  Future<void> cancelRequest(int id) async {
    cancelCalls++;
    onCancel?.call();
    if (error != null) {
      throw error!;
    }
  }
}

Map<String, dynamic> _row(int id, String status) => <String, dynamic>{
      'request_id': id,
      'service_id': 1,
      'description': 'Flooded street',
      'status': status,
      // What the server's cancelRefusal() answers for a row with no booking
      // and no trip: only Pending and Booked may be withdrawn.
      'can_cancel': status == 'Pending' || status == 'Booked',
      'created_at': '2026-08-01T05:04:00.000000Z',
      'updated_at': '2026-08-01T05:04:00.000000Z',
    };

Future<AppState> loadedState(FakeApi api) async {
  final state = AppState(api);
  await state.loadRequests();
  return state;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('a failed cancellation puts the request back', () async {
    final api = FakeApi(
      rows: <Map<String, dynamic>>[_row(1, 'Pending')],
      error: const ApiException('Cannot connect to server.'),
    );
    final state = await loadedState(api);

    final ok = await state.cancelRequest(1);

    expect(ok, isFalse);
    final request = state.requests.single;
    expect(request.status, ReqStatus.review,
        reason: 'the server still has this request open');
    expect(request.cancellable, isTrue,
        reason: 'left uncancellable, the resident could never retry');
    expect(request.note, isNot('You cancelled this request.'));
  });

  test('the resident is told why', () async {
    // Silently rolling back is its own bug: the row springs back to Pending
    // with no explanation and reads as the tap not registering.
    final api = FakeApi(
      rows: <Map<String, dynamic>>[_row(1, 'Pending')],
      error: const ApiException('Cannot connect to server.'),
    );
    final state = await loadedState(api);

    await state.cancelRequest(1);

    expect(state.lastError, 'Cannot connect to server.');
  });

  test('a non-ApiException still produces a sentence', () async {
    final api = FakeApi(
      rows: <Map<String, dynamic>>[_row(1, 'Pending')],
      error: StateError('bad state'),
    );
    final state = await loadedState(api);

    await state.cancelRequest(1);

    expect(state.lastError, 'Something went wrong. Please try again.');
    expect(state.requests.single.status, ReqStatus.review);
  });

  test('a successful cancellation stays cancelled', () async {
    // The contrast case. Without it, a rollback that fired unconditionally
    // would satisfy every other test in this file.
    final api = FakeApi(rows: <Map<String, dynamic>>[_row(1, 'Pending')]);
    final state = await loadedState(api);

    final ok = await state.cancelRequest(1);

    expect(ok, isTrue);
    expect(state.requests.single.status, ReqStatus.cancelled);
    expect(state.lastError, isNull);
  });

  test('the row is found by id, not by the position it held', () async {
    // Why the rollback re-runs indexWhere instead of reusing the index it
    // captured before the await. A poll or a pull-to-refresh can land while the
    // DELETE is in flight; restoring by the old position would overwrite
    // whichever request had moved into that slot — cancelling the wrong one on
    // screen while the one the resident tapped stays marked cancelled.
    late AppState state;
    final api = FakeApi(
      rows: <Map<String, dynamic>>[_row(7, 'Pending')],
      error: const ApiException('Cannot connect to server.'),
      onCancel: () => state.requests.insert(
        0,
        ServiceRequest.fromJson(_row(9, 'Responding')),
      ),
    );
    state = await loadedState(api);

    await state.cancelRequest(7);

    final moved = state.requests.firstWhere((r) => r.id == 9);
    final restored = state.requests.firstWhere((r) => r.id == 7);
    expect(moved.status, ReqStatus.scheduled,
        reason: 'the row that moved into slot 0 must not be overwritten');
    expect(restored.status, ReqStatus.review);
  });

  test('a request that vanished mid-flight is not resurrected', () async {
    // The `restoreAt != -1` guard. Re-inserting a row the list no longer holds
    // would put a request back on screen that the server has already dropped.
    late AppState state;
    final api = FakeApi(
      rows: <Map<String, dynamic>>[_row(1, 'Pending')],
      error: const ApiException('Cannot connect to server.'),
      onCancel: () => state.requests.clear(),
    );
    state = await loadedState(api);

    final ok = await state.cancelRequest(1);

    expect(ok, isFalse);
    expect(state.requests, isEmpty);
    expect(state.lastError, 'Cannot connect to server.');
  });

  group('calls that never reach the server', () {
    test('an unconfirmed request has nothing to cancel yet', () async {
      final api = FakeApi(rows: <Map<String, dynamic>>[_row(1, 'Pending')]);
      final state = await loadedState(api);

      final ok = await state.cancelRequest(null);

      expect(ok, isFalse);
      expect(api.cancelCalls, 0);
      expect(state.lastError,
          'This request is still being sent. Please wait a moment and try again.');
    });

    test('an already-closed request is left alone', () async {
      // Cancelled, Resolved and Disapproved are all closed. Disapproved is the
      // one worth naming: the MDRRMO refused it, so there is nothing left for
      // the resident to withdraw.
      for (final status in <String>['Cancelled', 'Resolved', 'Disapproved']) {
        final api = FakeApi(rows: <Map<String, dynamic>>[_row(1, status)]);
        final state = await loadedState(api);

        final ok = await state.cancelRequest(1);

        expect(ok, isFalse, reason: '$status must not be cancellable');
        expect(api.cancelCalls, 0, reason: 'no request should leave for $status');
      }
    });

    test('an id the list does not hold is refused', () async {
      final api = FakeApi(rows: <Map<String, dynamic>>[_row(1, 'Pending')]);
      final state = await loadedState(api);

      final ok = await state.cancelRequest(404);

      expect(ok, isFalse);
      expect(api.cancelCalls, 0);
    });
  });
}
