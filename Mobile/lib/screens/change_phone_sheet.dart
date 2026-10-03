library serbis.screens.change_phone_sheet;

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../models/phone_number.dart';
import '../state/account_store.dart';
import '../state/api_service.dart';
import '../state/translations.dart';
import '../state/verification_delivery.dart';
import '../theme/app_theme.dart';
import '../widgets/form_inputs.dart';
import '../widgets/form_steps.dart';
import '../widgets/shared_widgets.dart';

/// Moving the mobile number, which is also the login.
///
/// Two steps, because a token alone must not be enough to move it and because
/// the resident has to hold the number they are moving to:
///
///   1. the new number and the CURRENT PASSWORD — a code is texted to the NEW
///      number;
///   2. that code.
///
/// Nothing on the account changes until step two succeeds, and the sheet pops
/// with the refreshed profile only then. Every string is bilingual; the server's
/// English is used only for messages the resident can act on directly (a number
/// already taken, a wrong password), and `sms_unavailable` is replaced by the
/// app's own wording so it reads in the resident's language.
class ChangePhoneSheet extends StatefulWidget {
  final AppUser user;
  final UserStore userStore;
  final bool filipino;

  const ChangePhoneSheet({
    super.key,
    required this.user,
    required this.userStore,
    required this.filipino,
  });

  @override
  State<ChangePhoneSheet> createState() => _ChangePhoneSheetState();
}

class _ChangePhoneSheetState extends State<ChangePhoneSheet> {
  final _phone = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();

  bool _codeStep = false;
  bool _busy = false;
  bool _passwordHidden = true;

  String? _phoneError;
  String? _passwordError;
  String? _codeError;
  String? _formError;
  String? _notice;

  VerificationDelivery? _delivery;
  int _resendIn = 0;
  Timer? _timer;

