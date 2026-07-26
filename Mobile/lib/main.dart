
library serbis.main;

import 'package:flutter/material.dart';
import 'models/request_models.dart';
import 'screens/auth/login_screen.dart';
import 'screens/auth/register_screen.dart';
import 'screens/dashboard_screen.dart';
import 'screens/library_screen.dart';
import 'screens/profile_screen.dart';
import 'screens/services_screen.dart';
import 'screens/track_screen.dart';
import 'state/api_service.dart';
import 'state/request_store.dart';
import 'state/account_store.dart';
import 'theme/app_theme.dart';
import 'widgets/shared_widgets.dart';
import 'widgets/sos_button.dart';

void main() {
  runApp(const SerbisApp());
}

class SerbisApp extends StatelessWidget {
  const SerbisApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'SERBIS — Echague MDRRMO',
      debugShowCheckedModeBanner: false,
      theme: buildAppTheme(),
      home: const AuthGate(),
    );
  }
}

class AuthGate extends StatefulWidget {
  const AuthGate({super.key});

  @override
  State<AuthGate> createState() => _AuthGateState();
}

enum _AuthView { login, register }

class _AuthGateState extends State<AuthGate> {
  final ApiService _api = ApiService();
  late final UserStore _userStore = UserStore(_api);

  bool _ready = false;
  AppUser? _currentUser;
  _AuthView _view = _AuthView.login;
  String? _loginInfoMessage;

  @override
  void initState() {
    super.initState();
    _api.onUnauthorized = _onSessionExpired;
    _restoreSession();
  }

  /// A stored token carries no profile with it, so it has to be exchanged for
  /// one on every relaunch. Skipping this is what left the app authenticated
  /// with a blank name, email and address until the resident logged out and
  /// back in.
  Future<void> _restoreSession() async {
    await _api.loadToken();

    if (_api.isLoggedIn) {
      try {
        final user = await _userStore.currentUser();
        if (!mounted) {
          return;
        }

        setState(() {
          _currentUser = user;
          _ready = true;
        });
        return;
      } on ApiException catch (e) {
        if (!mounted) {
          return;
        }

        // A rejected token has already been cleared by ApiService. If the server
        // was merely unreachable the token is left alone, so logging in again
        // once there is a connection will work.
        _loginInfoMessage = e.isUnauthorized
            ? 'Your session expired. Please log in again.'
            : e.message;
      }
    }

    if (!mounted) {
      return;
    }

    setState(() {
      _ready = true;
      _currentUser = null;
    });
  }

  /// Fired by ApiService when a stored token is rejected mid-session.
  void _onSessionExpired() {
    if (!mounted || _currentUser == null) {
      return;
    }

    setState(() {
      _currentUser = null;
      _view = _AuthView.login;
      _loginInfoMessage = 'Your session expired. Please log in again.';
    });
  }

  void _login(AppUser user) {
    setState(() {
      _currentUser = user;
      _ready = true;
    });
  }

  void _afterRegister() {
    setState(() {
      _view = _AuthView.login;
      _loginInfoMessage = 'Account created! Please log in to verify your details.';
    });
  }

