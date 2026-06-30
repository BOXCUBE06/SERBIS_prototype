/// Centralized English / Filipino translations for static UI text shared
/// across Home, Services, Track, Library, and Profile.
///
/// Usage: `tr(filipino, 'home.active_request')` where `filipino` is
/// `appState.language == AppLanguage.filipino`.
///
/// This covers headers, section titles, buttons, status labels, empty
/// states, and other chrome that appears regardless of what a resident has
/// typed into a form. Free-form user input (form field values) and
/// already-submitted request data are not retroactively translated.
const Map<String, (String, String)> _strings = {
  // ---- Common ----
  'common.cancel': ('Cancel', 'Kanselahin'),
  'common.submit_request': ('Submit request', 'Isumite ang Kahilingan'),
  'common.view_details': ('View details', 'Tingnan ang Detalye'),
  'common.view_all': ('View all', 'Tingnan Lahat'),
  'common.view_timeline': ('View timeline', 'Tingnan ang Timeline'),
  'common.hide_timeline': ('Hide timeline', 'Itago ang Timeline'),
  'common.cancel_request': ('Cancel request', 'Kanselahin ang Kahilingan'),
  'common.calling': ('Calling', 'Tumatawag sa'),

  // ---- Status badges ----
  'status.review': ('Under review', 'Sinusuri'),
  'status.scheduled': ('Scheduled', 'Naka-iskedyul'),
  'status.completed': ('Completed', 'Natapos'),
  'status.cancelled': ('Cancelled', 'Kinansela'),

  // ---- Service types ----
  'type.ambulance.title': ('Medical Transport / Ambulance', 'Medical Transport / Ambulansya'),
  'type.ambulance.subtitle': ('Pick-up & drop-off', 'Pagsundo at paghatid'),
  'type.transfer.title': ('Hospital Transfer', 'Paglilipat sa Ospital'),
  'type.transfer.subtitle': ('Incl. dialysis patients', 'Kasama ang mga dialysis patient'),
  'type.road.title': ('Road Clearing', 'Paglinis ng Daan'),
  'type.road.subtitle': ('Debris, fallen trees', 'Debris, natumbang puno'),
  'type.relief.title': ('Relief Goods', 'Tulong / Relief Goods'),
  'type.relief.subtitle': ('Assistance request', 'Kahilingan ng tulong'),
  'type.inquiry.title': ('Information Inquiry', 'Katanungan / Impormasyon'),
  'type.inquiry.subtitle': ('General question to MDRRMO', 'Pangkalahatang tanong sa MDRRMO'),

  // ---- Home ----
  'home.active_request': ('Active Service Request', 'Aktibong Kahilingan'),
  'home.no_active_title': ('No active requests', 'Walang aktibong kahilingan'),
  'home.no_active_desc': (
    'Submit a service request and track its status here.',
    'Magsumite ng kahilingan sa serbisyo at subaybayan dito ang status.',
  ),
  'home.submit_a_request': ('Submit a request', 'Magsumite ng Kahilingan'),
  'home.need_help_now': ('Need help now?', 'Kailangan ng tulong ngayon?'),
  'home.announcements': ('Announcements', 'Mga Abiso'),
  'home.info_center': ('Info center', 'Sentro ng Impormasyon'),
  'home.ann1.title': ('New materials uploaded', 'Bagong materyal na na-upload'),
  'home.ann1.time': ('Today · 8:12 AM', 'Ngayon · 8:12 AM'),
  'home.ann1.desc': (
    'Updated flood preparedness guide and evacuation map are now available offline in the Library.',
    'Available na sa Library nang offline ang na-update na gabay sa pag-iwas sa baha at mapa ng evacuation.',
  ),
  'home.ann2.title': ('Weather advisory', 'Babala sa Panahon'),
  'home.ann2.time': ('Yesterday · 4:30 PM', 'Kahapon · 4:30 PM'),
  'home.ann2.desc': (
    'Light to moderate rains expected over Isabela through the weekend. '
        'Residents near riverbanks advised to stay alert.',
    'Inaasahan ang magaan hanggang katamtamang ulan sa Isabela hanggang weekend. '
        'Pinapaalalahanan ang mga residenteng malapit sa ilog na mag-ingat.',
  ),
  'home.status.review': (
    'Your request has been forwarded to MDRRMO for review. '
        "You'll be notified once it's processed.",
    'Ang iyong kahilingan ay ipinasa na sa MDRRMO para sa pagsusuri. '
        'Aabisuhan ka kapag ito ay naproseso.',
  ),
  'home.status.scheduled': (
    'Your request has been scheduled. '
        "You'll be notified of any updates.",
    'Naka-iskedyul na ang iyong kahilingan. '
        'Aabisuhan ka kung may update.',
  ),
  'home.status.completed': ('This request has been completed.', 'Natapos na ang kahilingang ito.'),
  'home.status.cancelled': ('This request has been cancelled.', 'Nakansela na ang kahilingang ito.'),

  // ---- Services ----
  'services.title': ('Service Request', 'Kahilingan sa Serbisyo'),
  'services.notice_title': ('Non-life-threatening use only', 'Para sa hindi-banta-sa-buhay na sitwasyon lamang'),
  'services.notice_body': (
    'If you are experiencing a life-threatening emergency, contact authorities directly:',
    'Kung ikaw ay nasa banta-sa-buhay na emerhensiya, direktang tawagan ang mga awtoridad:',
  ),
  'services.choose_type': ('Choose a request type', 'Pumili ng Uri ng Kahilingan'),
  'services.form.ambulance': ('Ambulance Request', 'Kahilingan ng Ambulansya'),
  'services.form.transfer': ('Hospital Transfer Request', 'Kahilingan ng Paglilipat sa Ospital'),
  'services.form.road': ('Road Clearing Request', 'Kahilingan ng Paglinis ng Daan'),
  'services.form.relief': ('Relief Goods Assistance', 'Kahilingan ng Relief Goods'),
  'services.form.inquiry': ('Information Inquiry', 'Katanungan / Impormasyon'),
  'services.confirm.title': ('Request submitted', 'Naisumite ang Kahilingan'),
  'services.confirm.body': (
    'Your request has been received. You can track its status anytime from the Track tab. Reference #{ref}.',
    'Natanggap ang iyong kahilingan. Maaari mong subaybayan ang status nito anumang oras sa Track tab. Reference #{ref}.',
  ),
  'services.confirm.view_track': ('View in Track', 'Tingnan sa Track'),

  // ---- Track ----
  'track.title': ('Track Your Requests', 'Subaybayan ang Iyong mga Kahilingan'),
  'track.empty_title': ('No requests yet', 'Walang kahilingan pa'),
  'track.empty_desc': (
    'Requests you submit from the Services tab will appear here, '
        'with their status and a timeline you can follow.',
    'Lalabas dito ang mga kahilingang isusumite mo mula sa Services tab, '
        'kasama ang status at timeline na maaari mong subaybayan.',
  ),
  'track.filter.all': ('All', 'Lahat'),

  // ---- Library ----
  'library.title': ('Safety Library', 'Aklatan ng Kaligtasan'),
  'library.hotlines': ('Emergency Hotlines', 'Mga Hotline ng Emerhensiya'),
  'library.first_aid': ('Basic First Aid', 'Pangunahing Lunas (First Aid)'),
  'library.preparedness': ('Disaster Preparedness', 'Paghahanda sa Sakuna'),
  'library.documents': ('MDRRMO Documents', 'Mga Dokumento ng MDRRMO'),
  'library.police': ('Police (PNP)', 'Pulis (PNP)'),
  'library.fire': ('Fire (BFP)', 'Bumbero (BFP)'),
  'library.national_emergency': ('National Emergency', 'Pambansang Emerhensiya'),

  // ---- Profile ----
  'profile.title': ('My Profile', 'Aking Profile'),
  'profile.update_info': ('Update information', 'I-update ang Impormasyon'),
  'profile.notifications': ('Notifications', 'Mga Abiso'),
  'profile.sms_alerts': ('SMS alerts', 'SMS Alerts'),
  'profile.sms_alerts_desc': (
    'Receive advisories from MDRRMO via SMS',
    'Tumanggap ng mga abiso mula sa MDRRMO sa pamamagitan ng SMS',
  ),
  'profile.push': ('Push notifications', 'Push Notifications'),
  'profile.push_desc': (
    'Updates on your service requests',
    'Mga update sa iyong mga kahilingan sa serbisyo',
  ),
  'profile.account_settings': ('Account settings', 'Mga Setting ng Account'),
  'profile.language': ('Language', 'Wika'),
  'profile.offline_materials': ('Offline materials', 'Mga Offline na Materyal'),
  'profile.offline_materials_desc': ('{n} saved · {size} used', '{n} naka-save · {size} ang nagamit'),
  'profile.logout': ('Log out', 'Mag-log Out'),
  'profile.offline_title': ('Offline Materials', 'Mga Offline na Materyal'),
};

/// Returns the translation for [key] in Filipino if [filipino] is true,
/// otherwise English. Falls back to the key itself if not found, so a
/// missing translation is visible (rather than crashing) during
/// development.
String tr(bool filipino, String key) {
  final pair = _strings[key];
  if (pair == null) return key;
  return filipino ? pair.$2 : pair.$1;
}
