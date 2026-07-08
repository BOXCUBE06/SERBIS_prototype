import 'package:flutter/material.dart';
import 'models/request_models.dart';
import 'screens/auth/login_screen.dart';
import 'screens/auth/register_screen.dart';
import 'screens/dashboard_screen.dart';
import 'screens/library_screen.dart';
import 'screens/profile_screen.dart';
import 'screens/services_screen.dart';
import 'screens/track_screen.dart';
import 'state/request_store.dart';
import 'state/account_store.dart';
import 'state/api_service.dart';
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

/// Top-level switcher between the auth flow (Login / Register) and the
/// main app shell.
///
/// Accounts and requests are now backed by the Laravel API through a
/// single shared [ApiService] instance, created here and passed down to
/// [UserStore] (for auth) and [AppState] (for requests). On startup, if a
/// Sanctum token was saved from a previous session, the resident is signed
/// back in automatically instead of being sent to the login screen.
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
    _userStore.load().then((_) async {
      if (_userStore.isLoggedIn) {
        try {
          final userData = await _userStore.getUser();
          if (!mounted) return;
          setState(() {
            _currentUser = AppUser.fromJson(userData);
            _ready = true;
          });
          return;
        } catch (_) {
          // Saved token is invalid or expired — fall through to login.
        }
      }
      if (mounted) setState(() => _ready = true);
    });
  }

  void _login(AppUser user) => setState(() => _currentUser = user);

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
        body: Center(child: CircularProgressIndicator(color: AppColors.green700)),
      );
    }

    final user = _currentUser;
    if (user != null) {
      return RootShell(
        api: _api,
        onLogout: _logout,
        initialName: user.name.isEmpty ? null : user.name,
        initialPhone: user.phone.isEmpty ? null : user.phone,
        initialAddress: user.address.isEmpty ? null : user.address,
      );
    }

    return _view == _AuthView.login
        ? LoginScreen(
            userStore: _userStore,
            onLoginSuccess: _login,
            onGoToRegister: () => setState(() {
              _view = _AuthView.register;
              _loginInfoMessage = null;
            }),
            infoMessage: _loginInfoMessage,
          )
        : RegisterScreen(
            userStore: _userStore,
            onRegisterSuccess: _afterRegister,
            onGoToLogin: () => setState(() => _view = _AuthView.login),
          );
  }
}

/// Holds the bottom navigation, the persistent SOS button, and switches
/// between the five main screens.
class RootShell extends StatefulWidget {
  final ApiService api;
  final VoidCallback onLogout;
  final String? initialName;
  final String? initialPhone;
  final String? initialAddress;

  const RootShell({
    super.key,
    required this.api,
    required this.onLogout,
    this.initialName,
    this.initialPhone,
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
    // Rebuild whenever a request is added or cancelled so Home/Track/etc.
    // reflect the latest data.
    _appState.addListener(_onAppStateChanged);
    // Pull the resident's existing requests from the server on entry.
    _appState.loadRequests();
  }

  @override
  void dispose() {
    _appState.removeListener(_onAppStateChanged);
    super.dispose();
  }

  void _onAppStateChanged() => setState(() {});

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
        initialPhone: widget.initialPhone,
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