  void _logout() {
    _userStore.logout();
    setState(() {
      _currentUser = null;
      _view = _AuthView.login;
      _loginInfoMessage = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (!_ready) {
      return const Scaffold(
        backgroundColor: AppColors.paper,
        body: Center(
          child: CircularProgressIndicator(color: AppColors.green700),
        ),
      );
    }

    if (_currentUser != null) {
      return RootShell(
        api: _api,
        onLogout: _logout,
        initialName: _currentUser!.fullName.isEmpty ? null : _currentUser!.fullName,
        initialEmail: _currentUser!.email.isEmpty ? null : _currentUser!.email,
        initialAddress: _currentUser!.address.isEmpty ? null : _currentUser!.address,
      );
    }

    if (_view == _AuthView.login) {
      return LoginScreen(
        userStore: _userStore,
        onLoginSuccess: _login,
        onGoToRegister: () {
          setState(() {
            _view = _AuthView.register;
            _loginInfoMessage = null;
          });
        },
        infoMessage: _loginInfoMessage,
      );
    }

    return RegisterScreen(
      userStore: _userStore,
      onRegisterSuccess: _afterRegister,
      onGoToLogin: () {
        setState(() {
          _view = _AuthView.login;
        });
      },
    );
  }
}

class RootShell extends StatefulWidget {
  final ApiService api;
  final VoidCallback onLogout;
  final String? initialName;
  final String? initialEmail;
  final String? initialAddress;

  const RootShell({
    super.key,
    required this.api,
    required this.onLogout,
    this.initialName,
    this.initialEmail,
    this.initialAddress,
  });

  @override
  State<RootShell> createState() => _RootShellState();
}

class _RootShellState extends State<RootShell> {
  int _index = 0;
  ServiceType _serviceType = ServiceType.ambulance;
  late final AppState _appState = AppState(widget.api);

  @override
  void initState() {
    super.initState();
    _appState.addListener(_onAppStateChanged);
    _appState.loadRequests();
    // Service names are server-side, so Track and the Home card cannot label
    // themselves in the resident's language until the catalogue is in hand.
    // Waiting for a visit to the Services tab would show English until then.
    _appState.loadServices();
  }

  @override
  void dispose() {
    _appState.removeListener(_onAppStateChanged);
    super.dispose();
  }

  void _onAppStateChanged() {
    setState(() {});

    // Single drain point for store failures, so every screen reports them the
    // same way instead of each one swallowing its own.
    final error = _appState.takeError();
    if (error == null) {
      return;
    }

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        showAppSnackBar(context, error, isError: true);
      }
    });
  }

  void _goTo(int index) => setState(() => _index = index);

  void _openService(ServiceType type) {
    setState(() {
      _serviceType = type;
      _index = 1;
    });
  }

  @override
  Widget build(BuildContext context) {
    final onOpenNotifications = () => NotificationsSheet.show(
          context,
          filipino: _appState.language == AppLanguage.filipino,
        );
    final onOpenProfile = () => _goTo(4);

    final screens = [
      HomeScreen(
        appState: _appState,
        onOpenTrack: () => _goTo(2),
        onOpenLibrary: () => _goTo(3),
        onOpenProfile: onOpenProfile,
        onOpenNotifications: onOpenNotifications,
        onOpenServices: () => _goTo(1),
        onOpenService: _openService,
      ),
      ServicesScreen(
        key: ValueKey(_serviceType),
        appState: _appState,
        initialType: _serviceType,
        onSubmitted: () => _goTo(2),
        onOpenNotifications: onOpenNotifications,
        onOpenProfile: onOpenProfile,
      ),
      TrackScreen(
        appState: _appState,
        onOpenNotifications: onOpenNotifications,
        onOpenProfile: onOpenProfile,
      ),
      LibraryScreen(
        appState: _appState,
        onOpenNotifications: onOpenNotifications,
        onOpenProfile: onOpenProfile,
      ),
      ProfileScreen(
        appState: _appState,
        onLogout: widget.onLogout,
        onOpenNotifications: onOpenNotifications,
        onOpenProfile: onOpenProfile,
        initialName: widget.initialName,
        initialEmail: widget.initialEmail,
        initialAddress: widget.initialAddress,
      ),
    ];

    return Scaffold(
      extendBody: true,
      body: SafeArea(
        top: false,
        bottom: false,
        child: IndexedStack(index: _index, children: screens),
      ),
      floatingActionButton: const SosFab(),
      floatingActionButtonLocation: FloatingActionButtonLocation.endFloat,
      bottomNavigationBar: _BottomNav(index: _index, onTap: _goTo),
    );
  }
}

class _BottomNav extends StatelessWidget {
  final int index;
  final ValueChanged<int> onTap;

  const _BottomNav({required this.index, required this.onTap});

  static const _items = [
    (Icons.home_rounded, Icons.home_outlined, 'Home'),
    (Icons.assignment_rounded, Icons.assignment_outlined, 'Services'),
    (Icons.fact_check_rounded, Icons.fact_check_outlined, 'Track'),
    (Icons.menu_book_rounded, Icons.menu_book_outlined, 'Library'),
    (Icons.person_rounded, Icons.person_outline_rounded, 'Profile'),
  ];

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface,
        border: Border(top: BorderSide(color: AppColors.line)),
      ),
      padding: const EdgeInsets.fromLTRB(8, 10, 8, 18),
      child: SafeArea(
        top: false,
        child: Row(
          children: List.generate(_items.length, (i) {
            final (filled, outline, label) = _items[i];
            final active = i == index;
            return Expanded(
              child: InkWell(
                onTap: () => onTap(i),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(active ? filled : outline, size: 22, color: active ? AppColors.green700 : AppColors.inkFaint),
                    const SizedBox(height: 4),
                    Text(
                      label,
                      style: AppText.display(
                        size: 11,
                        weight: active ? FontWeight.w600 : FontWeight.w500,
                        color: active ? AppColors.green700 : AppColors.inkFaint,
                      ),
                    ),
                  ],
                ),
              ),
            );
          }),
        ),
      ),
    );
  }
}
