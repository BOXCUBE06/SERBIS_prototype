
const Map<String, (String, String)> _strings = {
  'common.cancel': ('Cancel', 'Kanselahin'),
  'common.submit_request': ('Submit request', 'Isumite ang Kahilingan'),
  'common.view_details': ('View details', 'Tingnan ang Detalye'),
  'common.view_all': ('View all', 'Tingnan Lahat'),
  'common.view_timeline': ('View timeline', 'Tingnan ang Timeline'),
  'common.hide_timeline': ('Hide timeline', 'Itago ang Timeline'),
  'common.cancel_request': ('Cancel request', 'Kanselahin ang Kahilingan'),
  'common.calling': ('Calling', 'Tumatawag sa'),
  'common.close': ('Close', 'Isara'),

  'timeline.submitted': ('Request submitted', 'Naisumite ang kahilingan'),
  'timeline.review': ('Under review by MDRRMO', 'Sinusuri ng MDRRMO'),
  'timeline.responding': ('MDRRMO is responding', 'Tumutugon ang MDRRMO'),
  'timeline.completed': ('Completed', 'Natapos'),
  'timeline.cancelled': ('Cancelled', 'Kinansela'),
  'timeline.disapproved': ('Not approved by MDRRMO', 'Hindi inaprubahan ng MDRRMO'),
  'timeline.awaiting': ('Waiting', 'Naghihintay'),
  'timeline.time_unknown': ('Time not recorded', 'Walang naitalang oras'),
  'timeline.today': ('Today', 'Ngayon'),

  'status.review': ('Under review', 'Sinusuri'),
  'status.scheduled': ('Scheduled', 'Naka-iskedyul'),
  'status.completed': ('Completed', 'Natapos'),
  'status.cancelled': ('Cancelled', 'Kinansela'),
  'status.disapproved': ('Not approved', 'Hindi inaprubahan'),

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
  'home.ann.empty': (
    'MDRRMO has not published anything yet.',
    'Wala pang nailalathalang materyal ang MDRRMO.',
  ),
  'home.ann.failed': (
    "Couldn't load announcements.",
    'Hindi ma-load ang mga abiso.',
  ),
  'home.ann.offline': (
    'Saved copies — not refreshed from MDRRMO.',
    'Mga naka-save na kopya — hindi pa na-refresh mula sa MDRRMO.',
  ),
  'home.ann.no_date': ('Date not recorded', 'Walang naitalang petsa'),

  // ---- Offline ----
  'offline.title': (
    "No connection to MDRRMO. Requests can't be sent.",
    'Walang koneksyon sa MDRRMO. Hindi maipapadala ang mga kahilingan.',
  ),
  'offline.last_updated': ('Last updated', 'Huling na-update'),
  'offline.never_updated': (
    'Nothing has been loaded on this device yet.',
    'Wala pang na-load sa device na ito.',
  ),
  'offline.saved_copy': (
    'Saved copy',
    'Naka-save na kopya',
  ),

  // ---- Notifications ----
  'notif.empty_title': ('No updates yet', 'Wala pang update'),
  'notif.empty_body': (
    'Updates about your service requests appear here once you have submitted one.',
    'Lilitaw dito ang mga update tungkol sa iyong mga kahilingan sa serbisyo kapag nagsumite ka na.',
  ),
  'notif.scope_note': (
    'Updates about your own requests only. MDRRMO advisories are not sent here yet.',
    'Mga update lamang sa sarili mong kahilingan. Hindi pa dito ipinapadala ang mga abiso ng MDRRMO.',
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
  'home.status.disapproved': (
    'MDRRMO did not approve this request.',
    'Hindi inaprubahan ng MDRRMO ang kahilingang ito.',
  ),

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

  'track.title': ('Track Your Requests', 'Subaybayan ang Iyong mga Kahilingan'),
  'track.empty_title': ('No requests yet', 'Walang kahilingan pa'),
  'track.empty_desc': (
    'Requests you submit from the Services tab will appear here, '
        'with their status and a timeline you can follow.',
    'Lalabas dito ang mga kahilingang isusumite mo mula sa Services tab, '
        'kasama ang status at timeline na maaari mong subaybayan.',
  ),
  'track.filter.all': ('All', 'Lahat'),

  'library.title': ('Safety Library', 'Aklatan ng Kaligtasan'),
  'library.hotlines': ('Emergency Hotlines', 'Mga Hotline ng Emerhensiya'),
  'library.first_aid': ('Basic First Aid', 'Pangunahing Lunas (First Aid)'),
  'library.preparedness': ('Disaster Preparedness', 'Paghahanda sa Sakuna'),
  'library.documents': ('MDRRMO Documents', 'Mga Dokumento ng MDRRMO'),
  'library.police': ('Police (PNP)', 'Pulis (PNP)'),
  'library.fire': ('Fire (BFP)', 'Bumbero (BFP)'),
  'library.national_emergency': ('National Emergency', 'Pambansang Emerhensiya'),

  'profile.title': ('My Profile', 'Aking Profile'),
  'profile.photo': ('Profile photo', 'Larawan sa Profile'),
  'profile.photo_change': ('Change profile photo', 'Palitan ang larawan sa profile'),
  'profile.photo_choose': ('Choose a photo', 'Pumili ng larawan'),
  'profile.photo_remove': ('Remove photo', 'Alisin ang larawan'),
  // The sheet is read-only until a resident-scoped PATCH exists on the backend,
  // so the button no longer promises an update it cannot perform.
  'profile.account_details': ('Account details', 'Mga Detalye ng Account'),
  'profile.email': ('Email address', 'Email Address'),
  'profile.barangay': ('Barangay', 'Barangay'),
  // The barangay stays read-only: it is the field every service request is
  // dispatched on, so moving is an MDRRMO operation, not a self-service edit.
  'profile.barangay_locked': (
    'Contact MDRRMO to change your barangay — it is what your requests are dispatched on.',
    'Makipag-ugnayan sa MDRRMO para palitan ang iyong barangay — ito ang batayan ng pagpapadala sa iyong mga kahilingan.',
  ),
  'profile.first_name': ('First name', 'Pangalan'),
  'profile.middle_name_optional': ('Middle name (optional)', 'Gitnang Pangalan (opsyonal)'),
  'profile.last_name': ('Last name', 'Apelyido'),
  'profile.phone': ('Mobile number', 'Numero ng Telepono'),
  'profile.save': ('Save changes', 'I-save ang Pagbabago'),
  'profile.saved': ('Profile updated.', 'Na-update ang profile.'),
  'profile.no_changes': ('Nothing to save.', 'Walang isasave.'),
  'profile.required': ('Required', 'Kailangan'),
  'profile.email_invalid': ('Enter a valid email address.', 'Maglagay ng wastong email address.'),
  'profile.phone_invalid': (
    'Enter a valid mobile number.',
    'Maglagay ng wastong numero ng telepono.',
  ),
  // Shown in place of a value the server did not send. Never a plausible-looking
  // placeholder: the barangay here is what an emergency request is dispatched on.
  'profile.value_missing': ('Not on file', 'Wala sa talaan'),
  'profile.account_settings': ('Account settings', 'Mga Setting ng Account'),
  'profile.language': ('Language', 'Wika'),
  'profile.offline_materials': ('Offline materials', 'Mga Offline na Materyal'),
  'profile.offline_materials_desc': ('{n} saved · {size} used', '{n} naka-save · {size} ang nagamit'),
  'profile.logout': ('Log out', 'Mag-log Out'),
  'profile.offline_title': ('Offline Materials', 'Mga Offline na Materyal'),

  // The app records what went wrong so a resident can hand it to MDRRMO. There
  // is no crash reporter and no server-side client error log: this is a system
  // holding government ID scans, and shipping its failures to a third party is
  // not a trade this project gets to make on residents' behalf.
  'profile.report_problem': ('Report a problem', 'Mag-ulat ng Problema'),
  'profile.report_events': ('{n} events recorded', '{n} kaganapan ang naitala'),
  'profile.report_none': ('Nothing recorded yet', 'Wala pang naitala'),
  'profile.report_explain': (
    'This copies a list of what the app tried to do and what failed. It holds '
        'no password, no ID photo and nothing you typed into a request.',
    'Kokopyahin nito ang listahan ng sinubukan ng app at kung ano ang nabigo. '
        'Walang password, walang larawan ng ID, at wala sa mga isinulat mo sa '
        'kahilingan.',
  ),
  'profile.report_send': (
    'Paste it into a message or email to MDRRMO.',
    'I-paste ito sa mensahe o email sa MDRRMO.',
  ),
  'profile.report_empty': (
    'Nothing has failed since you opened the app, so there is nothing to send.',
    'Walang nabigo mula nang buksan mo ang app, kaya wala pang maipapadala.',
  ),
  'profile.report_copy': ('Copy report', 'Kopyahin ang Ulat'),
  'profile.report_copied': ('Report copied.', 'Nakopya ang ulat.'),
};

String tr(bool filipino, String key) {
  final pair = _strings[key];
  if (pair == null) return key;
  return filipino ? pair.$2 : pair.$1;
}
