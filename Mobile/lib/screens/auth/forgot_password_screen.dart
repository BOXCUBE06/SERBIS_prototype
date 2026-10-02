library serbis.screens.auth.forgot_password;

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../models/phone_number.dart';
import '../../state/account_store.dart';
import '../../state/api_service.dart';
import '../../state/app_log.dart';
import '../../theme/app_theme.dart';
import '../../widgets/form_inputs.dart';
import '../../widgets/shared_widgets.dart';

/// Getting a forgotten password back, by text: the number, then the code sent to
/// it, then a new password.
///
/// There is no email and no link. The screen never says whether the number has
/// an account — the server answers the same either way, so the second step is
/// always "if this number has an account, a code is on its way", and a wrong
/// code, an expired one and an unknown number all read the same.
///
/// Finishing does not sign the resident in: the server ends every session on a
/// reset, and they log in with the new password (and the usual code) like
/// anyone else.
class ForgotPasswordScreen extends StatefulWidget {
  final UserStore userStore;

  /// The number the resident had typed on the login screen, so it need not be
  /// typed twice.
  final String? initialPhone;

  final VoidCallback onGoToLogin;

  /// Called with a sentence for the login screen to show once the password has
  /// been changed.
  final void Function(String message) onReset;

  const ForgotPasswordScreen({
    super.key,
    required this.userStore,
    required this.onGoToLogin,
    required this.onReset,
    this.initialPhone,
  });

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

enum _Step { phone, code, password }

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  static const _logArea = 'forgot-password';

  static final RegExp _lower = RegExp(r'[a-z]');
  static final RegExp _upper = RegExp(r'[A-Z]');
  static final RegExp _digit = RegExp(r'[0-9]');

  final _phoneCtrl = TextEditingController();
  final _codeCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  final _confirmCtrl = TextEditingController();
  final _phoneKey = GlobalKey<FormState>();
  final _passKey = GlobalKey<FormState>();

  _Step _step = _Step.phone;
  bool _busy = false;
  String? _error;
  String? _notice;

  /// Held in memory only for the length of this screen, between step two and
  /// step three. Never stored.
  String? _resetToken;

  int _resendIn = 0;
  Timer? _resendTimer;

  @override
  void initState() {
    super.initState();
    _phoneCtrl.text = widget.initialPhone ?? '';
  }

  @override
  void dispose() {
    _resendTimer?.cancel();
    _phoneCtrl.dispose();
    _codeCtrl.dispose();
    _passCtrl.dispose();
    _confirmCtrl.dispose();
    super.dispose();
  }

  String get _phone => _phoneCtrl.text.trim();

