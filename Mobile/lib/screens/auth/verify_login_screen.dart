library serbis.screens.auth.verify_login;

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../state/api_service.dart';
import '../../state/account_store.dart';
import '../../state/app_log.dart';
import '../../theme/app_theme.dart';
import '../../widgets/form_inputs.dart';

/// Second half of resident login: the code the server sent on the
/// `mfa_required` refusal comes back here.
///
/// Deliberately a near-copy of [VerifyEmailScreen] rather than sharing a
/// widget with it — the two screens call different endpoints against a
/// different server-side code (this one never touches the signup
/// `verification_code` columns), and two small screens are simpler to read
/// than one parameterised over which flow it's in.
class VerifyLoginScreen extends StatefulWidget {
  final UserStore userStore;
  final String email;
  final String challengeId;

  /// Which channel carried the code and how long is left on the resend
  /// cooldown, as reported by the login call that issued it.
  final VerificationDelivery? delivery;

  final void Function(AppUser user) onVerified;
  final VoidCallback onGoToLogin;

  const VerifyLoginScreen({
    super.key,
    required this.userStore,
    required this.email,
    required this.challengeId,
    required this.delivery,
    required this.onVerified,
    required this.onGoToLogin,
  });

  @override
  State<VerifyLoginScreen> createState() => _VerifyLoginScreenState();
}

class _VerifyLoginScreenState extends State<VerifyLoginScreen> {
  static const _logArea = 'verify-login';

  final _codeController = TextEditingController();

  bool _submitting = false;
  bool _resending = false;
  String? _error;
  String? _notice;

  /// Starts as whatever the login call reported, and is replaced on every
  /// resend: a number the vendor rejects falls back to mail mid-screen.
  VerificationDelivery? _delivery;

  /// Mirrors the server's per-challenge cooldown so the resident sees a
  /// counter instead of tapping resend into a 429.
  int _resendIn = 0;
  Timer? _resendTimer;

  @override
  void initState() {
    super.initState();
    _delivery = widget.delivery;
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
      final user = await widget.userStore.verifyLoginCode(
        challengeId: widget.challengeId,
        code: code,
      );
      if (!mounted) return;
      widget.onVerified(user);
    } on ApiException catch (e) {
      AppLog.error(_logArea, 'verify', status: e.statusCode, reason: e.message);
      if (!mounted) return;
      // The challenge is gone either way (expired, or killed by too many wrong
      // codes) — there is nothing left here to submit a code against.
      if (e.code == 'mfa_challenge_expired' || e.code == 'too_many_attempts') {
        widget.onGoToLogin();
        return;
      }
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
      final delivery = await widget.userStore.resendLoginCode(
        challengeId: widget.challengeId,
      );
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
      if (e.code == 'mfa_challenge_expired') {
        widget.onGoToLogin();
        return;
      }
      setState(() => _error = e.message);
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

  String get _sentToLine {
    final delivery = _delivery;

    if (delivery == null) {
      return 'We sent a 6-digit code to ${widget.email}.';
    }
    if (delivery.bySms) {
      return delivery.sentTo.isEmpty
          ? 'We sent a 6-digit code by text message to your phone.'
          : 'We sent a 6-digit code by text message to the number ending in '
              '${delivery.sentTo}.';
    }
    return 'We sent a 6-digit code to '
        '${delivery.sentTo.isEmpty ? widget.email : delivery.sentTo}.';
  }

  @override
  Widget build(BuildContext context) {
    final canResend = _resendIn <= 0 && !_resending && !_submitting;
    final bySms = _delivery?.bySms ?? false;

    return Scaffold(
      backgroundColor: AppColors.paper,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SizedBox(height: 24),
              Text(
                bySms ? 'Check your messages' : 'Check your email',
                style:
                    const TextStyle(fontSize: 24, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 12),
              Text(
                '$_sentToLine Enter it below to finish signing in.',
                style: const TextStyle(fontSize: 15, height: 1.5),
              ),
              const SizedBox(height: 24),
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
              if (_notice != null) ...[
                const SizedBox(height: 8),
                Text(
                  _notice!,
                  style: const TextStyle(color: AppColors.green700),
                ),
              ],
              const SizedBox(height: 24),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: _submitting ? null : _submit,
                  child: _submitting
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Text('Verify'),
                ),
              ),
              const SizedBox(height: 16),
              Center(
                child: TextButton(
                  onPressed: canResend ? _resend : null,
                  child: Text(
                    _resendIn > 0
                        ? 'Resend code in ${_resendIn}s'
                        : 'Send a new code',
                  ),
                ),
              ),
              Center(
                child: TextButton(
                  onPressed: _submitting ? null : widget.onGoToLogin,
                  child: const Text('Back to log in'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
