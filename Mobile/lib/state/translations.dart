
const Map<String, (String, String)> _strings = {
  'common.cancel': ('Cancel', 'Kanselahin'),
  'common.submit_request': ('Send request', 'Ipadala ang kahilingan'),
  'common.view_details': ('View details', 'Tingnan ang Detalye'),
  'common.view_all': ('View all', 'Tingnan Lahat'),
  'common.cancel_request': ('Cancel request', 'Kanselahin ang Kahilingan'),
  'common.calling': ('Calling', 'Tumatawag sa'),
  'common.close': ('Close', 'Isara'),

  'request.responders_heading': ('Responders', 'Mga Tumutugon'),

  // A step says the same word as the status it stands for (status.* below).
  'timeline.submitted': ('Sent', 'Naipadala'),
  'timeline.review': ('Under review', 'Sinusuri'),
  'timeline.booked': ('Booked', 'Nakabook'),
  'timeline.responding': ('Responding', 'Tumutugon'),
  'timeline.approved': ('Approved', 'Aprubado'),
  'timeline.completed': ('Completed', 'Natapos'),
  'timeline.not_transported': ('Not transported', 'Hindi naihatid'),
  'timeline.cancelled': ('Cancelled by you', 'Kinansela mo'),
  'timeline.disapproved': ('Not approved', 'Hindi inaprubahan'),
  'timeline.awaiting': ('Waiting', 'Naghihintay'),
  'timeline.time_unknown': ('Time not recorded', 'Walang naitalang oras'),
  'timeline.today': ('Today', 'Ngayon'),

  'status.review': ('Under review', 'Sinusuri'),
  'status.booked': ('Booked', 'Nakabook'),
  'status.scheduled': ('Responding', 'Tumutugon'),
  'status.not_transported': ('Not transported', 'Hindi naihatid'),
  'status.approved': ('Approved', 'Aprubado'),
  'account.individual': ('Head of the Family', 'Pinuno ng Pamilya'),
  'account.organization': ('Organization', 'Organisasyon'),
  'account.barangay': ('Barangay', 'Barangay'),
  'awaiting.header': ('Your account', 'Iyong account'),
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
  // Only the resident can cancel: the admin status routes refuse 'Cancelled'.
  'status.cancelled': ('Cancelled by you', 'Kinansela mo'),
  'status.disapproved': ('Not approved', 'Hindi inaprubahan'),
  // Loans: the step a loan is on, in the same words as its progress steps.
  'status.ready_pickup': ('Ready to pick up', 'Handa nang kunin'),
  'status.out_delivery': ('Out for delivery', 'Ihahatid na'),
  'status.picked_up': ('Picked up', 'Nakuha na'),
  'status.delivered': ('Delivered', 'Naihatid na'),
  'status.returned': ('Returned', 'Naibalik'),

  'common.booking_overdue': (
    'Scheduled time has passed. Contact MDRRMO if you still need this.',
    'Nakalipas na ang naka-iskedyul na oras. Makipag-ugnayan sa MDRRMO kung kailangan mo pa rin ito.',
  ),

  'type.ambulance.title': ('Medical Transport / Ambulance', 'Medical Transport / Ambulansya'),
  'type.ambulance.subtitle': ('Patient transport', 'Paghahatid ng pasyente'),
  'type.transfer.title': ('Hospital Transfer', 'Paglilipat sa Ospital'),
  'type.transfer.subtitle': ('Incl. dialysis patients', 'Kasama ang mga dialysis patient'),
  'type.road.title': ('Road clearing', 'Paglinis ng daan'),
  'type.road.subtitle': ('Debris, fallen trees', 'Debris, natumbang puno'),
  'type.relief.title': ('Relief Goods', 'Tulong / Relief Goods'),
  'type.relief.subtitle': ('Assistance request', 'Kahilingan ng tulong'),
  'type.inquiry.title': ('Information Inquiry', 'Katanungan / Impormasyon'),
  'type.inquiry.subtitle': ('General question to MDRRMO', 'Pangkalahatang tanong sa MDRRMO'),

  'home.announcements': ('Announcements', 'Mga Abiso'),
  'home.brand': ('SERBIS · Echague MDRRMO', 'SERBIS · Echague MDRRMO'),
  'home.seal': ('MDRRMO seal', 'Selyo ng MDRRMO'),
  'home.greet.morning': ('Good morning,', 'Magandang umaga,'),
  'home.greet.afternoon': ('Good afternoon,', 'Magandang hapon,'),
  'home.greet.evening': ('Good evening,', 'Magandang gabi,'),
  'home.emergency': ('Emergency?', 'May emergency?'),
  'home.emergency.call': ('Call the hotline', 'Tumawag sa hotline'),
  'home.latest': ('Your latest request', 'Ang iyong pinakabagong kahilingan'),
  'home.open_in': ('Open in {tab}', 'Buksan sa {tab}'),
  'home.more_one': ('1 more in progress', '1 pa ang kasalukuyan'),
  'home.more_many': ('{n} more in progress', '{n} pa ang kasalukuyan'),
  'home.see_all': ('See all', 'Tingnan lahat'),
  'home.req.ambulance_to': ('Ambulance to {place}', 'Ambulansya papuntang {place}'),
  'home.services': ('What do you need?', 'Ano ang kailangan mo?'),
  'track.loans': ('Borrowed items', 'Mga hiniram na gamit'),
  'home.tile.transport': ('Patient transport', 'Paghatid ng pasyente'),
  'home.tile.transport_desc': ('Ambulance for check-ups and transfers', 'Ambulansya para sa check-up at paglipat'),
  'home.tile.borrow_desc': ('Wheelchairs, beds and more', 'Wheelchair, kama at iba pa'),
  'home.tile.guides_desc': ('First aid and disaster preparedness', 'Pangunang lunas at paghahanda sa sakuna'),
  'home.tile.all': ('All services', 'Lahat ng serbisyo'),
  'home.tile.all_desc': ('See everything MDRRMO offers', 'Tingnan ang lahat ng alok ng MDRRMO'),
  'nav.notifications_new': ('Notifications, new updates', 'Mga abiso, may bago'),
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
  'tab.unavailable_title': ('Not available', 'Hindi available'),
  'tab.unavailable_subtitle': ('Not offered right now', 'Hindi iniaalok sa ngayon'),
  'home.safety_guides': ('Safety guides', 'Mga Gabay sa Kaligtasan'),
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

  // ---- Shared feedback and loading (calm redesign) ----
  'feedback.try_again': ('Try again', 'Subukang muli'),
  'feedback.summary_one': ('1 field needs attention.', '1 field ang kailangang ayusin.'),
  'feedback.summary_many': ('{n} fields need attention.', '{n} field ang kailangang ayusin.'),
  'feedback.summary_hint': ('Check the fields marked in red.', 'Tingnan ang mga field na may pulang marka.'),
  // A form that stops at the first problem names it rather than counting.
  'feedback.check_one': ('Please check the highlighted field.', 'Pakitingnan ang field na may pulang marka.'),
  'feedback.check_field': ('Please check: {field}', 'Pakitingnan: {field}'),
  'status.updated': ('Updated', 'Na-update'),
  'loading.label': ('Loading', 'Naglo-load'),
  'loading.updating': ('Updating…', 'Ina-update…'),
  'loading.sending': ('Sending request…', 'Ipinapadala ang kahilingan…'),
  'upload.uploading': ('Uploading {name}', 'Ina-upload ang {name}'),
  'upload.progress': ('{done} of {total}', '{done} sa {total}'),

  // ---- Notifications ----
  'notif.subtitle': (
    'Advisories and updates on your requests',
    'Mga abiso at update sa iyong mga kahilingan',
  ),
  'notif.scope_note': (
    'Requests from the last 30 days. Reminders like equipment due dates come as phone notifications.',
    'Mga kahilingan sa nakaraang 30 araw. Ang mga paalala, gaya ng petsa ng pagbalik ng kagamitan, ay dumarating bilang notification sa telepono.',
  ),
  'notif.advisories': ('MDRRMO advisories', 'Mga abiso ng MDRRMO'),
  'notif.your_requests': ('Your requests', 'Iyong mga kahilingan'),
  'notif.new': ('New', 'Bago'),
  'notif.earlier': ('Earlier', 'Nakaraan'),
  'notif.unread': ('Not yet seen', 'Hindi pa nakikita'),
  'notif.requests_none': (
    'No updates on your requests in the last 30 days.',
    'Walang update sa iyong mga kahilingan sa nakaraang 30 araw.',
  ),
  'notif.adv_none': (
    'No advisories have been sent to you.',
    'Wala pang abisong ipinadala sa iyo.',
  ),
  'notif.adv_failed': (
    "Couldn't load advisories. This does not mean none were sent — check with your barangay.",
    'Hindi ma-load ang mga abiso. Hindi ito nangangahulugang wala — magtanong sa inyong barangay.',
  ),
  'notif.adv_failed_title': ("Couldn't load advisories", 'Hindi ma-load ang mga abiso'),
  'notif.adv_date_unknown': ('Date not recorded', 'Walang naitalang petsa'),

  // ---- Services ----
  'services.title': ('Services', 'Mga Serbisyo'),
  'services.subtitle': ('Request help from Echague MDRRMO', 'Humingi ng tulong sa Echague MDRRMO'),
  // The ambulance flow's step headings (C_Amb2-5); step 1 opens on the note.
  'ambulance.step2.title': ('Where are we going?', 'Saan tayo pupunta?'),
  'ambulance.step2.body': ('Pickup point and destination.', 'Kung saan susunduin at saan dadalhin.'),
  'ambulance.step3.title': ('How is the patient?', 'Kumusta ang pasyente?'),
  'ambulance.step3.body': ('Tell the crew what to expect.', 'Sabihin sa crew kung ano ang aasahan.'),
  'ambulance.step4.title': ('When do you need it?', 'Kailan mo ito kailangan?'),
  'ambulance.step4.body': ('Then add a photo of a valid ID.', 'Saka magdagdag ng larawan ng valid ID.'),
  'ambulance.step5.title': ('Check your request', 'Suriin ang iyong kahilingan'),
  'ambulance.step5.body': ('Tap Edit to change anything.', 'Pindutin ang I-edit para baguhin ang anuman.'),
  // The relief form's steps, each with its own heading (C_Rel1-3).
  'relief.step1.title': ('Who is this for?', 'Para kanino ito?'),
  'relief.step1.body': ('The household that needs relief goods.', 'Ang sambahayang nangangailangan ng relief goods.'),
  'relief.step2.title': ('What do you need?', 'Ano ang kailangan mo?'),
  'relief.step2.body': ('Choose one, then how it should reach you.', 'Pumili ng isa, at kung paano ito makakarating sa iyo.'),
  'relief.step3.title': ('Check and send', 'Suriin at ipadala'),
  'relief.step3.body': ('Add your ID, then review your answers.', 'Idagdag ang iyong ID, saka suriin ang iyong mga sagot.'),
  'services.search': ('Search services', 'Maghanap ng serbisyo'),
  'services.search_clear': ('Clear search', 'Burahin ang hinahanap'),
  'services.no_match': ('No services match "{q}"', 'Walang serbisyong tugma sa "{q}"'),
  'services.no_match_body': ('Try another word.', 'Subukan ang ibang salita.'),
  'services.load_failed': ("Couldn't load services", 'Hindi ma-load ang mga serbisyo'),
  // Category headings on the Services tab (tbl_services.category).
  'services.category.infrastructure': ('Infrastructure', 'Imprastraktura'),
  'services.category.rescue': ('Rescue', 'Pagsagip'),
  'services.category.relief': ('Relief', 'Tulong'),
  'services.category.programs': ('Programs', 'Mga Programa'),
  'services.category.medical': ('Medical', 'Medikal'),
  'services.category.other': ('Other requests', 'Iba pang kahilingan'),
  'services.notice_title': ('Non-life-threatening use only', 'Para sa hindi-banta-sa-buhay na sitwasyon lamang'),
  'services.notice_body': (
    'If you are experiencing a life-threatening emergency, contact authorities directly:',
    'Kung ikaw ay nasa banta-sa-buhay na emerhensiya, direktang tawagan ang mga awtoridad:',
  ),
  'notice.view_hotlines': ('View emergency hotlines', 'Tingnan ang mga emergency hotline'),
  'services.choose_type': ('Choose a request type', 'Pumili ng Uri ng Kahilingan'),
  'services.form.ambulance': ('Ambulance Request', 'Kahilingan ng Ambulansya'),
  'services.form.transfer': ('Hospital Transfer Request', 'Kahilingan ng Paglilipat sa Ospital'),
  'services.form.road': ('Road Clearing Request', 'Kahilingan ng Paglinis ng Daan'),
  'services.form.relief': ('Relief Goods Assistance', 'Kahilingan ng Relief Goods'),
  'services.form.inquiry': ('Information Inquiry', 'Katanungan / Impormasyon'),
  'services.confirm.title': ('Request sent', 'Naipadala ang kahilingan'),
  'services.confirm.view_track': ('View in Track', 'Tingnan sa Track'),
  'services.confirm.scheduled_for': ('Scheduled for', 'Naka-iskedyul para sa'),

  // ---- Guided form section labels ----
  // Group headings inside the four request forms, so related fields read as
  // one group instead of a flat, identically-spaced list of inputs.
  'form_section.patient': ('Patient', 'Pasyente'),
  'form_section.trip': ('Trip details', 'Detalye ng byahe'),
  'form_section.condition': ('Condition', 'Kondisyon'),
  'form_section.relatives': ('Patient / Relatives', 'Pasyente / Kamag-anak'),
  'form_section.location': ('Location', 'Lokasyon'),
  'form_section.description': ('Description', 'Paglalarawan'),
  'form_section.household': ('Household', 'Sambahayan'),
  'form_section.assistance': ('Assistance needed', 'Kailangang tulong'),
  'form_section.details': ('Details', 'Detalye'),
  'form_section.attachments': ('Attachments', 'Mga kalakip'),
  'form_section.event': ('Event', 'Kaganapan'),
  'form_section.certification': ('Certification', 'Sertipikasyon'),

  // ---- Ambulance scheduling ----
  'ambulance_schedule.title': ('When', 'Kailan'),
  'ambulance_schedule.asap': ('Now, when available', 'Ngayon, kapag may bakante'),
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

  'track.title': ('Track', 'Subaybay'),
  'track.subtitle': ('Your requests and borrowed items', 'Ang iyong mga kahilingan at hiniram na gamit'),
  'track.in_progress': ('In progress', 'Kasalukuyan'),
  'track.past': ('Past requests', 'Mga nakaraang kahilingan'),
  'track.show_older': ('Show older requests', 'Ipakita ang mas lumang kahilingan'),
  'track.empty': ('No requests yet', 'Wala pang kahilingan'),
  'track.empty_body': (
    'Requests you send and items you borrow will show up here.',
    'Lalabas dito ang mga kahilingang ipinadala mo at mga gamit na hiniram mo.',
  ),
  'track.sent': ('Sent {date}', 'Naipadala {date}'),
  'track.browse': ('Browse services', 'Tingnan ang mga serbisyo'),
  'track.call': ('Call MDRRMO', 'Tawagan ang MDRRMO'),
  'track.ref_pending': ('Reference number pending', 'Naghihintay ng reference number'),
  'track.box.review.next': (
    'MDRRMO is checking your request. Updates appear here and under the bell.',
    'Sinusuri ng MDRRMO ang iyong kahilingan. Lalabas dito at sa kampanilya ang mga update.',
  ),
  'track.box.booked.next': ('MDRRMO has confirmed your request.', 'Kinumpirma na ng MDRRMO ang iyong kahilingan.'),
  'track.box.booked.next_dated': ('Scheduled for {date}.', 'Naka-iskedyul sa {date}.'),
  'track.box.overdue.title': ('Scheduled time has passed', 'Lumipas na ang naka-iskedyul na oras'),
  'track.box.responding.next': (
    'Help is on the way. Call MDRRMO if anything changes.',
    'Papunta na ang tulong. Tawagan ang MDRRMO kung may magbago.',
  ),
  'track.box.approved.next': (
    'MDRRMO approved your request and will contact you with details.',
    'Inaprubahan ng MDRRMO ang iyong kahilingan at makikipag-ugnayan sa iyo.',
  ),
  'track.box.completed.next': ('This request is done.', 'Tapos na ang kahilingang ito.'),
  'track.box.cancelled.next': ('You cancelled this request.', 'Kinansela mo ang kahilingang ito.'),
  'track.box.disapproved.next': ('MDRRMO could not approve this request.', 'Hindi naaprubahan ng MDRRMO ang kahilingang ito.'),
  'track.box.reason': ("MDRRMO's reason: {reason}", 'Dahilan ng MDRRMO: {reason}'),

  'library.title': ('Safety library', 'Aklatan ng kaligtasan'),
  'library.subtitle': ('First aid, preparedness and hotlines', 'Pangunang lunas at paghahanda'),
  'library.hotlines': ('Emergency hotlines', 'Mga hotline ng emerhensiya'),
  'library.first_aid': ('Basic first aid', 'Pangunahing lunas (First Aid)'),
  'library.preparedness': ('Disaster preparedness', 'Paghahanda sa sakuna'),
  'library.documents': ('MDRRMO documents', 'Mga dokumento ng MDRRMO'),
  'article.offline_title': ('Available offline', 'Magagamit kahit offline'),
  'library.police': ('Police (PNP)', 'Pulis (PNP)'),
  'library.fire': ('Fire (BFP)', 'Bumbero (BFP)'),
  'library.national_emergency': ('National Emergency', 'Pambansang Emerhensiya'),

  'profile.title': ('My profile', 'Aking profile'),
  'profile.save_hint': ('Change a detail above to save.', 'Baguhin ang isang detalye sa itaas para mai-save.'),
  'profile.subtitle': ('Your account and settings', 'Ang iyong account at mga setting'),
  'profile.offline_subtitle': ('Available without internet', 'Magagamit kahit walang internet'),
  'profile.photo': ('Profile photo', 'Larawan sa profile'),
  'profile.photo_change': ('Change profile photo', 'Palitan ang larawan sa profile'),
  'profile.photo_choose': ('Choose a photo', 'Pumili ng larawan'),
  'profile.photo_remove': ('Remove photo', 'Alisin ang larawan'),
  'profile.edit_details': ('Edit my details', 'I-edit ang aking detalye'),
  'profile.group_name': ('Name', 'Pangalan'),
  'profile.group_contact': ('Contact', 'Contact'),
  'profile.group_address': ('Address', 'Address'),
  'profile.phone_change_link': ('Change', 'Palitan'),
  // Moving changes where new requests and texts go, never the old requests,
  // so the dialog says both halves.
  'profile.barangay_confirm_title': ('Change your barangay?', 'Palitan ang iyong barangay?'),
  'profile.barangay_confirm_body': (
    'Requests you already filed stay with {old}. New requests and MDRRMO texts go to {new}.',
    'Mananatili sa {old} ang mga kahilingang naisumite mo na. Mapupunta sa {new} ang mga bagong kahilingan at text ng MDRRMO.',
  ),
  'profile.barangay_confirm_keep': ('Go back', 'Bumalik'),
  'profile.barangay_confirm_go': ('Change barangay', 'Palitan ang barangay'),
  'barangay.label': ('Barangay', 'Barangay'),
  'barangay.loading': ('Loading barangays…', 'Nilo-load ang mga barangay…'),
  'barangay.failed': ("Couldn't load barangays.", 'Hindi ma-load ang mga barangay.'),
  'barangay.retry': ('Retry', 'Subukan muli'),
  'barangay.search': ('Search your barangay', 'Hanapin ang iyong barangay'),
  'barangay.required': ('Select your barangay', 'Piliin ang iyong barangay'),
  'profile.contact_mdrrmo': ('Contact MDRRMO', 'Tawagan ang MDRRMO'),
  'profile.contact_mdrrmo_desc': ('Call for help with your account', 'Tumawag para sa tulong sa iyong account'),
  'profile.first_name': ('First name', 'Pangalan'),
  'profile.middle_name_optional': ('Middle name (optional)', 'Gitnang pangalan (opsyonal)'),
  'profile.last_name': ('Last name', 'Apelyido'),
  'profile.street_address': ('Street / purok (optional)', 'Kalye / purok (opsyonal)'),
  'profile.phone': ('Mobile number', 'Numero ng Telepono'),
  'profile.save': ('Save changes', 'I-save ang pagbabago'),
  'profile.saved': ('Profile updated.', 'Na-update ang profile.'),
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
  'profile.settings': ('Settings', 'Mga setting'),
  'profile.language': ('Language', 'Wika'),
  'profile.sms_alerts': ('MDRRMO text alerts', 'Mga text alert ng MDRRMO'),
  // The switch controls one thing: whether SmsController's recipient query
  // includes this number. The word restates the switch, so it is not read by
  // colour alone; the note under an Off switch says what that costs.
  'profile.sms_alerts_on': ('On', 'Naka-on'),
  'profile.sms_alerts_off': ('Off', 'Naka-off'),
  'profile.sms_alerts_off_note': (
    "You won't get flood or evacuation texts from MDRRMO.",
    'Hindi ka makakatanggap ng text tungkol sa baha o paglikas mula sa MDRRMO.',
  ),
  // Shown while the switch is On but the account is still pending: blasts
  // skip accounts MDRRMO has not activated.
  'profile.sms_alerts_pending_note': (
    'Text alerts start once MDRRMO activates your account.',
    'Magsisimula ang mga text alert kapag na-activate na ng MDRRMO ang iyong account.',
  ),
  'profile.offline_materials': ('Offline materials', 'Mga offline na materyal'),
  'profile.offline_materials_desc': ('{n} saved · {size} used', '{n} naka-save · {size} ang nagamit'),
  'profile.logout': ('Log out', 'Mag-log out'),
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
    'Ambulance/medical response',
    'Ambulansya / tugong medikal',
  ),
  'service.ambulance-medical-response.desc': (
    'Ambulance transport for non-life-threatening medical needs.',
    'Paghahatid ng ambulansya para sa mga pangangailangang medikal na hindi nagbabanta sa buhay.',
  ),
  'service.relief-goods-distribution.name': (
    'Relief goods distribution',
    'Pamamahagi ng relief goods',
  ),
  'service.relief-goods-distribution.desc': (
    'Distribution of essential relief goods during disasters.',
    'Pamamahagi ng mahahalagang relief goods tuwing may sakuna.',
  ),
  // Same wording as `type.road.title` above, deliberately: the label must not
  // change spelling depending on which screen shows it.
  'service.road-clearing.name': ('Road clearing', 'Paglinis ng daan'),
  'service.road-clearing.desc': (
    'Clearing roads of debris and obstacles after natural calamities.',
    'Paglilinis ng mga daan mula sa debris at balakid pagkatapos ng kalamidad.',
  ),
  'service.power-line-repair.name': (
    'Power line repair',
    'Pagkumpuni ng linya ng kuryente',
  ),
  'service.power-line-repair.desc': (
    'Emergency repair of downed power lines.',
    'Pang-emerhensiyang pagkumpuni ng mga bumagsak na linya ng kuryente.',
  ),
  'service.debris-removal.name': ('Debris removal', 'Pag-aalis ng debris'),
  'service.debris-removal.desc': (
    'Removal of hazardous debris from public areas.',
    'Pag-aalis ng mapanganib na debris sa mga pampublikong lugar.',
  ),
  'service.sandbagging.name': ('Sandbagging', 'Paglalagay ng sandbags'),
  'service.sandbagging.desc': (
    'Provision and placement of sandbags for flood prevention.',
    'Paglalaan at paglalagay ng sandbags upang maiwasan ang baha.',
  ),
  'service.others.name': ('Others', 'Iba pa'),
  'service.others.desc': (
    'Something not covered by the services above.',
    'Isang bagay na hindi saklaw ng mga serbisyo sa itaas.',
  ),
  // The programs. English matches tbl_services; the Filipino is new (2026-09-30).
  'service.drrm-trainings-and-seminars.name': ('DRRM trainings and seminars', 'Mga pagsasanay at seminar sa DRRM'),
  'service.drrm-trainings-and-seminars.desc': (
    'Disaster risk reduction and management trainings and seminars (IEC) for barangays and organizations.',
    'Mga pagsasanay at seminar (IEC) sa disaster risk reduction and management para sa mga barangay at organisasyon.',
  ),
  'service.simulation-drills-nsed.name': ('Simulation drills / NSED', 'Mga simulation drill / NSED'),
  'service.simulation-drills-nsed.desc': (
    'Simulation drills, including the Nationwide Simultaneous Earthquake Drill (NSED), for barangays and organizations.',
    'Mga simulation drill, kasama ang Nationwide Simultaneous Earthquake Drill (NSED), para sa mga barangay at organisasyon.',
  ),
  'service.mdrrmo-certification.name': ('MDRRMO certification', 'Sertipikasyon ng MDRRMO'),
  'service.mdrrmo-certification.desc': ('Certification issued by the MDRRMO.', 'Sertipikasyong inilalabas ng MDRRMO.'),
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

