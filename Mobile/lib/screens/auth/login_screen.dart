
library serbis.screens.auth.login;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../models/phone_number.dart';
import '../../state/api_service.dart';
import '../../state/account_store.dart';
import '../../state/app_log.dart';
import '../../theme/app_theme.dart';
import '../../widgets/auth_layout.dart';
import '../../widgets/shared_widgets.dart';


class LoginScreen extends StatefulWidget {
  final UserStore userStore;
  final void Function(AppUser user) onLoginSuccess;
  final VoidCallback onGoToRegister;

  /// The credentials were right but the number was never verified. Carries it so
  /// the verify screen can resume a registration that was left half-finished.
  final void Function(String phone, VerificationDelivery? delivery)
      onPhoneUnverified;

  /// The password was right; a text-message code is what's left. `challengeId`
  /// goes straight to `/resident/login/verify` — the login screen never
  /// inspects it.
  final void Function(
    String phone,
    String challengeId,
    VerificationDelivery? delivery,
  ) onMfaRequired;

  /// "Forgot password?" — opens the reset flow, carrying whatever number was
  /// typed so it need not be typed twice. There is no email to send a link to; it
  /// is a code texted to the number.
  final void Function(String phone) onForgotPassword;
  final String? infoMessage;

  /// Opens the emergency hotlines list; null hides the link.
  final VoidCallback? onOpenHotlines;

  const LoginScreen({
    super.key,
    required this.userStore,
    required this.onLoginSuccess,
    required this.onGoToRegister,
    required this.onPhoneUnverified,
    required this.onMfaRequired,
    required this.onForgotPassword,
    this.infoMessage,
    this.onOpenHotlines,
  });

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey    = GlobalKey<FormState>();
  final _phoneCtrl  = TextEditingController();
  final _passCtrl   = TextEditingController();
  bool    _loading   = false;
  String? _formError;

  /// Set when the server refuses the account itself rather than the
  /// credentials. Kept apart from [_formError] because the two need opposite
  /// affordances: a wrong password is worth retrying, a closed account is not.
  /// While this holds, "Log in" is disabled.
  String? _blockedMessage;

  @override
  void initState() {
    super.initState();
    // Clears the block when the number changes — a different account may be
    // perfectly fine, and without this the screen is a dead end needing an app
    // restart. Editing the password alone does not clear it: the password was
    // never the problem.
    _phoneCtrl.addListener(_clearBlockOnPhoneChange);
  }

  void _clearBlockOnPhoneChange() {
    if (_blockedMessage != null) setState(() => _blockedMessage = null);
  }

  @override
  void dispose() {
    _phoneCtrl.removeListener(_clearBlockOnPhoneChange);
    _phoneCtrl.dispose();
    _passCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _formError = null);
    if (!_formKey.currentState!.validate()) return;

    setState(() => _loading = true);

