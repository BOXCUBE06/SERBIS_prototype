/// Registration screen: where new residents create an account.
///
/// Sends resident details to the Laravel backend via
/// [UserStore.register] → [ApiService.register].
library serbis.screens.auth.register;

import 'package:flutter/material.dart';
import '../../state/account_store.dart';
import '../../theme/app_theme.dart';
import '../../widgets/shared_widgets.dart';

/// Registration screen.
///
/// Collects first name, last name, email address, and password.
/// Password rules: exactly 8 characters, must include one uppercase
/// letter, one number, and one special character (e.g. Pasada1!).
///
/// On success, calls [onRegisterSuccess] which returns to Login with
/// a "please log in to verify" message.
class RegisterScreen extends StatefulWidget {
  final UserStore userStore;
  final VoidCallback onRegisterSuccess;
  final VoidCallback onGoToLogin;

  const RegisterScreen({
    super.key,
    required this.userStore,
    required this.onRegisterSuccess,
    required this.onGoToLogin,
  });

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _formKey       = GlobalKey<FormState>();
  final _firstNameCtrl = TextEditingController();
  final _lastNameCtrl  = TextEditingController();
  final _emailCtrl     = TextEditingController();
  final _passwordCtrl  = TextEditingController();
  final _confirmCtrl   = TextEditingController();
  bool    _agreed     = false;
  bool    _loading    = false;
  String? _formError;

  // Password validation patterns
  static final RegExp _upper   = RegExp(r'[A-Z]');
  static final RegExp _digit   = RegExp(r'[0-9]');
  static final RegExp _special = RegExp(r'[!@#\$%^&*(),.?":{}|<>_\-]');

  @override
  void dispose() {
    _firstNameCtrl.dispose();
    _lastNameCtrl.dispose();
    _emailCtrl.dispose();
    _passwordCtrl.dispose();
    _confirmCtrl.dispose();
    super.dispose();
  }

  String? _validatePassword(String? v) {
    final value = v ?? '';
    if (value.isEmpty)       return 'Enter a password';
    if (value.length != 8)   return 'Password must be exactly 8 characters';
    if (!_upper.hasMatch(value))   return 'Include at least one uppercase letter (A-Z)';
    if (!_digit.hasMatch(value))   return 'Include at least one number (0-9)';
    if (!_special.hasMatch(value)) return 'Include at least one special character (e.g. !@#\$)';
    return null;
  }

