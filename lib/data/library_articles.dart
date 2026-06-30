import 'package:flutter/material.dart';
import '../theme/app_theme.dart';

/// A section of an article — a heading, optional body paragraph, and an
/// optional list of bullet points.
class ArticleSection {
  final String heading;
  final String? body;
  final List<String>? bullets;

  const ArticleSection({required this.heading, this.body, this.bullets});
}

/// A readable safety/disaster-preparedness material shown in the Library.
/// Tapping a library item opens an [ArticleReaderScreen] showing these
/// sections in the resident's selected language (English or Filipino).
class LibraryArticle {
  final String title;
  final String titleFil;
  final String subtitle;
  final String subtitleFil;
  final IconData icon;
  final Color iconBg;
  final Color iconFg;
  final List<ArticleSection> sections;
  final List<ArticleSection> sectionsFil;

  const LibraryArticle({
    required this.title,
    required this.titleFil,
    required this.subtitle,
    required this.subtitleFil,
    required this.icon,
    required this.iconBg,
    required this.iconFg,
    required this.sections,
    required this.sectionsFil,
  });

  String titleFor({required bool filipino}) => filipino ? titleFil : title;
  String subtitleFor({required bool filipino}) => filipino ? subtitleFil : subtitle;
  List<ArticleSection> sectionsFor({required bool filipino}) => filipino ? sectionsFil : sections;
}