    try {
      final user = await widget.userStore.login(
        phoneNumber: _phoneCtrl.text.trim(),
        password:    _passCtrl.text,
      );
      if (!mounted) return;
      widget.onLoginSuccess(user);
    } on ApiException catch (e) {
      // An abandoned registration: the password was right, the number was
      // never verified. Sending them to the code screen is the only useful
      // answer — an error on this form leaves them with nothing to do.
      //
      // The refusal sent a fresh code on its way out, so the delivery details
      // ride along: without them the code screen would have to start its
      // cooldown from zero.
      if (e.isPhoneUnverified) {
        widget.onPhoneUnverified(_phoneCtrl.text.trim(), e.delivery);
        return;
      }
      // Password proven; a code is on its way. challengeId is always present
      // when the server sends mfa_required — the server never omits it.
      if (e.isMfaRequired && e.challengeId != null) {
        widget.onMfaRequired(_phoneCtrl.text.trim(), e.challengeId!, e.delivery);
        return;
      }
      // An old build talking to a server that has moved to phone login. Retrying
      // cannot help — only updating the app can — so it is shown like a closed
      // account: as a block with the log-in button off. The server's message
      // already asks, in English and Filipino, for the update.
      if (e.isAppUpdateRequired) {
        setState(() => _blockedMessage = e.message);
        return;
      }
      // A closed account. Deliberately NOT routed to _formError: the password
      // was right, so retyping it produces the same 403 forever, and an error
      // above a live "Log in" button reads as an invitation to try again. The
      // server's own message is shown because it names the only thing that
      // actually helps — going to the office. Nothing in the app can fix this.
      if (e.isAccountDeactivated) {
        setState(() => _blockedMessage = e.message);
        return;
      }
      // Already resident-readable: "Invalid resident credentials." on a bad
      // password, the connection message when the server is unreachable, the
      // "could not send the text" sentence when there was no way to deliver a
      // code. Already logged by ApiService with its status; the number is
      // deliberately not added here.
      setState(() => _formError = e.message);
    } catch (error) {
      // Anything that is not an ApiException got past the HTTP layer's own
      // normalisation, so nothing has logged it yet.
      AppLog.error('auth', 'resident login', error: error);
      setState(() => _formError =
          'Cannot connect to server. Check your connection.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AuthPage(
      header: const AppHeader(),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Semantics(
              header: true,
              child: Text(
                'Welcome back',
                style: AppText.display(size: AppTextSize.headline, color: AppColors.sectionInk),
                textAlign: TextAlign.center,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              'Log in to submit and track your service requests.',
              textAlign: TextAlign.center,
              style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted, height: 1.5),
            ),
            const SizedBox(height: AppSpacing.lg),
            const ServicePurposeNote(),
            if (widget.infoMessage != null) ...[
              const SizedBox(height: AppSpacing.md),
              InlineNotice(text: widget.infoMessage!, tone: NoticeTone.success),
            ],
            const SizedBox(height: AppSpacing.xl),
            AuthTextField(
              label: 'Mobile number',
              hint: '09XXXXXXXXX',
              controller: _phoneCtrl,
              keyboard: TextInputType.phone,
              prefixIcon: Icons.phone_outlined,
              maxLength: PhoneNumber.maxLength,
              inputFormatters: [
                FilteringTextInputFormatter.allow(RegExp(r'[0-9+]')),
              ],
              validator: (v) {
                final val = (v ?? '').trim();
                if (val.isEmpty) return 'Enter your mobile number';
                if (!PhoneNumber.isValid(val)) {
                  return 'Enter a valid mobile number';
                }
                return null;
              },
            ),
            AuthTextField(
              label: 'Password',
              hint: '',
              controller: _passCtrl,
              obscure: true,
              prefixIcon: Icons.lock_outline_rounded,
              validator: (v) => (v ?? '').isEmpty ? 'Enter your password' : null,
            ),
            if (_blockedMessage != null)
              InlineNotice(text: _blockedMessage!, icon: Icons.block_rounded),
            if (_formError != null) InlineNotice(text: _formError!),
            Align(
              alignment: Alignment.centerRight,
              child: AuthLink(
                label: 'Forgot password?',
                onPressed: () => widget.onForgotPassword(_phoneCtrl.text.trim()),
              ),
            ),
            const SizedBox(height: AppSpacing.sm),
            AppButton(
              label: 'Log in',
              loading: _loading,
              // Null disables it — AppButton already treats a null onPressed as
              // disabled, the same path `loading` uses. This is what removes the
              // retry rather than merely discouraging it.
              onPressed: _blockedMessage == null ? _submit : null,
            ),
            const SizedBox(height: AppSpacing.md),
            Wrap(
              alignment: WrapAlignment.center,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text("Don't have an account?", style: AppText.body(size: AppTextSize.body, color: AppColors.inkMuted)),
                AuthLink(label: 'Register', onPressed: widget.onGoToRegister),
              ],
            ),
            // Hotlines need no account: reachable before signing in.
            if (widget.onOpenHotlines != null)
              Center(
                child: TextButton.icon(
                  onPressed: widget.onOpenHotlines,
                  style: TextButton.styleFrom(minimumSize: const Size(64, 48)),
                  icon: const Icon(Icons.call_rounded, size: 20, color: AppColors.red600),
                  label: Text(
                    'Emergency hotlines',
                    style: AppText.display(size: AppTextSize.body, weight: FontWeight.w700, color: AppColors.red600),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
