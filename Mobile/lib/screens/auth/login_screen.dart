/// Login screen: the first screen unauthenticated residents see.
///
/// Sends the resident's email + password to the Laravel backend via
/// [UserStore.login] → [ApiService.residentLogin].
library serbis.screens.auth.login;

import 'package:flutter/material.dart';
import '../../state/account_store.dart';
import '../../theme/app_theme.dart';
import '../../widgets/shared_widgets.dart';

/// Login screen.
///
/// Uses email address + password (matching the Laravel [Resident] model).
/// On success the Sanctum token is stored locally and [onLoginSuccess] is
/// called with the logged-in [AppUser].
class LoginScreen extends StatefulWidget {
  final UserStore userStore;
  final void Function(AppUser user) onLoginSuccess;
  final VoidCallback onGoToRegister;
  final String? infoMessage;

  const LoginScreen({
    super.key,
    required this.userStore,
    required this.onLoginSuccess,
    required this.onGoToRegister,
    this.infoMessage,
  });

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey    = GlobalKey<FormState>();
  final _emailCtrl  = TextEditingController();
  final _passCtrl   = TextEditingController();
  bool    _loading   = false;
  String? _formError;

  @override
  void dispose() {
    _emailCtrl.dispose();
    _passCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _formError = null);
    if (!_formKey.currentState!.validate()) return;

    setState(() => _loading = true);

    try {
      final user = await widget.userStore.login(
        email:    _emailCtrl.text.trim(),
        password: _passCtrl.text,
      );
      if (!mounted) return;
      widget.onLoginSuccess(user);
    } on String catch (e) {
      // Human-readable error thrown by ApiService / UserStore
      setState(() => _formError = e);
    } catch (e) {
      setState(() => _formError =
          'Cannot connect to server. Check your connection.');
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
            const AppHeader(),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 28, 24, 24),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // ── Heading ──────────────────────────────────────────
                    Text(
                      'Welcome back',
                      style: AppText.display(size: 21),
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'Log in to submit and track your service requests '
                      'with Echague MDRRMO.',
                      textAlign: TextAlign.center,
                      style: AppText.body(
                          size: 12.5, color: AppColors.inkMuted, height: 1.5),
                    ),

                    // ── Success banner (after register) ──────────────────
                    if (widget.infoMessage != null) ...[
                      const SizedBox(height: 14),
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: AppColors.green50,
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Icon(Icons.check_circle_outline_rounded,
                                size: 18, color: AppColors.green700),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(widget.infoMessage!,
                                  style: AppText.body(
                                      size: 12.5,
                                      color: AppColors.green900,
                                      height: 1.5)),
                            ),
                          ],
                        ),
                      ),
                    ],

                    const SizedBox(height: 24),

                    // ── Email field ──────────────────────────────────────
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

                    // ── Password field ───────────────────────────────────
                    AuthTextField(
                      label: 'Password',
                      hint: 'Enter your password',
                      controller: _passCtrl,
                      obscure: true,
                      prefixIcon: Icons.lock_outline_rounded,
                      validator: (v) =>
                          (v ?? '').isEmpty ? 'Enter your password' : null,
                    ),

                    // ── API error banner ─────────────────────────────────
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

                    // ── Forgot password ──────────────────────────────────
                    Align(
                      alignment: Alignment.centerRight,
                      child: GestureDetector(
                        onTap: () => showAppSnackBar(
                            context, 'Contact MDRRMO to reset your password.'),
                        child: Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: Text('Forgot password?',
                              style: AppText.display(
                                  size: 12,
                                  weight: FontWeight.w600,
                                  color: AppColors.green700)),
                        ),
                      ),
                    ),

                    const SizedBox(height: 8),
                    AppButton(
                        label: 'Log in',
                        loading: _loading,
                        onPressed: _submit),
                    const SizedBox(height: 18),

                    // ── Go to Register ───────────────────────────────────
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text("Don't have an account?",
                            style: AppText.body(
                                size: 12.5, color: AppColors.inkMuted)),
                        const SizedBox(width: 4),
                        GestureDetector(
                          onTap: widget.onGoToRegister,
                          child: Text('Register',
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