/// All readable materials available in the Safety Library, grouped by the
/// section they appear in. Content is general safety guidance — replace or
/// expand with official MDRRMO-reviewed materials when available.
final libraryArticles = <String, LibraryArticle>{
  'cpr': const LibraryArticle(
    title: 'CPR — Step by Step',
    titleFil: 'CPR — Hakbang-hakbang',
    subtitle: 'Adult & child guide',
    subtitleFil: 'Gabay para sa matanda at bata',
    icon: Icons.bolt_rounded,
    iconBg: AppColors.red50,
    iconFg: AppColors.red600,
    sections: [
      ArticleSection(
        heading: 'Before you start',
        body: 'Check the scene for safety, then check if the person responds. '
            'Tap their shoulder and shout. If there is no response and they are '
            'not breathing normally, call for help immediately and ask someone '
            'to bring an AED if one is available.',
      ),
      ArticleSection(
        heading: 'Hand placement',
        bullets: [
          'Place the heel of one hand on the center of the chest, between the nipples.',
          'Place your other hand on top and interlock your fingers.',
          'Keep your arms straight and shoulders directly above your hands.',
        ],
      ),
      ArticleSection(
        heading: 'Chest compressions',
        bullets: [
          'Push hard and fast — at least 5 cm (2 in) deep for adults.',
          'Aim for a rate of 100–120 compressions per minute.',
          'Allow the chest to fully recoil between compressions.',
          'Give 30 compressions, then 2 rescue breaths if trained to do so.',
        ],
      ),
      ArticleSection(
        heading: 'For children and infants',
        body: 'Use one hand (or two fingers for infants) and compress about '
            'one-third the depth of the chest. The rate and ratio of '
            'compressions to breaths stays the same as for adults.',
      ),
      ArticleSection(
        heading: 'When to stop',
        body: 'Continue CPR until the person starts breathing normally, '
            'emergency responders arrive and take over, or you are too '
            'exhausted to continue safely.',
      ),
    ],
    sectionsFil: [
      ArticleSection(
        heading: 'Bago Magsimula',
        body: 'Suriin muna kung ligtas ang paligid, tapos tingnan kung tumutugon '
            'ang biktima. Tapikin ang balikat at tawagin nang malakas. Kung '
            'walang tugon at hindi normal ang paghinga, humingi agad ng tulong '
            'at magpadala ng AED kung meron.',
      ),
      ArticleSection(
        heading: 'Pwesto ng Kamay',
        bullets: [
          'Ilagay ang bahagi ng palad sa gitna ng dibdib, sa pagitan ng dalawang utong.',
          'Ipatong ang isang kamay sa ibabaw ng kamay na nasa dibdib at ikawing ang mga daliri.',
          'Panatilihing tuwid ang braso at ang balikat ay direktang ibabaw ng kamay.',
        ],
      ),
      ArticleSection(
        heading: 'Compressions sa Dibdib',
        bullets: [
          'Itulak nang malakas at mabilis — hindi bababa sa 5 cm (2 in) ang lalim para sa matatanda.',
          'Layunin ang 100–120 compressions kada minuto.',
          'Hayaang bumalik nang husto ang dibdib sa pagitan ng bawat compression.',
          'Gumawa ng 30 compressions, sundan ng 2 rescue breaths kung sanay.',
        ],
      ),
      ArticleSection(
        heading: 'Para sa Mga Bata at Sanggol',
        body: 'Gumamit ng isang kamay (o dalawang daliri para sa sanggol) at '
            'i-compress ang humigit-kumulang ikatlong bahagi ng lalim ng dibdib. '
            'Pareho lang ang bilis at ratio ng compressions sa breaths gaya ng '
            'sa matatanda.',
      ),
      ArticleSection(
        heading: 'Kailan Dapat Tumigil',
        body: 'Ipagpatuloy ang CPR hanggang sa magsimulang huminga nang normal '
            'ang biktima, may dumating na rescue team na papalit, o kapag pagod '
            'na kayo at hindi na kayang ipagpatuloy nang ligtas.',
      ),
    ],
  ),
  'burns': const LibraryArticle(
    title: 'Treating Burns',
    titleFil: 'Pag-alaga sa Paso (Burns)',
    subtitle: 'Minor & severe burns',
    subtitleFil: 'Magaan at malalang paso',
    icon: Icons.warning_amber_rounded,
    iconBg: AppColors.amber50,
    iconFg: AppColors.amber600,
    sections: [
      ArticleSection(
        heading: 'First steps for any burn',
        bullets: [
          'Remove the person from the source of the burn.',
          'Cool the burn under cool (not ice-cold) running water for 10–20 minutes.',
          'Remove tight clothing and jewelry near the burn before swelling starts.',
          'Cover loosely with a clean, non-fluffy cloth or dressing.',
        ],
      ),
      ArticleSection(
        heading: 'Minor burns',
        body: 'Small burns that only affect the top layer of skin (redness, '
            'mild swelling, pain) can usually be treated at home. After '
            'cooling, apply a clean dressing and avoid breaking any blisters '
            'that form.',
      ),
      ArticleSection(
        heading: 'Seek medical help if...',
        bullets: [
          'The burn is larger than the person\'s palm.',
          'It is on the face, hands, feet, joints, or genitals.',
          'The skin looks white, leathery, or charred.',
          'The burn was caused by chemicals, electricity, or an explosion.',
          'The person is a child, elderly, or has other medical conditions.',
        ],
      ),
      ArticleSection(
        heading: 'What not to do',
        bullets: [
          'Do not apply ice directly — it can damage tissue further.',
          'Do not apply butter, toothpaste, or other home remedies.',
          'Do not pop blisters, as this increases infection risk.',
        ],
      ),
    ],
    sectionsFil: [
      ArticleSection(
        heading: 'Unang Gagawin sa Anumang Paso',
        bullets: [
          'Ilayo ang biktima sa pinagmulan ng init o sunog.',
          'Patubigan ang sugat ng malamig (hindi yelo) na tubig sa loob ng 10–20 minuto.',
          'Tanggalin ang masikip na damit at alahas malapit sa paso bago ito mamaga.',
          'Takpan nang maluwag gamit ang malinis na tela o dressing na hindi maraming hibla.',
        ],
      ),
      ArticleSection(
        heading: 'Magaan na Paso',
        body: 'Maliliit na pasong umaapekto lamang sa pinakaibabaw na balat '
            '(pamumula, bahagyang pamamaga, sakit) ay maaaring gamutin sa '
            'bahay. Pagkatapos patubigan, takpan ng malinis na dressing at '
            'iwasang butasin ang anumang paltos na mabuo.',
      ),
      ArticleSection(
        heading: 'Kumonsulta Agad sa Doktor Kung...',
        bullets: [
          'Mas malaki ang paso kaysa sa palad ng biktima.',
          'Nasa mukha, kamay, paa, kasukasuan, o ari ito.',
          'Ang balat ay mukhang puti, parang katad, o nasunog.',
          'Sanhi ng kemikal, kuryente, o pagsabog ang paso.',
          'Bata, matanda, o may iba pang sakit ang biktima.',
        ],
      ),
      ArticleSection(
        heading: 'Mga Bawal Gawin',
        bullets: [
          'Huwag maglagay ng yelo direkta — maaari pa itong magpalala ng sugat.',
          'Huwag maglagay ng mantikilya, toothpaste, o ibang lunas-bahay.',
          'Huwag butasin ang paltos dahil madaling magka-impeksyon.',
        ],
      ),
    ],
  ),
  'wound_care': const LibraryArticle(
    title: 'Wound Care Basics',
    titleFil: 'Mga Pangunahing Hakbang sa Pag-alaga ng Sugat',
    subtitle: 'Cleaning & dressing',
    subtitleFil: 'Paglilinis at pagtatapal',
    icon: Icons.shield_outlined,
    iconBg: AppColors.blue50,
    iconFg: AppColors.blue600,
    sections: [
      ArticleSection(
        heading: 'Step 1 — Stop the bleeding',
        body: 'Apply firm, direct pressure to the wound with a clean cloth or '
            'dressing. Keep pressing until the bleeding slows or stops. If '
            'blood soaks through, add another layer on top rather than '
            'removing the first one.',
      ),
      ArticleSection(
        heading: 'Step 2 — Clean the wound',
        bullets: [
          'Wash your hands before touching the wound.',
          'Rinse the wound gently with clean water to remove dirt and debris.',
          'Avoid using alcohol or hydrogen peroxide directly on open wounds — they can damage healthy tissue.',
        ],
      ),
      ArticleSection(
        heading: 'Step 3 — Dress the wound',
        bullets: [
          'Apply a thin layer of antiseptic ointment if available.',
          'Cover with a sterile adhesive bandage or gauze.',
          'Change the dressing daily or whenever it becomes wet or dirty.',
        ],
      ),
      ArticleSection(
        heading: 'Watch for signs of infection',
        body: 'Seek medical attention if the wound becomes increasingly red, '
            'swollen, warm, or painful, if pus develops, or if the person '
            'develops a fever. Deep cuts, puncture wounds, and animal bites '
            'should be checked by a health worker, especially if tetanus '
            'vaccination status is unknown.',
      ),
    ],
    sectionsFil: [
      ArticleSection(
        heading: 'Hakbang 1 — Patigilin ang Pagdurugo',
        body: 'Idiin nang mahigpit at direkta ang sugat gamit ang malinis na '
            'tela o dressing. Ipagpatuloy ang pagdiin hanggang humina o tumigil '
            'ang dugo. Kung tumagos ang dugo, magdagdag ng isa pang layer sa '
            'ibabaw imbes na alisin ang una.',
      ),
      ArticleSection(
        heading: 'Hakbang 2 — Linisin ang Sugat',
        bullets: [
          'Maghugas ng kamay bago hawakan ang sugat.',
          'Banlawan nang dahan-dahan ang sugat gamit ang malinis na tubig para alisin ang dumi.',
          'Iwasang gamitin ang alcohol o hydrogen peroxide direkta sa bukas na sugat dahil maaari itong makasira sa malusog na tisyu.',
        ],
      ),
      ArticleSection(
        heading: 'Hakbang 3 — Tapalan ang Sugat',
        bullets: [
          'Maglagay ng manipis na antiseptic ointment kung meron.',
          'Takpan gamit ang sterile na bandage o gauze.',
          'Palitan ang tapal araw-araw o kung ito ay basa o marumi na.',
        ],
      ),
      ArticleSection(
        heading: 'Bantayan ang mga Senyales ng Impeksyon',
        body: 'Kumonsulta sa health worker kung lumalala ang pamumula, '
            'pamamaga, init, o sakit ng sugat, may nana, o nilagnat ang '
            'biktima. Ang malalim na hiwa, tusok, at kagat ng hayop ay dapat '
            'ipatingin, lalo na kung hindi sigurado ang bakuna sa tetano.',
      ),
    ],
  ),
  'before': const LibraryArticle(
    title: 'Before a Disaster',
    titleFil: 'Bago ang Sakuna',
    subtitle: 'Family plan & go-bag checklist',
    subtitleFil: 'Plano ng pamilya at go-bag checklist',
    icon: Icons.shield_outlined,
    iconBg: AppColors.green50,
    iconFg: AppColors.green700,
    sections: [
      ArticleSection(
        heading: 'Make a family emergency plan',
        bullets: [
          'Agree on a meeting point if family members get separated.',
          'Identify the nearest evacuation center for your barangay.',
          'Save MDRRMO, barangay, and emergency hotline numbers in every phone.',
          'Know the evacuation routes from your home and workplace.',
        ],
      ),
      ArticleSection(
        heading: 'Prepare a go-bag',
        bullets: [
          'Drinking water and ready-to-eat food for at least 3 days.',
          'Flashlight, extra batteries, and a power bank.',
          'First aid kit and any maintenance medicines.',
          'Copies of important documents (IDs, land titles, insurance) in a waterproof bag.',
          'Cash, whistle, face masks, and a battery-powered radio.',
          'Extra clothes, blankets, and hygiene items.',
        ],
      ),
      ArticleSection(
        heading: 'Around the house',
        bullets: [
          'Know how to shut off the main water, gas, and electricity supply.',
          'Secure heavy furniture and appliances that could topple over.',
          'Keep gutters and drainage clear before the rainy season.',
        ],
      ),
      ArticleSection(
        heading: 'Stay informed',
        body: 'Monitor official MDRRMO and PAGASA advisories regularly, '
            'especially during typhoon season. Sign up for SMS alerts in the '
            'app so you receive announcements even with limited internet '
            'access.',
      ),
    ],
    sectionsFil: [
      ArticleSection(
        heading: 'Gumawa ng Family Emergency Plan',
        bullets: [
          'Magkasundo sa lugar na magkikitaan kung magkahiwalay ang pamilya.',
          'Alamin ang pinakamalapit na evacuation center sa inyong barangay.',
          'I-save ang mga numero ng MDRRMO, barangay, at emergency hotlines sa lahat ng cellphone.',
          'Alamin ang ruta ng evacuation mula sa tahanan at lugar ng trabaho.',
        ],
      ),
      ArticleSection(
        heading: 'Maghanda ng Go-bag',
        bullets: [
          'Inuming tubig at ready-to-eat na pagkain para sa hindi bababa sa 3 araw.',
          'Flashlight, ekstrang baterya, at power bank.',
          'First aid kit at gamot na regular na iniinom.',
          'Kopya ng mahahalagang dokumento (ID, titulo ng lupa, insurance) sa waterproof bag.',
          'Pera, pito, face mask, at radyo na de-baterya.',
          'Ekstrang damit, kumot, at gamit sa kalinisan.',
        ],
      ),
      ArticleSection(
        heading: 'Sa Paligid ng Bahay',
        bullets: [
          'Alamin paano isarado ang main water, gas, at kuryente.',
          'Itali o ayusin ang mabibigat na kasangkapan at appliances na maaaring matumba.',
          'Linisin ang mga gutter at drainage bago dumating ang tag-ulan.',
        ],
      ),
      ArticleSection(
        heading: 'Manatiling May Alam',
        body: 'Subaybayan ang mga opisyal na advisory ng MDRRMO at PAGASA, '
            'lalo na sa tag-bagyo. Mag-sign up para sa SMS alerts sa app para '
            'makatanggap ng abiso kahit limitado ang internet.',
      ),
    ],
  ),
  'during': const LibraryArticle(
    title: 'During a Disaster',
    titleFil: 'Habang Nagaganap ang Sakuna',
    subtitle: 'What to do when alerts are issued',
    subtitleFil: 'Gagawin kapag may inilabas na alerto',
    icon: Icons.shield_outlined,
    iconBg: AppColors.green50,
    iconFg: AppColors.green700,
    sections: [
      ArticleSection(
        heading: 'When an alert or warning is issued',
        bullets: [
          'Stay calm and follow instructions from MDRRMO and barangay officials.',
          'Move to higher ground early if you are in a flood- or landslide-prone area — don\'t wait for water to rise.',
          'Unplug appliances and turn off the main power and water supply before leaving.',
          'Bring your go-bag, important documents, and any needed medication.',
        ],
      ),
      ArticleSection(
        heading: 'If you must evacuate',
        bullets: [
          'Use the evacuation routes shared by your barangay — avoid shortcuts through flooded or unfamiliar areas.',
          'Help elderly, children, persons with disabilities, and pregnant women first.',
          'Do not attempt to cross flooded roads, bridges, or rivers — even shallow, fast-moving water can sweep people away.',
          'Keep pets secured if possible, but never risk your safety to retrieve them.',
        ],
      ),
      ArticleSection(
        heading: 'If you are staying in place',
        bullets: [
          'Move to the highest level of a sturdy structure, away from windows.',
          'Keep your phone charged and radio on for updates.',
          'Avoid using candles if there is a gas leak risk — use flashlights instead.',
        ],
      ),
      ArticleSection(
        heading: 'During an earthquake',
        body: 'Drop, cover, and hold on. Get under a sturdy table or against '
            'an interior wall, away from windows and heavy furniture. If '
            'outdoors, move to an open area away from buildings, trees, and '
            'power lines. Do not run outside during the shaking.',
      ),
    ],
    sectionsFil: [
      ArticleSection(
        heading: 'Kapag May Inilabas na Alerto o Babala',
        bullets: [
          'Manatiling kalmado at sundin ang tagubilin ng MDRRMO at barangay officials.',
          'Lumikas papuntang mataas na lugar agad kung ikaw ay sa flood- o landslide-prone area — huwag hintayin tumaas ang tubig.',
          'I-unplug ang appliances at isara ang main power at water supply bago umalis.',
          'Dalhin ang go-bag, mahahalagang dokumento, at gamot na kailangan.',
        ],
      ),
      ArticleSection(
        heading: 'Kung Kailangang Lumikas',
        bullets: [
          'Gamitin ang ruta ng evacuation na ibinigay ng barangay — iwasan ang shortcut sa baha o lugar na hindi pamilyar.',
          'Unahing tulungan ang mga matanda, bata, PWD, at buntis.',
          'Huwag tumawid sa mga binahang kalsada, tulay, o ilog — kahit mababaw na tubig na mabilis ang agos ay maaaring tangayin ang tao.',
          'Pasiguraduhin ang alagang hayop kung posible, pero huwag ipagpalit ang sariling kaligtasan dito.',
        ],
      ),
      ArticleSection(
        heading: 'Kung Mananatili sa Bahay',
        bullets: [
          'Lumipat sa pinakamataas na bahagi ng matibay na gusali, palayo sa bintana.',
          'Panatilihing charged ang cellphone at buksan ang radyo para sa updates.',
          'Iwasang gumamit ng kandila kung may panganib ng gas leak — gamitin na lang ang flashlight.',
        ],
      ),
      ArticleSection(
        heading: 'Habang Lumilindol',
        body: 'Dapa, sumilong, at humawak nang mahigpit. Pumasok sa ilalim ng '
            'matibay na mesa o sa tabi ng panloob na pader, palayo sa bintana '
            'at mabibigat na kasangkapan. Kung sa labas, lumayo sa gusali, '
            'puno, at poste ng kuryente. Huwag tumakbo sa labas habang '
            'yumayanig.',
      ),
    ],
  ),
  'after': const LibraryArticle(
    title: 'After a Disaster',
    titleFil: 'Pagkatapos ng Sakuna',
    subtitle: 'Recovery & assistance steps',
    subtitleFil: 'Hakbang sa paggaling at tulong',
    icon: Icons.shield_outlined,
    iconBg: AppColors.green50,
    iconFg: AppColors.green700,
    sections: [
      ArticleSection(
        heading: 'Check for safety first',
        bullets: [
          'Check yourself and family members for injuries before checking property.',
          'Watch for hazards: downed power lines, broken glass, gas leaks, and structural damage.',
          'Avoid floodwater — it may be contaminated or carry electrical hazards.',
          'Do not return home until local officials say it is safe to do so.',
        ],
      ),
      ArticleSection(
        heading: 'Getting help',
        bullets: [
          'Report damage and injuries to your barangay or MDRRMO using the Service Request feature.',
          'Visit designated relief distribution points for food, water, and hygiene kits.',
          'Keep records (photos) of damage to your home or belongings for assistance applications.',
        ],
      ),
      ArticleSection(
        heading: 'Health and hygiene',
        bullets: [
          'Boil or treat water before drinking if your water supply may be contaminated.',
          'Throw away food that came into contact with floodwater.',
          'Wash hands frequently, especially after cleanup activities.',
          'Watch for signs of waterborne illness and seek medical care if symptoms appear.',
        ],
      ),
      ArticleSection(
        heading: 'Emotional recovery',
        body: 'It is normal to feel anxious, sad, or overwhelmed after a '
            'disaster. Stay connected with family, friends, and neighbors, '
            'and reach out to local health workers if you or someone you know '
            'is struggling to cope.',
      ),
    ],
    sectionsFil: [
      ArticleSection(
        heading: 'Suriin Muna ang Kaligtasan',
        bullets: [
          'Tingnan kung may sugat ang sarili at pamilya bago tingnan ang ari-arian.',
          'Mag-ingat sa mga panganib: putol na linya ng kuryente, basag na salamin, gas leak, at sirang istruktura.',
          'Iwasan ang tubig-baha — maaaring kontaminado o may electrical hazard.',
          'Huwag bumalik sa bahay hangga\'t hindi pinapayagan ng mga opisyal.',
        ],
      ),
      ArticleSection(
        heading: 'Paghingi ng Tulong',
        bullets: [
          'I-report ang pinsala at sugat sa barangay o MDRRMO gamit ang Service Request feature.',
          'Pumunta sa mga itinalagang relief distribution point para sa pagkain, tubig, at hygiene kit.',
          'Kumuha ng litrato ng pinsala sa bahay o gamit para sa application ng tulong.',
        ],
      ),
      ArticleSection(
        heading: 'Kalusugan at Kalinisan',
        bullets: [
          'Pakuluan o linisin ang tubig bago inumin kung posibleng kontaminado ang supply.',
          'Itapon ang pagkain na nahawakan o nadikitan ng tubig-baha.',
          'Maghugas ng kamay nang madalas, lalo na pagkatapos ng paglilinis.',
          'Bantayan ang sintomas ng sakit mula sa kontaminadong tubig at kumonsulta agad kung may sintomas.',
        ],
      ),
      ArticleSection(
        heading: 'Pagkakaroon ng Lakas ng Loob',
        body: 'Normal lamang na maramdaman ang pagkabalisa, kalungkutan, o '
            'pagkapagod pagkatapos ng sakuna. Manatiling konektado sa pamilya, '
            'kaibigan, at kapitbahay, at humingi ng tulong sa local health '
            'worker kung nahihirapang makayanan ang sitwasyon.',
      ),
    ],
  ),
  'drrm_plan': const LibraryArticle(
    title: 'Echague DRRM Plan 2026',
    titleFil: 'Echague DRRM Plan 2026',
    subtitle: 'Summary of the official plan',
    subtitleFil: 'Buod ng opisyal na plano',
    icon: Icons.description_outlined,
    iconBg: AppColors.grey50,
    iconFg: AppColors.inkMuted,
    sections: [
      ArticleSection(
        heading: 'About this document',
        body: 'The Echague Disaster Risk Reduction and Management (DRRM) '
            'Plan outlines how the municipality prepares for, responds to, '
            'and recovers from natural and human-induced hazards. This in-app '
            'summary highlights the sections most relevant to residents — the '
            'full official document remains available from the MDRRMO office.',
      ),
      ArticleSection(
        heading: 'Key hazards covered',
        bullets: [
          'Typhoons, flooding, and severe weather',
          'Earthquakes and ground shaking',
          'Landslides in upland barangays',
          'Fire incidents in residential and commercial areas',
        ],
      ),
      ArticleSection(
        heading: 'What the plan provides for',
        bullets: [
          'Early warning systems and SMS alert distribution',
          'Designated evacuation centers per barangay cluster',
          'Pre-positioned relief goods and emergency supplies',
          'Coordination with PNP, BFP, and rescue volunteers during incidents',
        ],
      ),
      ArticleSection(
        heading: 'How residents can participate',
        body: 'Residents are encouraged to attend barangay-level disaster '
            'preparedness drills, keep contact information updated with their '
            'barangay, and report hazards (blocked drainage, damaged '
            'infrastructure) through the Service Request feature so they can '
            'be addressed before they become emergencies.',
      ),
    ],
    sectionsFil: [
      ArticleSection(
        heading: 'Tungkol sa Dokumentong Ito',
        body: 'Ang Echague Disaster Risk Reduction and Management (DRRM) Plan '
            'ay naglalahad kung paano naghahanda, tumutugon, at bumabangon ang '
            'munisipyo sa mga sakuna mula sa kalikasan o gawa ng tao. Ang buod '
            'na ito sa app ay nagbibigay-diin sa mga bahaging may kaugnayan sa '
            'mga residente — ang buong opisyal na dokumento ay makukuha sa '
            'MDRRMO office.',
      ),
      ArticleSection(
        heading: 'Mga Pangunahing Panganib na Sinasaklaw',
        bullets: [
          'Bagyo, baha, at matinding panahon',
          'Lindol at panginginig ng lupa',
          'Landslide sa mga barangay sa mataas na lugar',
          'Sunog sa mga lugar na tirahan at komersyal',
        ],
      ),
      ArticleSection(
        heading: 'Ano ang Saklaw ng Plano',
        bullets: [
          'Early warning system at pagpapadala ng SMS alert',
          'Itinalagang evacuation centers para sa bawat cluster ng barangay',
          'Naka-handa nang relief goods at emergency supplies',
          'Koordinasyon sa PNP, BFP, at rescue volunteers sa oras ng insidente',
        ],
      ),
      ArticleSection(
        heading: 'Paano Makakatulong ang Residente',
        body: 'Hinihikayat ang mga residente na dumalo sa barangay-level na '
            'disaster drills, i-update ang contact information sa barangay, '
            'at i-report ang mga panganib (nakaharang na drainage, sirang '
            'imprastraktura) gamit ang Service Request feature para maagapan '
            'bago ito magdulot ng emerhensiya.',
      ),
    ],
  ),
  'evacuation_map': const LibraryArticle(
    title: 'Evacuation Map & Centers',
    titleFil: 'Mapa at Sentro ng Evacuation',
    subtitle: 'Designated centers in Echague',
    subtitleFil: 'Itinalagang sentro sa Echague',
    icon: Icons.description_outlined,
    iconBg: AppColors.grey50,
    iconFg: AppColors.inkMuted,
    sections: [
      ArticleSection(
        heading: 'Finding your evacuation center',
        body: 'Each barangay in Echague is assigned to a primary evacuation '
            'center, usually the nearest school, covered court, or municipal '
            'building on higher ground. Confirm your assigned center with '
            'your barangay officials, as assignments may be adjusted based on '
            'the type of hazard.',
      ),
      ArticleSection(
        heading: 'Sample designated centers',
        bullets: [
          'Echague Central School Covered Court',
          'Echague National Comprehensive High School',
          'Barangay Angancasilian Multi-Purpose Hall',
          'Barangay Malasin Covered Court',
          'Echague Municipal Gymnasium',
        ],
      ),
      ArticleSection(
        heading: 'What to expect at a center',
        bullets: [
          'Registration at the entrance for headcount and family tracing.',
          'Designated areas for families, seniors, persons with disabilities, and pregnant women.',
          'Basic water, sanitation, and first aid support.',
          'Regular updates from MDRRMO and barangay staff.',
        ],
      ),
      ArticleSection(
        heading: 'Getting there safely',
        body: 'Use the routes shared by your barangay during drills and '
            'advisories. Avoid roads that are known to flood quickly, and '
            'leave early — evacuation centers fill up fastest in the hours '
            'right before a typhoon makes landfall.',
      ),
    ],
    sectionsFil: [
      ArticleSection(
        heading: 'Paghahanap ng Inyong Evacuation Center',
        body: 'Bawat barangay sa Echague ay may itinalagang primary evacuation '
            'center, karaniwang ang pinakamalapit na paaralan, covered court, o '
            'gusaling munisipyo na nasa matataas na lugar. Kumpirmahin sa '
            'inyong barangay officials ang inyong itinalagang sentro, dahil '
            'maaari itong baguhin depende sa uri ng sakuna.',
      ),
      ArticleSection(
        heading: 'Mga Halimbawang Itinalagang Sentro',
        bullets: [
          'Echague Central School Covered Court',
          'Echague National Comprehensive High School',
          'Barangay Angancasilian Multi-Purpose Hall',
          'Barangay Malasin Covered Court',
          'Echague Municipal Gymnasium',
        ],
      ),
      ArticleSection(
        heading: 'Ano ang Mararanasan sa Sentro',
        bullets: [
          'Pagpaparehistro sa pasukan para sa headcount at family tracing.',
          'Itinalagang lugar para sa mga pamilya, senior citizen, PWD, at buntis.',
          'Pangunahing tulong sa tubig, sanitation, at first aid.',
          'Regular na update mula sa MDRRMO at barangay staff.',
        ],
      ),
      ArticleSection(
        heading: 'Paano Makarating nang Ligtas',
        body: 'Gamitin ang ruta na ibinigay ng barangay sa mga drill at '
            'advisory. Iwasan ang mga kalsadang madaling bahain, at umalis nang '
            'maaga — mas mabilis mapuno ang evacuation centers sa oras bago '
            'tumama ang bagyo.',
      ),
    ],
  ),
};
