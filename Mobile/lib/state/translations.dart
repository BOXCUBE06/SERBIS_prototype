
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

  'request.responders_heading': ('Responders', 'Mga Tumutugon'),

  'timeline.submitted': ('Request submitted', 'Naisumite ang kahilingan'),
  'timeline.review': ('Under review by MDRRMO', 'Sinusuri ng MDRRMO'),
  'timeline.booked': ('Booked by MDRRMO', 'Nakabook sa MDRRMO'),
  'timeline.responding': ('MDRRMO is responding', 'Tumutugon ang MDRRMO'),
  'timeline.approved': ('Approved by MDRRMO', 'Inaprubahan ng MDRRMO'),
  'timeline.completed': ('Completed', 'Natapos'),
  'timeline.not_transported': ('Not transported', 'Hindi naihatid'),
  'timeline.cancelled': ('Cancelled', 'Kinansela'),
  'timeline.disapproved': ('Not approved by MDRRMO', 'Hindi inaprubahan ng MDRRMO'),
  'timeline.awaiting': ('Waiting', 'Naghihintay'),
  'timeline.time_unknown': ('Time not recorded', 'Walang naitalang oras'),
  'timeline.today': ('Today', 'Ngayon'),

  'status.review': ('Under review', 'Sinusuri'),
  'status.booked': ('Booked', 'Nakabook'),
  'status.scheduled': ('Responding', 'Tumutugon'),
  'status.not_transported': ('Not transported', 'Hindi naihatid'),
  'status.approved': ('Approved', 'Aprubado'),
  'account.individual': ('Individual', 'Indibidwal'),
  'account.organization': ('Organization', 'Organisasyon'),
  'account.barangay': ('Barangay', 'Barangay'),
  'awaiting.title': ('Awaiting MDRRMO approval', 'Naghihintay ng pag-apruba ng MDRRMO'),
  'awaiting.body': (
    'MDRRMO checks every organization account before it can request services. '
        "You'll be able to use the app as soon as it is approved.",
    'Sinusuri ng MDRRMO ang bawat account ng organisasyon bago ito makahiling ng serbisyo. '
        'Magagamit mo na ang app kapag naaprubahan ito.',
  ),
  'awaiting.check': ('Check again', 'Suriin muli'),
  'awaiting.still': ('Still waiting for approval.', 'Naghihintay pa rin ng pag-apruba.'),
  'awaiting.failed': ('Could not check right now. Try again.', 'Hindi masuri ngayon. Subukan muli.'),
  'status.completed': ('Completed', 'Natapos'),
  'status.cancelled': ('Cancelled', 'Kinansela'),
  'status.disapproved': ('Not approved', 'Hindi inaprubahan'),

  'common.booking_overdue': (
    'Scheduled time has passed. Contact MDRRMO if you still need this.',
    'Nakalipas na ang naka-iskedyul na oras. Makipag-ugnayan sa MDRRMO kung kailangan mo pa rin ito.',
  ),

  'type.ambulance.title': ('Medical Transport / Ambulance', 'Medical Transport / Ambulansya'),
  'type.ambulance.subtitle': ('Patient transport', 'Paghahatid ng pasyente'),
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
  'nav.home': ('Home', 'Home'),
  'nav.ambulance': ('Ambulance', 'Ambulansya'),
  'nav.services': ('Services', 'Serbisyo'),
  'nav.borrow': ('Borrow', 'Hiram'),
  'nav.track': ('Track', 'Subaybay'),
  'nav.back': ('Back', 'Bumalik'),
  'nav.notifications': ('Notifications', 'Mga Abiso'),
  'nav.profile': ('My profile', 'Aking profile'),
  'tab.borrow_unavailable': (
    'Equipment borrowing is not offered to this account type.',
    'Hindi iniaalok ang paghiram ng kagamitan sa uri ng account na ito.',
  ),
  'tab.ambulance_unavailable': (
    'Ambulance booking is not offered to this account type.',
    'Hindi iniaalok ang pag-book ng ambulansya sa uri ng account na ito.',
  ),
  'tab.load_failed': (
    'The list could not be loaded. Check your connection and try again.',
    'Hindi ma-load ang listahan. Suriin ang koneksyon at subukang muli.',
  ),
  'borrow.title': ('Borrow equipment', 'Manghiram ng kagamitan'),
  'tab.retry': ('Try again', 'Subukang muli'),
  'home.safety_guides': ('Safety guides', 'Mga Gabay sa Kaligtasan'),
  'home.safety_guides_desc': (
    'First aid, disaster preparedness and hotline numbers.',
    'Pangunang lunas, paghahanda sa sakuna at mga numero ng hotline.',
  ),
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
    'MDRRMO advisories sent to you, and updates about your own requests.',
    'Mga abiso ng MDRRMO na ipinadala sa iyo, at mga update sa sarili mong kahilingan.',
  ),
  'notif.advisories': ('MDRRMO advisories', 'Mga Abiso ng MDRRMO'),
  'notif.your_requests': ('Your requests', 'Iyong mga kahilingan'),
  'notif.adv_none': (
    'No advisories have been sent to you.',
    'Wala pang abisong ipinadala sa iyo.',
  ),
  'notif.adv_failed': (
    "Couldn't load advisories. This does not mean none were sent — check with your barangay.",
    'Hindi ma-load ang mga abiso. Hindi ito nangangahulugang wala — magtanong sa inyong barangay.',
  ),
  'notif.adv_date_unknown': ('Date not recorded', 'Walang naitalang petsa'),
  'home.status.review': (
    'Your request has been forwarded to MDRRMO for review. '
        "You'll be notified once it's processed.",
    'Ang iyong kahilingan ay ipinasa na sa MDRRMO para sa pagsusuri. '
        'Aabisuhan ka kapag ito ay naproseso.',
  ),
  'home.status.booked': (
    'Your request is booked. '
        'You will be notified before the schedule.',
    'Nakabook na ang iyong kahilingan. '
        'Aabisuhan ka bago ang iskedyul.',
  ),
  'home.status.scheduled': (
    'MDRRMO is responding to your request. '
        "You'll be notified of any updates.",
    'Tumutugon na ang MDRRMO sa iyong kahilingan. '
        'Aabisuhan ka kung may update.',
  ),
  'home.status.approved': (
    'MDRRMO has approved your request. '
        "You'll be notified of any updates.",
    'Inaprubahan na ng MDRRMO ang iyong kahilingan. '
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
  'services.grid_intro': (
    'Choose the service you need. Each one opens its own form.',
    'Piliin ang serbisyong kailangan mo. Bawat isa ay may sariling form.',
  ),
  'services.notice_title': ('Non-life-threatening use only', 'Para sa hindi-banta-sa-buhay na sitwasyon lamang'),
  'services.notice_body': (
    'If you are experiencing a life-threatening emergency, contact authorities directly:',
    'Kung ikaw ay nasa banta-sa-buhay na emerhensiya, direktang tawagan ang mga awtoridad:',
  ),
  'notice.show_hotlines': ('Show hotline numbers', 'Ipakita ang mga numero ng hotline'),
  'notice.hide_hotlines': ('Hide hotline numbers', 'Itago ang mga numero ng hotline'),
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
  'services.confirm.scheduled_for': ('Scheduled for', 'Naka-iskedyul para sa'),

  // ---- Guided form section labels ----
  // Group headings inside the four request forms, so related fields read as
  // one group instead of a flat, identically-spaced list of inputs.
  'form_section.patient': ('Patient', 'Pasyente'),
  'form_section.trip': ('Trip details', 'Detalye ng Byahe'),
  'form_section.condition': ('Condition', 'Kondisyon'),
  'form_section.relatives': ('Patient / Relatives', 'Pasyente / Kamag-anak'),
  'form_section.location': ('Location', 'Lokasyon'),
  'form_section.description': ('Description', 'Paglalarawan'),
  'form_section.household': ('Household', 'Sambahayan'),
  'form_section.assistance': ('Assistance needed', 'Kailangang Tulong'),
  'form_section.details': ('Details', 'Detalye'),
  'form_section.attachments': ('Attachments', 'Mga Kalakip'),
  'form_section.event': ('Event', 'Kaganapan'),
  'form_section.certification': ('Certification', 'Sertipikasyon'),

  // ---- Ambulance scheduling ----
  'ambulance_schedule.title': ('When', 'Kailan'),
  'ambulance_schedule.asap': ('As soon as possible', 'Sa lalong madaling panahon'),
  // Short on purpose: this is a toggle segment label, not a sentence — the
  // long-form phrasing lives in the confirmation card once a time is picked.
  'ambulance_schedule.mode_scheduled': ('Scheduled', 'Naka-iskedyul'),
  'ambulance_schedule.change': ('Change', 'Palitan'),
  'ambulance_schedule.lead_time_error': (
    'Please pick a time at least 1 hour from now.',
    'Pumili ng oras na hindi bababa sa 1 oras mula ngayon.',
  ),
  'ambulance_schedule.checking': ('Checking availability…', 'Sinusuri ang availability…'),
  'ambulance_schedule.some_free': (
    'At least one ambulance may be free at that time.',
    'May kaunting ambulansyang maaaring libre sa oras na iyon.',
  ),
  'ambulance_schedule.none_free': (
    'No ambulance may be free at that time yet. You can still submit — MDRRMO will confirm.',
    'Maaaring walang libreng ambulansya sa oras na iyon. Maaari ka pa ring magsumite — kukumpirmahin ito ng MDRRMO.',
  ),

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
  'profile.street_address': ('Street / Purok (optional)', 'Kalye / Purok (opsyonal)'),
  'profile.phone': ('Mobile number', 'Numero ng Telepono'),
  'profile.save': ('Save changes', 'I-save ang Pagbabago'),
  'profile.saved': ('Profile updated.', 'Na-update ang profile.'),
  'profile.no_changes': ('Nothing to save.', 'Walang isasave.'),
  'profile.required': ('Required', 'Kailangan'),
  'profile.password_current': ('Current password', 'Kasalukuyang password'),
  'profile.password_required': (
    'Enter your current password to change your number.',
    'Ilagay ang iyong kasalukuyang password para mapalitan ang numero.',
  ),
  // The mobile number is the login and where every code goes, so it is not
  // edited inline with the other details.
  'profile.phone_locked': (
    'Your mobile number is how you log in. Changing it needs your password and a code sent to the new number.',
    'Ang iyong mobile number ang ginagamit mo sa pag-login. Kailangan ang password mo at isang code na ipapadala sa bagong numero para mapalitan ito.',
  ),
  'phonechange.button': ('Change number', 'Palitan ang numero'),
  'phonechange.title': ('Change your mobile number', 'Palitan ang iyong mobile number'),
  'phonechange.new_number': ('New mobile number', 'Bagong mobile number'),
  'phonechange.same': ('That is already your number.', 'Iyan na ang iyong numero.'),
  'phonechange.send': ('Send code', 'Magpadala ng code'),
  'phonechange.code_title': ('Enter the code', 'Ilagay ang code'),
  'phonechange.sent_to': (
    'We sent a 6-digit code by text message to the new number ending in',
    'Nagpadala kami ng 6-digit na code sa text sa bagong numerong nagtatapos sa',
  ),
  'phonechange.enter_below': ('Enter it below.', 'Ilagay ito sa ibaba.'),
  'phonechange.code': ('Verification code', 'Verification code'),
  'phonechange.code_length': ('Enter the 6-digit code.', 'Ilagay ang 6-digit na code.'),
  'phonechange.confirm': ('Confirm new number', 'Kumpirmahin ang bagong numero'),
  'phonechange.resend': ('Send a new code', 'Magpadala ng bagong code'),
  'phonechange.resend_in': ('Resend code in', 'Ulitin ang pagpapadala sa loob ng'),
  'phonechange.new_code': ('A new code is on its way.', 'Papunta na ang bagong code.'),
  'phonechange.unknown_hint': (
    "Didn't get a text? It can take a minute. If it hasn't come when the timer ends, tap Send a new code.",
    'Walang dumating na text? Maaaring abutin ng isang minuto. Kung wala pa rin pagkatapos ng timer, pindutin ang Magpadala ng bagong code.',
  ),
  // Replaces the server's English for code `sms_unavailable`, so it reads in the
  // resident's own language. There is no email to fall back to.
  'phonechange.sms_unavailable': (
    'We could not send the text message. Check the number and try again in a minute, or visit the MDRRMO office.',
    'Hindi naipadala ang text message. Suriin ang numero at subukang muli pagkalipas ng isang minuto, o pumunta sa opisina ng MDRRMO.',
  ),
  'profile.phone_invalid': (
    'Enter a valid mobile number.',
    'Maglagay ng wastong numero ng telepono.',
  ),
  // Shown in place of a value the server did not send. Never a plausible-looking
  // placeholder: the barangay here is what an emergency request is dispatched on.
  'profile.value_missing': ('Not on file', 'Wala sa talaan'),
  'profile.account_settings': ('Account settings', 'Mga Setting ng Account'),
  'profile.language': ('Language', 'Wika'),
  'profile.sms_alerts': ('MDRRMO text alerts', 'Mga text alert ng MDRRMO'),
  // Blunt on purpose, in both directions. The switch controls one thing:
  // whether SmsController's recipient query includes this number. There is no
  // category of blast that ignores it, so the copy must not imply one.
  'profile.sms_alerts_on': (
    'On — MDRRMO text blasts are sent to your number.',
    'Naka-on — ipinapadala sa numero mo ang mga text blast ng MDRRMO.',
  ),
  'profile.sms_alerts_off': (
    'Off — you will not receive any MDRRMO text blast.',
    'Naka-off — hindi ka makakatanggap ng kahit anong text blast ng MDRRMO.',
  ),
  'profile.offline_materials': ('Offline materials', 'Mga Offline na Materyal'),
  'profile.offline_materials_desc': ('{n} saved · {size} used', '{n} naka-save · {size} ang nagamit'),
  'profile.logout': ('Log out', 'Mag-log Out'),
  'profile.offline_title': ('Offline Materials', 'Mga Offline na Materyal'),

  // The service catalogue, keyed on `tbl_services.code`. These used to come
  // from the API's `name_localized` / `description_localized`, resolved out of
  // a translations table in the database. They live here now: a label the
  // resident reads is a property of this app, not a row someone can edit into
  // a different language halfway through an emergency.
  //
  // The Tagalog below is the wording reviewed and confirmed by the project
  // owner on 2026-07-26, carried over unchanged.
  'service.ambulance-medical-response.name': (
    'Ambulance/Medical Response',
    'Ambulansya / Tugong Medikal',
  ),
  'service.ambulance-medical-response.desc': (
    'Emergency medical response and ambulance services.',
    'Pang-emerhensiyang tugong medikal at serbisyong ambulansya.',
  ),
  'service.relief-goods-distribution.name': (
    'Relief Goods Distribution',
    'Pamamahagi ng Relief Goods',
  ),
  'service.relief-goods-distribution.desc': (
    'Distribution of essential relief goods during disasters.',
    'Pamamahagi ng mahahalagang relief goods tuwing may sakuna.',
  ),
  // Same wording as `type.road.title` above, deliberately: the label must not
  // change spelling depending on which screen shows it.
  'service.road-clearing.name': ('Road Clearing', 'Paglinis ng Daan'),
  'service.road-clearing.desc': (
    'Clearing roads of debris and obstacles after natural calamities.',
    'Paglilinis ng mga daan mula sa debris at balakid pagkatapos ng kalamidad.',
  ),
  'service.power-line-repair.name': (
    'Power Line Repair',
    'Pagkumpuni ng Linya ng Kuryente',
  ),
  'service.power-line-repair.desc': (
    'Emergency repair of downed power lines.',
    'Pang-emerhensiyang pagkumpuni ng mga bumagsak na linya ng kuryente.',
  ),
  'service.debris-removal.name': ('Debris Removal', 'Pag-aalis ng Debris'),
  'service.debris-removal.desc': (
    'Removal of hazardous debris from public areas.',
    'Pag-aalis ng mapanganib na debris sa mga pampublikong lugar.',
  ),
  'service.animal-rescue.name': ('Animal Rescue', 'Pagsagip sa Hayop'),
  'service.animal-rescue.desc': (
    'Rescue operations for stranded or injured animals.',
    'Pagsagip sa mga naipit o nasugatang hayop.',
  ),
  'service.sandbagging.name': ('Sandbagging', 'Paglalagay ng Sandbags'),
  'service.sandbagging.desc': (
    'Provision and placement of sandbags for flood prevention.',
    'Paglalaan at paglalagay ng sandbags upang maiwasan ang baha.',
  ),
  'service.others.name': ('Others', 'Iba pa'),
  'service.others.desc': (
    'Something not covered by the services above.',
    'Isang bagay na hindi saklaw ng mga serbisyo sa itaas.',
  ),
};

String tr(bool filipino, String key) {
  final pair = _strings[key];
  if (pair == null) return key;
  return filipino ? pair.$2 : pair.$1;
}

/// A catalogue service's name in the resident's language.
///
/// Unlike [tr] this never returns the key on a miss. A service the MDRRMO adds
/// in the admin panel has no entry here, and showing "service.x.name" on the
/// tile a resident taps would be worse than showing its English name — so
/// [fallback], the API's `service_name`, is what an unknown code resolves to.
String serviceNameFor(bool filipino, String code, String fallback) {
  final pair = _strings['service.$code.name'];
  if (pair == null) return fallback;
  return filipino ? pair.$2 : pair.$1;
}

/// The blurb under a service, same rules as [serviceNameFor].
String serviceDescriptionFor(bool filipino, String code, String fallback) {
  final pair = _strings['service.$code.desc'];
  if (pair == null) return fallback;
  return filipino ? pair.$2 : pair.$1;
}