  void _startCooldown(int seconds) {
    _resendTimer?.cancel();
    setState(() => _resendIn = seconds);

    _resendTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      setState(() => _resendIn -= 1);
      if (_resendIn <= 0) timer.cancel();
    });
  }

  /// Runs one step's network call with the shared busy/error handling, so each
  /// step below is only what differs.
  Future<void> _run(String what, Future<void> Function() action) async {
    setState(() {
      _busy = true;
      _error = null;
      _notice = null;
    });

    try {
      await action();
    } on ApiException catch (e) {
      AppLog.error(_logArea, what, status: e.statusCode, reason: e.message);
      if (!mounted) return;
      setState(() => _error = e.message);
    } catch (error) {
      AppLog.error(_logArea, what, reason: error.toString());
      if (!mounted) return;
      setState(() => _error = 'Something went wrong. Please try again.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _sendCode() async {
    if (!_phoneKey.currentState!.validate()) return;

    await _run('forgot', () async {
      final cooldown = await widget.userStore.forgotPassword(phoneNumber: _phone);
      if (!mounted) return;
      setState(() {
        _step = _Step.code;
        _codeCtrl.clear();
      });
      _startCooldown(cooldown);
    });
  }

  Future<void> _resend() async {
    await _run('resend', () async {
      final cooldown = await widget.userStore.forgotPassword(phoneNumber: _phone);
      if (!mounted) return;
      setState(() => _notice = 'If this number has an account, a new code is on its way.');
      _startCooldown(cooldown);
    });
  }

  Future<void> _verifyCode() async {
    final code = _codeCtrl.text.trim();

    if (code.length != 6) {
      setState(() => _error = 'Enter the 6-digit code we sent you.');
      return;
    }

    await _run('verify', () async {
      final token = await widget.userStore
          .verifyPasswordReset(phoneNumber: _phone, code: code);
      if (!mounted) return;
      setState(() {
        _resetToken = token;
        _step = _Step.password;
      });
    });
  }

  Future<void> _setPassword() async {
    if (!_passKey.currentState!.validate()) return;

    await _run('reset', () async {
      try {
        await widget.userStore.resetPassword(
          phoneNumber: _phone,
          resetToken: _resetToken ?? '',
          password: _passCtrl.text,
        );
      } on ApiException catch (e) {
        // The token lapsed (ten minutes) or was spent: nothing left to submit
        // a password against, so start over rather than retry forever.
        if (e.code == 'reset_expired' && mounted) {
          setState(() {
            _step = _Step.phone;
            _resetToken = null;
            _passCtrl.clear();
            _confirmCtrl.clear();
          });
        }
        rethrow;
      }

      if (!mounted) return;
      widget.onReset('Your password has been changed. Log in with your new password.');
    });
  }

  String? _passwordRule(String? v) {
    final val = v ?? '';
    if (val.isEmpty) return 'Enter a new password';
    if (val.length < 8) return 'At least 8 characters';
    if (!_lower.hasMatch(val) || !_upper.hasMatch(val) || !_digit.hasMatch(val)) {
      return 'Use upper and lower case letters and a number';
    }
    return null;
  }

  Widget _errorLine() {
    if (_error == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.error_outline_rounded, size: 16, color: AppColors.red600),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              _error!,
              key: const Key('forgot-error'),
              style: AppText.body(size: AppTextSize.small, color: AppColors.red600, height: 1.4),
            ),
          ),
        ],
      ),
    );
  }

  Widget _phoneStep() {
    return Form(
      key: _phoneKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Forgot your password?', style: AppText.display(size: AppTextSize.headline)),
          const SizedBox(height: 8),
          Text(
            "Enter the mobile number you signed up with. We'll text you a code "
            'to set a new password.',
            style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted, height: 1.5),
          ),
          const SizedBox(height: 24),
          AuthTextField(
            label: 'Mobile number',
            hint: '09XXXXXXXXX',
            controller: _phoneCtrl,
            keyboard: TextInputType.phone,
            prefixIcon: Icons.phone_outlined,
            maxLength: PhoneNumber.maxLength,
            inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9+]'))],
            validator: (v) {
              final val = (v ?? '').trim();
              if (val.isEmpty) return 'Enter your mobile number';
              if (!PhoneNumber.isValid(val)) return 'Enter a valid mobile number';
              return null;
            },
          ),
          _errorLine(),
          AppButton(label: 'Send code', loading: _busy, onPressed: _busy ? null : _sendCode),
        ],
      ),
    );
  }

  Widget _codeStep() {
    final canResend = _resendIn <= 0 && !_busy;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Check your messages', style: AppText.display(size: AppTextSize.headline)),
        const SizedBox(height: 8),
        Text(
          'If ${PhoneNumber.display(_phone)} has an account, we sent a 6-digit '
          "code by text message. Enter it below. It's good for 10 minutes.",
          key: const Key('forgot-sent-line'),
          style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted, height: 1.5),
        ),
        const SizedBox(height: 24),
        AppTextField(
          label: 'Verification code',
          hint: '123456',
          controller: _codeCtrl,
          keyboard: TextInputType.number,
          maxLength: 6,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          errorText: _error,
          enabled: !_busy,
        ),
        if (_notice != null) ...[
          const SizedBox(height: 8),
          Text(_notice!, style: AppText.body(size: AppTextSize.bodyLg, color: AppColors.green700)),
        ],
        const SizedBox(height: 8),
        Text(
          "Didn't get a text? Check the number, wait for the timer, then send "
          'a new code.',
          style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted, height: 1.4),
        ),
        const SizedBox(height: 20),
        AppButton(label: 'Verify', loading: _busy, onPressed: _busy ? null : _verifyCode),
        const SizedBox(height: 8),
        Center(
          child: TextButton(
            onPressed: canResend ? _resend : null,
            child: Text(_resendIn > 0 ? 'Resend code in ${_resendIn}s' : 'Send a new code'),
          ),
        ),
        Center(
          child: TextButton(
            onPressed: _busy
                ? null
                : () => setState(() {
                      _step = _Step.phone;
                      _error = null;
                      _notice = null;
                    }),
            child: const Text('Use a different number'),
          ),
        ),
      ],
    );
  }

  Widget _passwordStep() {
    return Form(
      key: _passKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Choose a new password', style: AppText.display(size: AppTextSize.headline)),
          const SizedBox(height: 8),
          Text(
            'At least 8 characters, with upper and lower case letters and a number.',
            style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted, height: 1.5),
          ),
          const SizedBox(height: 24),
          AuthTextField(
            label: 'New password',
            hint: '',
            controller: _passCtrl,
            obscure: true,
            prefixIcon: Icons.lock_outline_rounded,
            validator: _passwordRule,
          ),
          AuthTextField(
            label: 'Confirm new password',
            hint: '',
            controller: _confirmCtrl,
            obscure: true,
            prefixIcon: Icons.lock_outline_rounded,
            validator: (v) =>
                (v ?? '') == _passCtrl.text ? null : 'The two passwords do not match',
          ),
          _errorLine(),
          AppButton(
              label: 'Change password',
              loading: _busy,
              onPressed: _busy ? null : _setPassword),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.paper,
      body: SafeArea(
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            const AppHeader(),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 28, 24, 24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  switch (_step) {
                    _Step.phone => _phoneStep(),
                    _Step.code => _codeStep(),
                    _Step.password => _passwordStep(),
                  },
                  const SizedBox(height: 18),
                  Center(
                    child: TextButton(
                      onPressed: _busy ? null : widget.onGoToLogin,
                      child: const Text('Back to log in'),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
