library serbis.screens.auth.verify_email;

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../state/api_service.dart';
import '../../state/account_store.dart';
import '../../state/app_log.dart';
import '../../theme/app_theme.dart';
import '../../widgets/form_inputs.dart';

/// The second half of registration: the code from the email comes back here.
///
/// Reached two ways — straight after registering, and from the login screen
/// when the server refuses an unverified account. Both hand it the address, so
/// the resident never retypes it and the code can only ever be checked against
/// the account it was issued for.
class VerifyEmailScreen extends StatefulWidget {
  final UserStore userStore;
  final String email;
  final void Function(AppUser user) onVerified;
  final VoidCallback onGoToLogin;

  const VerifyEmailScreen({
    super.key,
    required this.userStore,
    required this.email,
    required this.onVerified,
    required this.onGoToLogin,
  });

  @override
  State<VerifyEmailScreen> createState() => _VerifyEmailScreenState();
}

class _VerifyEmailScreenState extends State<VerifyEmailScreen> {
  static const _logArea = 'verify-email';

  final _codeController = TextEditingController();

  bool _submitting = false;
  bool _resending = false;
  String? _error;
  String? _notice;

  /// Mirrors the server's per-account cooldown so the resident sees a counter
  /// instead of tapping resend into a 429.
  int _resendIn = 0;
  Timer? _resendTimer;

  @override
  void initState() {
    super.initState();
    // A code was just sent by whatever brought us here, so the cooldown is
    // already running server-side.
    _startCooldown(60);
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
      setState(() => _error = 'Enter the 6-digit code from your email.');
      return;
    }

    setState(() {
      _submitting = true;
      _error = null;
      _notice = null;
    });

    try {
      final user = await widget.userStore.verifyEmail(
        email: widget.email,
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
      await widget.userStore.resendVerificationCode(email: widget.email);
      if (!mounted) return;
      setState(() => _notice = 'A new code is on its way.');
      _startCooldown(60);
    } on ApiException catch (e) {
      AppLog.error(_logArea, 'resend', status: e.statusCode, reason: e.message);
      if (!mounted) return;
      setState(() => _error = e.message);
      // The server knows the real remaining wait; trust it over the local clock.
      if (e.code == 'resend_too_soon') {
        _startCooldown(60);
      }
    } catch (error) {
      AppLog.error(_logArea, 'resend', reason: error.toString());
      if (!mounted) return;
      setState(() => _error = 'Something went wrong. Please try again.');
    } finally {
      if (mounted) setState(() => _resending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final canResend = _resendIn <= 0 && !_resending && !_submitting;

    return Scaffold(
      backgroundColor: AppColors.paper,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SizedBox(height: 24),
              const Text(
                'Check your email',
                style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 12),
              Text(
                'We sent a 6-digit code to ${widget.email}. '
                'Enter it below to finish creating your account.',
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