  @override
  void dispose() {
    _timer?.cancel();
    _phone.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  String _tr(String key) => tr(widget.filipino, key);

  String get _newPhone => _phone.text.trim();

  void _startCooldown(int seconds) {
    _timer?.cancel();
    setState(() => _resendIn = seconds);

    _timer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      setState(() => _resendIn -= 1);
      if (_resendIn <= 0) timer.cancel();
    });
  }

  /// The wording for a failed call: the app's own line when no text could be
  /// sent (so it reads in the resident's language), the server's otherwise.
  String _messageFor(ApiException e) =>
      e.isSmsUnavailable ? _tr('phonechange.sms_unavailable') : e.message;

  Future<void> _request() async {
    final errors = <String, String>{};

    if (_newPhone.isEmpty) {
      errors['phone'] = _tr('profile.required');
    } else if (!PhoneNumber.isValid(_newPhone)) {
      errors['phone'] = _tr('profile.phone_invalid');
    } else if (PhoneNumber.display(_newPhone) == widget.user.phoneDisplay) {
      errors['phone'] = _tr('phonechange.same');
    }

    // Not trimmed: a space is a legitimate password character.
    if (_password.text.isEmpty) {
      errors['password'] = _tr('profile.password_required');
    }

    setState(() {
      _phoneError = errors['phone'];
      _passwordError = errors['password'];
      _formError = null;
    });
    if (errors.isNotEmpty) return;

    setState(() => _busy = true);

    try {
      final delivery = await widget.userStore.requestPhoneChange(
        phoneNumber: _newPhone,
        currentPassword: _password.text,
      );
      if (!mounted) return;
      setState(() {
        _codeStep = true;
        _delivery = delivery;
        _code.clear();
      });
      _startCooldown(
          delivery?.retryAfter ?? VerificationDelivery.fallbackCooldownSeconds);
    } on ApiException catch (e) {
      if (!mounted) return;
      final phoneError = e.fieldErrors['phone_number'];
      final passwordError = e.fieldErrors['current_password'];
      setState(() {
        _phoneError = phoneError;
        _passwordError = passwordError;
        // Not also in the banner when it is already under its field.
        _formError =
            phoneError == null && passwordError == null ? _messageFor(e) : null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verify() async {
    final code = _code.text.trim();

    if (code.length != 6) {
      setState(() => _codeError = _tr('phonechange.code_length'));
      return;
    }

    setState(() {
      _busy = true;
      _codeError = null;
      _formError = null;
      _notice = null;
    });

    try {
      final updated = await widget.userStore.verifyPhoneChange(code: code);
      if (!mounted) return;
      Navigator.pop(context, updated);
    } on ApiException catch (e) {
      if (!mounted) return;
      // The change itself is gone (too many wrong codes, or the number was taken
      // meanwhile): nothing left to enter a code against, so back to step one.
      if (e.code == 'too_many_attempts' ||
          e.code == 'no_pending_change' ||
          e.code == 'phone_taken') {
        setState(() {
          _codeStep = false;
          _password.clear();
          _formError = e.message;
        });
        return;
      }
      setState(() => _codeError = _messageFor(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resend() async {
    setState(() {
      _busy = true;
      _codeError = null;
      _formError = null;
      _notice = null;
    });

    try {
      final delivery = await widget.userStore.resendPhoneChangeCode();
      if (!mounted) return;
      setState(() {
        _notice = _tr('phonechange.new_code');
        if (delivery != null) _delivery = delivery;
      });
      _startCooldown(
          delivery?.retryAfter ?? VerificationDelivery.fallbackCooldownSeconds);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _codeError = _messageFor(e));
      if (e.code == 'resend_too_soon') {
        _startCooldown(e.retryAfter ?? VerificationDelivery.fallbackCooldownSeconds);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _banner(String text) {
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(top: 14),
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.red50,
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: Text(
        text,
        key: const Key('change-phone-error'),
        style: AppText.body(size: AppTextSize.body, color: AppColors.red600, height: 1.4),
      ),
    );
  }

  Widget _numberStep() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          _tr('profile.phone_locked'),
          style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted, height: 1.5),
        ),
        const SizedBox(height: AppSpacing.md),
        AppTextField.phone(
          label: _tr('phonechange.new_number'),
          controller: _phone,
          errorText: _phoneError,
          enabled: !_busy,
        ),
        AppTextField(
          label: _tr('profile.password_current'),
          hint: '',
          controller: _password,
          obscure: _passwordHidden,
          errorText: _passwordError,
          enabled: !_busy,
          suffixIcon: IconButton(
            onPressed: _busy
                ? null
                : () => setState(() => _passwordHidden = !_passwordHidden),
            icon: Icon(
              _passwordHidden
                  ? Icons.visibility_outlined
                  : Icons.visibility_off_outlined,
              size: 22,
              color: AppColors.inkMuted,
            ),
          ),
        ),
        if (_formError != null) _banner(_formError!),
        const SizedBox(height: 16),
        AppButton(
          label: _tr('phonechange.send'),
          loading: _busy,
          onPressed: _busy ? null : _request,
        ),
      ],
    );
  }

  Widget _codeStepView() {
    final canResend = _resendIn <= 0 && !_busy;
    final ending = _delivery?.sentTo.isNotEmpty == true
        ? _delivery!.sentTo
        : PhoneNumber.lastFour(_newPhone);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '${_tr('phonechange.sent_to')} $ending. ${_tr('phonechange.enter_below')}',
          key: const Key('change-phone-sent-line'),
          style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted, height: 1.5),
        ),
        const SizedBox(height: AppSpacing.md),
        AppTextField(
          label: _tr('phonechange.code'),
          hint: '123456',
          controller: _code,
          keyboard: TextInputType.number,
          maxLength: 6,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          errorText: _codeError,
          enabled: !_busy,
        ),
        if (_notice != null) ...[
          Text(_notice!, style: AppText.body(size: AppTextSize.bodyLg, weight: FontWeight.w600, color: AppColors.green700)),
          const SizedBox(height: 8),
        ],
        if (_delivery?.unknown == true)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              _tr('phonechange.unknown_hint'),
              key: const Key('delivery-unknown-hint'),
              style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted, height: 1.4),
            ),
          ),
        if (_formError != null) _banner(_formError!),
        const SizedBox(height: 12),
        AppButton(
          label: _tr('phonechange.confirm'),
          loading: _busy,
          onPressed: _busy ? null : _verify,
        ),
        Center(
          child: TextButton(
            onPressed: canResend ? _resend : null,
            style: TextButton.styleFrom(minimumSize: const Size(88, 48)),
            child: Text(
              _resendIn > 0
                  ? '${_tr('phonechange.resend_in')} ${_resendIn}s'
                  : _tr('phonechange.resend'),
            ),
          ),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final f = widget.filipino;
    return SheetFrame(
      title: _codeStep ? _tr('phonechange.code_title') : _tr('phonechange.title'),
      scrollable: true,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Two steps, said once and drawn once, so a resident who sees a code
          // field knows the number step is behind them.
          Text(
            trEn(f, 'Step {n} of {total}').replaceAll('{n}', _codeStep ? '2' : '1').replaceAll('{total}', '2'),
            style: AppText.body(size: AppTextSize.small, color: AppColors.inkMuted),
          ),
          const SizedBox(height: AppSpacing.xs),
          FormStepProgress(step: _codeStep ? 1 : 0, total: 2),
          const SizedBox(height: AppSpacing.lg),
          _codeStep ? _codeStepView() : _numberStep(),
          const SizedBox(height: AppSpacing.sm),
          AppButton(
            label: _tr('common.cancel'),
            style: AppButtonStyle.outline,
            onPressed: _busy ? null : () => Navigator.pop(context),
          ),
        ],
      ),
    );
  }
}
