library serbis.screens.auth.verify_phone;

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../models/phone_number.dart';
import '../../state/api_service.dart';
import '../../state/account_store.dart';
import '../../state/app_log.dart';
import '../../theme/app_theme.dart';
import '../../widgets/auth_layout.dart';
import '../../widgets/form_inputs.dart';
import '../../widgets/shared_widgets.dart' show AppButton, TabHeaderBar;

/// The second half of registration: the code the server sent comes back here.
///
/// Reached two ways — straight after registering, and from the login screen
/// when the server refuses an unverified account. Both send a code on the way
/// here and both hand over the number, so the resident never retypes it and the
/// code can only ever be checked against the account it was issued for.
class VerifyPhoneScreen extends StatefulWidget {
  final UserStore userStore;
  final String phone;

  /// How the send went and how long is left on the resend cooldown, as reported
  /// by whichever call sent it. Null when the server did not say, and the
  /// screen then assumes a full cooldown.
  final VerificationDelivery? delivery;

  final void Function(AppUser user) onVerified;
  final VoidCallback onGoToLogin;

  const VerifyPhoneScreen({
    super.key,
    required this.userStore,
    required this.phone,
    required this.delivery,
    required this.onVerified,
    required this.onGoToLogin,
  });

  @override
  State<VerifyPhoneScreen> createState() => _VerifyPhoneScreenState();
}

class _VerifyPhoneScreenState extends State<VerifyPhoneScreen> {
  static const _logArea = 'verify-phone';

  final _codeController = TextEditingController();

  bool _submitting = false;
  bool _resending = false;
  String? _error;
  String? _notice;

  /// Starts as whatever brought the resident here reported, and is replaced on
  /// every resend: a send that timed out once can go through the next time, so
  /// how it went can change under a screen that is already open.
  VerificationDelivery? _delivery;

  /// Mirrors the server's per-account cooldown so the resident sees a counter
  /// instead of tapping resend into a 429.
  int _resendIn = 0;
  Timer? _resendTimer;

  @override
  void initState() {
    super.initState();
    _delivery = widget.delivery;
    // A code was just sent by whatever brought us here, so the cooldown is
    // already running server-side. Seeding it from the server's own count is
    // what stops the button re-enabling before a send would be accepted.
    _startCooldown(
      _delivery?.retryAfter ?? VerificationDelivery.fallbackCooldownSeconds,
    );
  }

  @override
  void dispose() {
    _resendTimer?.cancel();
    _codeController.dispose();
    super.dispose();
  }

  void _startCooldown(int seconds) {
    _resendTimer?.cancel();
    setState(() => _resendIn = seconds);

    _resendTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      setState(() => _resendIn -= 1);
      if (_resendIn <= 0) {
        timer.cancel();
      }
    });
  }

  Future<void> _submit() async {
    final code = _codeController.text.trim();

    // Checked here as well as on the server so an obviously-wrong length never
    // costs a round trip, and never burns one of the five attempts a minute.
    if (code.length != 6) {
      setState(() => _error = 'Enter the 6-digit code we sent you.');
      return;
    }

    setState(() {
      _submitting = true;
      _error = null;
      _notice = null;
    });

    try {
      final user = await widget.userStore.verifyPhone(
        phoneNumber: widget.phone,
        code: code,
      );
      if (!mounted) return;
      widget.onVerified(user);
    } on ApiException catch (e) {
      AppLog.error(_logArea, 'verify', status: e.statusCode, reason: e.message);
      if (!mounted) return;
      setState(() => _error = e.message);
    } catch (error) {
      AppLog.error(_logArea, 'verify', reason: error.toString());
      if (!mounted) return;
      setState(() => _error = 'Something went wrong. Please try again.');
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _resend() async {
    setState(() {
      _resending = true;
      _error = null;
      _notice = null;
    });

    try {
      final delivery = await widget.userStore
          .resendVerificationCode(phoneNumber: widget.phone);
      if (!mounted) return;
      setState(() {
        _notice = 'A new code is on its way.';
        if (delivery != null) _delivery = delivery;
      });
      _startCooldown(
        delivery?.retryAfter ?? VerificationDelivery.fallbackCooldownSeconds,
      );
    } on ApiException catch (e) {
      AppLog.error(_logArea, 'resend', status: e.statusCode, reason: e.message);
      if (!mounted) return;
      setState(() => _error = e.message);
      // The server knows the real remaining wait; trust it over the local clock.
      if (e.code == 'resend_too_soon') {
        _startCooldown(
          e.retryAfter ?? VerificationDelivery.fallbackCooldownSeconds,
        );
      }
    } catch (error) {
      AppLog.error(_logArea, 'resend', reason: error.toString());
      if (!mounted) return;
      setState(() => _error = 'Something went wrong. Please try again.');
    } finally {
      if (mounted) setState(() => _resending = false);
    }
  }

  /// Names where the code went. Always a text now — there is no email — so the
  /// number's last four digits are all a resident needs to recognise it. Falls
  /// back to the number they typed when the server did not say.
  String get _sentToLine {
    final delivery = _delivery;
    final shown = PhoneNumber.display(widget.phone);

    if (delivery == null) {
      return 'We sent a 6-digit code by text message to $shown.';
    }
    return delivery.sentTo.isEmpty
        ? 'We sent a 6-digit code by text message to $shown.'
        : 'We sent a 6-digit code by text message to the number ending in '
            '${delivery.sentTo}.';
  }

  /// Shown when the server timed out talking to the SMS provider, so the text
  /// may or may not be on its way. The screen is open and Resend is counting
  /// down; this says why a wait is reasonable and what to do after it.
  bool get _deliveryUnknown => _delivery?.unknown ?? false;

  @override
  Widget build(BuildContext context) {
    final canResend = _resendIn <= 0 && !_resending && !_submitting;

    return AuthPage(
      header: const TabHeaderBar(
        title: 'Check your messages',
        subtitle: 'Finish creating your account',
        filipino: false,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            '$_sentToLine Enter it below to finish creating your account.',
            style: AppText.body(size: AppTextSize.bodyLg, height: 1.5),
          ),
          const SizedBox(height: AppSpacing.lg),
          AppTextField(
            label: 'Verification code',
            hint: '123456',
            controller: _codeController,
            keyboard: TextInputType.number,
            maxLength: 6,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            errorText: _error,
            enabled: !_submitting,
          ),
          if (_notice != null) InlineNotice(text: _notice!, tone: NoticeTone.success),
          if (_deliveryUnknown)
            const InlineNotice(
              text: "Didn't get a text? It can take a minute. If it hasn't come "
                  'when the timer ends, tap Send a new code.',
              tone: NoticeTone.warning,
              textKey: Key('delivery-unknown-hint'),
            ),
          const SizedBox(height: AppSpacing.sm),
          AppButton(label: 'Verify', loading: _submitting, onPressed: _submitting ? null : _submit),
          const SizedBox(height: AppSpacing.md),
          Center(
            child: AuthLink(
              label: _resendIn > 0 ? 'Resend code in ${_resendIn}s' : 'Send a new code',
              onPressed: canResend ? _resend : null,
            ),
          ),
          Center(
            child: AuthLink(label: 'Back to log in', onPressed: _submitting ? null : widget.onGoToLogin),
          ),
        ],
      ),
    );
  }
}