  Future<void> _submit() async {
    setState(() => _formError = null);

    final formValid = _formKey.currentState!.validate();
    if (!_agreed) {
      showAppSnackBar(
          context, 'Please agree to the data privacy notice to continue.');
    }
    if (!formValid || !_agreed) return;

    setState(() => _loading = true);

    try {
      final error = await widget.userStore.register(
        firstName: _firstNameCtrl.text.trim(),
        lastName:  _lastNameCtrl.text.trim(),
        email:     _emailCtrl.text.trim(),
        password:  _passwordCtrl.text,
      );

      if (!mounted) return;

      if (error != null) {
        // Server returned a validation / duplicate-email error
        setState(() => _formError = error);
        return;
      }

      // Success — go back to Login with a confirmation banner
      widget.onRegisterSuccess();
    } catch (e) {
      if (mounted) {
        setState(() =>
            _formError = 'Cannot connect to server. Check your connection.');
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.paper,
      body: SafeArea(
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            _Header(onBack: widget.onGoToLogin),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 24, 24, 24),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // ── Heading ─────────────────────────────────────────
                    Text('Create your account',
                        style: AppText.display(size: 20)),
                    const SizedBox(height: 4),
                    Text(
                      'Register to submit service requests and receive '
                      'updates from MDRRMO. You will log in afterwards '
                      'to verify your account.',
                      style: AppText.body(
                          size: 12.5, color: AppColors.inkMuted, height: 1.5),
                    ),
                    const SizedBox(height: 22),

                    // ── First name ───────────────────────────────────────
                    AuthTextField(
                      label: 'First name',
                      hint: 'e.g. Juan',
                      controller: _firstNameCtrl,
                      prefixIcon: Icons.person_outline_rounded,
                      validator: (v) => (v ?? '').trim().isEmpty
                          ? 'Enter your first name'
                          : null,
                    ),

                    // ── Last name ────────────────────────────────────────
                    AuthTextField(
                      label: 'Last name',
                      hint: 'e.g. Delacruz',
                      controller: _lastNameCtrl,
                      prefixIcon: Icons.person_outline_rounded,
                      validator: (v) => (v ?? '').trim().isEmpty
                          ? 'Enter your last name'
                          : null,
                    ),

                    // ── Email ────────────────────────────────────────────
                    AuthTextField(
                      label: 'Email address',
                      hint: 'yourname@email.com',
                      controller: _emailCtrl,
                      keyboard: TextInputType.emailAddress,
                      prefixIcon: Icons.email_outlined,
                      validator: (v) {
                        final val = (v ?? '').trim();
                        if (val.isEmpty) return 'Enter your email address';
                        if (!val.contains('@') || !val.contains('.')) {
                          return 'Enter a valid email address';
                        }
                        return null;
                      },
                    ),

                    // ── Password ─────────────────────────────────────────
                    AuthTextField(
                      label: 'Password',
                      hint: '8 chars: A-Z, 0-9, symbol',
                      controller: _passwordCtrl,
                      obscure: true,
                      prefixIcon: Icons.lock_outline_rounded,
                      maxLength: 8,
                      validator: _validatePassword,
                    ),
                    Padding(
                      padding: const EdgeInsets.only(bottom: 13),
                      child: Text(
                        'Must be exactly 8 characters with at least one '
                        'uppercase letter, one number, and one special '
                        'character — e.g. Pasada1!',
                        style: AppText.body(
                            size: 11, color: AppColors.inkMuted, height: 1.5),
                      ),
                    ),

                    // ── Confirm password ─────────────────────────────────
                    AuthTextField(
                      label: 'Confirm password',
                      hint: 'Re-enter your password',
                      controller: _confirmCtrl,
                      obscure: true,
                      prefixIcon: Icons.lock_outline_rounded,
                      maxLength: 8,
                      validator: (v) {
                        if ((v ?? '').isEmpty) return 'Confirm your password';
                        if (v != _passwordCtrl.text) {
                          return 'Passwords do not match';
                        }
                        return null;
                      },
                    ),

                    // ── API / server error ───────────────────────────────
                    if (_formError != null)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Icon(Icons.error_outline_rounded,
                                size: 16, color: AppColors.red600),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(_formError!,
                                  style: AppText.body(
                                      size: 12.5,
                                      color: AppColors.red600,
                                      height: 1.4)),
                            ),
                          ],
                        ),
                      ),

                    // ── Privacy checkbox ─────────────────────────────────
                    const SizedBox(height: 4),
                    InkWell(
                      onTap: () => setState(() => _agreed = !_agreed),
                      borderRadius: BorderRadius.circular(10),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 6),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Padding(
                              padding: const EdgeInsets.only(top: 1),
                              child: Icon(
                                _agreed
                                    ? Icons.check_box_rounded
                                    : Icons.check_box_outline_blank_rounded,
                                size: 19,
                                color: _agreed
                                    ? AppColors.green700
                                    : AppColors.inkFaint,
                              ),
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                'I agree that my information will be used by '
                                'Echague MDRRMO to process service requests '
                                'and send announcements.',
                                style: AppText.body(
                                    size: 12,
                                    color: AppColors.inkMuted,
                                    height: 1.5),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),

                    const SizedBox(height: 14),
                    AppButton(
                        label: 'Create account',
                        loading: _loading,
                        onPressed: _submit),
                    const SizedBox(height: 18),

                    // ── Go to Login ──────────────────────────────────────
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text('Already have an account?',
                            style: AppText.body(
                                size: 12.5, color: AppColors.inkMuted)),
                        const SizedBox(width: 4),
                        GestureDetector(
                          onTap: widget.onGoToLogin,
                          child: Text('Log in',
                              style: AppText.display(
                                  size: 12.5,
                                  weight: FontWeight.w700,
                                  color: AppColors.green700)),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Compact gradient header with a back arrow — reused from the old
/// register screen.
class _Header extends StatelessWidget {
  final VoidCallback onBack;
  const _Header({required this.onBack});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(12, 14, 24, 28),
      decoration: const BoxDecoration(
        gradient: AppColors.headerGradient,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(28)),
      ),
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Positioned(
            right: -50,
            top: -70,
            child: Container(
              width: 160,
              height: 160,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: Colors.white.withOpacity(.05),
              ),
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              IconButton(
                onPressed: onBack,
                icon: const Icon(Icons.arrow_back_rounded, color: Colors.white),
              ),
              Padding(
                padding: const EdgeInsets.only(left: 12, top: 4),
                child: Row(
                  children: [
                    Container(
                      width: 38,
                      height: 38,
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(.12),
                        borderRadius: BorderRadius.circular(12),
                        border:
                            Border.all(color: Colors.white.withOpacity(.14)),
                      ),
                      alignment: Alignment.center,
                      child: const Icon(Icons.shield_outlined,
                          color: Colors.white, size: 19),
                    ),
                    const SizedBox(width: 11),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('SERBIS',
                            style: AppText.display(
                                size: 17,
                                color: Colors.white,
                                letterSpacing: .5)),
                        Text('ECHAGUE MDRRMO',
                            style: AppText.display(
                                size: 10,
                                weight: FontWeight.w500,
                                color: Colors.white.withOpacity(.65),
                                letterSpacing: 2)),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