/// Filipino for an English UI string that has no key of its own: the form
/// field labels, hints and choice options (which are also the English values
/// sent to MDRRMO), and the Borrow tab. Keyed on the English text so the value
/// sent to the server never changes with the language. Missing entries stay
/// English. `{n}`, `{item}`, `{time}`, `{list}` and `{label}` are filled in by
/// the caller.
const Map<String, String> _filipinoOf = {
  // Borrow tab
  "Couldn't load equipment": 'Hindi ma-load ang mga kagamitan',
  'My requests ({n})': 'Aking mga kahilingan ({n})',
  'See available equipment': 'Tingnan ang puwedeng hiramin',
  'Borrow Equipment': 'Manghiram ng Kagamitan',
  'Available': 'Puwedeng hiramin',
  'Nothing available right now': 'Walang puwedeng hiramin sa ngayon',
  'MDRRMO has no equipment listed for loan at the moment.':
      'Walang kagamitang nakalista ang MDRRMO na puwedeng hiramin sa ngayon.',
  'No borrow requests yet': 'Wala ka pang kahilingang manghiram',
  'Items you request from the Available tab will show up here.':
      'Lalabas dito ang mga kagamitang hiniling mo mula sa Puwedeng hiramin.',
  'None available right now': 'Wala sa ngayon',
  '{n} available': '{n} ang puwedeng hiramin',
  'Borrow': 'Hiramin',
  'Need something else?': 'May iba ka pang kailangan?',
  'Request': 'Humiling',
  'Sending...': 'Ipinapadala...',
  'Filed': 'Naisumite',
  'Filed {time}': 'Naisumite {time}',
  'Handover photos': 'Mga larawan ng pag-abot',
  'Released': 'Naibigay',
  'Returned': 'Naibalik',
  'Tell MDRRMO what you need this for.': 'Sabihin sa MDRRMO kung para saan ito.',
  'Name the item you need.': 'Isulat ang pangalan ng kagamitang kailangan mo.',
  'Where should MDRRMO deliver it?': 'Saan ito ihahatid ng MDRRMO?',
  'Something went wrong. Please try again.': 'May nangyaring mali. Pakisubukang muli.',
  'Request another item': 'Humiling ng ibang kagamitan',
  'MDRRMO will check whether they can lend this.': 'Titingnan ng MDRRMO kung maipapahiram nila ito.',
  '{n} available to borrow': '{n} ang puwedeng hiramin',
  'What do you need?': 'Ano ang kailangan mo?',
  'e.g. Portable generator': 'hal. Portable generator',
  'What do you need it for?': 'Para saan mo ito kailangan?',
  'e.g. Barangay flood drill this weekend': 'hal. Flood drill ng barangay ngayong weekend',
  'MDRRMO reviews this before approving the loan.': 'Susuriin ito ng MDRRMO bago aprubahan ang paghiram.',
  'How will you get it?': 'Paano mo ito makukuha?',
  'Pickup': 'Kukunin',
  'Delivery': 'Ihahatid',
  'Same as my address': 'Kapareho ng aking address',
  'Delivery address': 'Address ng paghahatid',
  'House number, street, barangay': 'Numero ng bahay, kalye, barangay',
  'Request this item': 'Hilingin ang kagamitang ito',
  'Request filed for {item}. MDRRMO will review it.':
      'Naisumite ang kahilingan para sa {item}. Susuriin ito ng MDRRMO.',
  // Borrowing statuses: the words are status.* keys; this is the line after.
  'MDRRMO is checking if they can lend this. Updates appear here and under the bell.':
      'Tinitingnan ng MDRRMO kung maipapahiram nila ito. Lalabas dito at sa kampanilya ang mga update.',
  // Due and schedule countdowns
  '{n} day overdue': 'Lampas na ng {n} araw',
  '{n} days overdue': 'Lampas na ng {n} araw',
  'Due today': 'Ibalik ngayong araw',
  'Due tomorrow': 'Ibalik bukas',
  'Due in {n} days': 'Ibalik sa loob ng {n} araw',
  'Scheduled today': 'Naka-iskedyul ngayong araw',
  'Scheduled tomorrow': 'Naka-iskedyul bukas',
  'Scheduled in {n} days': 'Naka-iskedyul sa loob ng {n} araw',
  // Home and Services
  'Wheelchairs, stretchers & more': 'Wheelchair, stretcher at iba pa',
  // Request form
  'Please attach a photo of your valid ID before submitting.':
      'Maglakip muna ng larawan ng iyong valid ID bago magsumite.',
  'Please choose a preferred date.': 'Pumili ng nais na petsa.',
  'Choose a date at least {n} days from today.': 'Pumili ng petsang hindi bababa sa {n} araw mula ngayon.',
  'Please attach your request letter before submitting.':
      'Maglakip muna ng iyong request letter bago magsumite.',
  'Request letter (required)': 'Request letter (kailangan)',
  'Supporting document (optional)': 'Karagdagang dokumento (opsyonal)',
  'PDF, JPG or PNG, up to 4 MB': 'PDF, JPG o PNG, hanggang 4 MB',
  'A photo of a nearby landmark. JPG or PNG, up to 4 MB': 'Larawan ng kalapit na palatandaan. JPG o PNG, hanggang 4 MB',
  'Choose another file': 'Pumili ng ibang file',
  // Ambulance labels, as C_Amb1-3 word them.
  'Full name': 'Buong pangalan',
  'Relative to contact': 'Kamag-anak na tatawagan',
  "We'll call them if we can't reach the patient.": 'Tatawagan namin sila kung hindi makontak ang pasyente.',
  'Helps the driver find you.': 'Nakakatulong para mahanap ka ng driver.',
  'Done': 'Tapos na',
  'To an address': 'Sa isang address',
  'Up to {n}': 'Hanggang {n}',
  'Valid ID (required)': 'Valid ID (kailangan)',
  'Site photo (optional)': 'Larawan ng lugar (opsyonal)',
  'Landmark (optional)': 'Palatandaan (opsyonal)',
  'e.g. beside the chapel': 'hal. katabi ng kapilya',
  'Remove {label}': 'Alisin ang {label}',
  // Ambulance form
  'Patient is myself': 'Ako ang pasyente',
  'Patient name': 'Pangalan ng pasyente',
  'e.g. Maria Santos': 'hal. Maria Santos',
  'Age': 'Edad',
  'e.g. 62': 'hal. 62',
  'Patient address': 'Address ng pasyente',
  'Contact number': 'Numero ng telepono',
  'From': 'Mula sa',
  'Search or pick Other': 'Maghanap o piliin ang Iba pa',
  'Pickup location': 'Lugar ng pagsundo',
  'e.g. Purok 3, San Fabian': 'hal. Purok 3, San Fabian',
  'To': 'Papunta sa',
  'Search or pick Others': 'Maghanap o piliin ang Iba pa',
  'Destination': 'Destinasyon',
  'e.g. Echague District Hospital': 'hal. Echague District Hospital',
  "Briefly describe the patient's condition": 'Ilarawan nang maikli ang kalagayan ng pasyente',
  'Relative {n}': 'Kamag-anak {n}',
  'e.g. Juan Dela Cruz': 'hal. Juan Dela Cruz',
  'Remove relative {n}': 'Alisin ang kamag-anak {n}',
  'Add another relative': 'Magdagdag pa ng kamag-anak',
  // Ambulance steps
  'Step {n} of {total}': 'Hakbang {n} sa {total}',
  'Patient': 'Pasyente',
  'Trip': 'Byahe',
  'Condition': 'Kondisyon',
  'Schedule and ID': 'Iskedyul at ID',
  'Review': 'Suriin',
  'Household': 'Sambahayan',
  'Assistance and delivery': 'Tulong at paghahatid',
  'ID and review': 'ID at pagsusuri',
  'Next: {step}': 'Susunod: {step}',
  'Edit': 'I-edit',
  'Not given': 'Hindi ibinigay',
  'Your registered barangay': 'Ang nakarehistro mong barangay',
  'Relatives': 'Mga kamag-anak',
  'Destination name': 'Pangalan ng destinasyon',
  'Enter the patient name.': 'Ilagay ang pangalan ng pasyente.',
  'Enter where the ambulance should go.': 'Ilagay kung saan pupunta ang ambulansya.',
  'Name at least one relative going with the patient.': 'Maglagay ng kahit isang kamag-anak na sasama sa pasyente.',
  'Attach a photo of a valid ID.': 'Maglakip ng larawan ng valid ID.',
  'Discard this request?': 'Itapon ang kahilingang ito?',
  'What you entered will be cleared.': 'Mabubura ang mga inilagay mo.',
  'Keep editing': 'Ituloy ang pag-edit',
  'Discard': 'Itapon',
  'Search barangay': 'Maghanap ng barangay',
  'Full address': 'Buong address',
  'House no., street, barangay, town': 'Numero ng bahay, kalye, barangay, bayan',
  'Purok / street': 'Purok / kalye',
  'e.g. Purok 3': 'hal. Purok 3',
  'Other': 'Iba pa',
  'Others': 'Iba pa',
  // Road, relief, generic and program forms
  'Pickup or delivery': 'Kukunin o ihahatid',
  'How should this reach you?': 'Paano ito makakarating sa iyo?',
  'Purok / street, barangay': 'Purok / kalye, barangay',
  'Location / road name': 'Lokasyon / pangalan ng daan',
  'e.g. Brgy. Malasin – Provincial Road': 'hal. Brgy. Malasin – Provincial Road',
  'Obstruction type': 'Uri ng harang',
  'Fallen tree / branches': 'Natumbang puno / sanga',
  'Flooding / silt': 'Baha / putik',
  'Landslide debris': 'Debris ng landslide',
  'Description': 'Paglalarawan',
  "Describe the obstruction and how it's affecting access":
      'Ilarawan ang harang at kung paano nito naaapektuhan ang pagdaan',
  'Household head name': 'Pangalan ng pinuno ng pamilya',
  'Address': 'Address',
  'Household size': 'Bilang ng kasambahay',
  'e.g. 5': 'hal. 5',
  'Count everyone who regularly eats and sleeps in this household, including yourself.':
      'Bilangin ang lahat ng regular na kumakain at natutulog sa bahay na ito, kasama ka.',
  'Type of assistance needed': 'Uri ng tulong na kailangan',
  'Food packs': 'Mga food pack',
  'Hygiene kits': 'Mga hygiene kit',
  'Drinking water': 'Inuming tubig',
  'Temporary shelter materials': 'Materyales para sa pansamantalang tirahan',
  'Preferred date': 'Nais na petsa',
  'Location': 'Lokasyon',
  'Venue, purok, barangay': 'Lugar, purok, barangay',
  'Expected number of participants': 'Inaasahang bilang ng kalahok',
  'e.g. 40': 'hal. 40',
  'Training topic': 'Paksa ng pagsasanay',
  'e.g. Basic life support, fire safety': 'hal. Basic life support, fire safety',
  'Drill type': 'Uri ng drill',
  'Earthquake (NSED)': 'Lindol (NSED)',
  'Fire': 'Sunog',
  'Flood': 'Baha',
  'Certification type': 'Uri ng sertipikasyon',
  'Which certificate do you need?': 'Anong sertipiko ang kailangan mo?',
  'Purpose': 'Layunin',
  'What is the certificate for?': 'Para saan ang sertipiko?',
  'Details': 'Mga detalye',
  'Describe what you need and where': 'Ilarawan kung ano ang kailangan mo at saan',
  'Choose a date': 'Pumili ng petsa',
  'not chosen': 'hindi pa napili',
  'At least {n} days from today, so MDRRMO can plan.':
      'Hindi bababa sa {n} araw mula ngayon, para makapaghanda ang MDRRMO.',
  // Ambulance form (redesign)
  'Request an ambulance': 'Humiling ng ambulansya',
  'Non-emergency medical transport': 'Medikal na sasakyan para sa hindi emergency',
  'Back': 'Bumalik',
  '6 short sections': '6 maikling bahagi',
  'Required': 'Kailangan',
  '{n} of 6 sections answered': '{n} sa 6 na bahagi ang nasagutan',
  'Who needs the ambulance?': 'Sino ang nangangailangan ng ambulansya?',
  'Myself': 'Ako mismo',
  'Someone else': 'Ibang tao',
  'Lives at my address': 'Nakatira sa aking address',
  'Fills in barangay and purok for you': 'Pupunan ang barangay at purok para sa iyo',
  'Barangay': 'Barangay',
  'Pick up at my address': 'Susunduin sa aking address',
  'Pick up from': 'Susunduin mula sa',
  'Take patient to': 'Dadalhin ang pasyente sa',
  'Symptoms, since when, and whether the patient can walk': 'Mga sintomas, mula kailan, at kung nakakalakad ang pasyente',
  'Plain words are fine.': 'Puwede ang simpleng salita.',
  'Relatives going with the patient': 'Mga kamag-anak na sasama sa pasyente',
  'MDRRMO will confirm the unit and time': 'Kukumpirmahin ng MDRRMO ang unit at oras',
  'Pick a date and time': 'Pumili ng petsa at oras',
  'Valid ID': 'Valid ID',
  'Request letter': 'Request letter',
  'JPG or PNG, up to 2 MB': 'JPG o PNG, hanggang 2 MB',
  'Take photo': 'Kumuha ng larawan',
  'Choose file': 'Pumili ng file',
  'You can track this request in the Track tab.': 'Masusubaybayan mo ang kahilingang ito sa Track tab.',
  // Borrow screen (redesign)
  'Borrow equipment': 'Manghiram ng kagamitan',
  'Free loans from Echague MDRRMO': 'Libreng pahiram mula sa Echague MDRRMO',
  'My requests': 'Aking mga kahilingan',
  // Borrow: My requests
  'In progress': 'Kasalukuyan',
  'Past requests': 'Mga nakaraang kahilingan',
  'Reason:': 'Dahilan:',
  'Call MDRRMO': 'Tawagan ang MDRRMO',
  'Borrow again': 'Hiramin muli',
  'MDRRMO will review your request and update it here.': 'Susuriin ng MDRRMO ang iyong kahilingan at ia-update ito rito.',
  'MDRRMO will bring it to your address.': 'Dadalhin ito ng MDRRMO sa iyong address.',
  'Pick it up at the MDRRMO office.': 'Kunin ito sa opisina ng MDRRMO.',
  "Return it to MDRRMO when you're done.": 'Ibalik ito sa MDRRMO kapag tapos ka na.',
  'Please return it by {date}.': 'Pakibalik ito bago o sa {date}.',
  'Thank you for returning it.': 'Salamat sa pagbabalik nito.',
  "MDRRMO's reason:": 'Dahilan ng MDRRMO:',
  'MDRRMO could not approve this request.': 'Hindi naaprubahan ng MDRRMO ang kahilingang ito.',
  '{n} pending': '{n} naghihintay',
  'Search equipment': 'Maghanap ng kagamitan',
  'No equipment matches your search': 'Walang kagamitang tugma sa hinanap mo',
  'Try another name, or ask for it below.': 'Subukan ang ibang pangalan, o hilingin ito sa ibaba.',
  'Only 1 left': 'Isa na lang ang natitira',
  'Unavailable': 'Hindi available',
  'Ask for an item that\'s not on this list': 'Humiling ng gamit na wala sa listahang ito',
  'For equipment not on the list': 'Para sa kagamitang wala sa listahan',
  'Close': 'Isara',
  'Quantity': 'Dami',
  'How many you need': 'Ilan ang kailangan mo',
  'Decrease quantity': 'Bawasan ang dami',
  'Increase quantity': 'Dagdagan ang dami',
  'What will you use it for?': 'Saan mo ito gagamitin?',
  'At MDRRMO office': 'Sa opisina ng MDRRMO',
  'To your address': 'Sa iyong address',
  'Deliver to': 'Ihatid sa',
  'MDRRMO will check if they can lend this and notify you.': 'Titingnan ng MDRRMO kung maipapahiram ito at aabisuhan ka.',
  'Send request': 'Ipadala ang kahilingan',
};

/// [english] in Filipino when [filipino] and a translation exists, else as is.
String trEn(bool filipino, String english) => filipino ? (_filipinoOf[english] ?? english) : english;
