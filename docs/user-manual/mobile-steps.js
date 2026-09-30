const { GROUPS, login } = require('./mobile-capture.js')

GROUPS.probe = async (p, a) => {
  await a.shot('_probe')
}

GROUPS.reg1 = async (p, a) => {
  await a.shot('01-login')
  await a.tap(280, 732, 3000)
  await a.shot('_r1')
  await a.wheel(600)
  await a.shot('_r2')
  await a.wheel(900)
  await a.shot('_r3')
}

GROUPS.reg2 = async (p, a) => {
  await a.tap(280, 732, 3000)
  await a.wheel(600)
  await a.shot('02-register-individual')
  await a.tap(297, 392, 1500)
  await a.shot('03-register-organization')
  await a.tap(115, 392, 1500)
  await a.wheel(900)
  await a.tap(206, 509, 1500)
  await p.keyboard.type('cab', { delay: 80 })
  await a.wait(1200)
  await a.shot('04-register-barangay-search')
}

async function fillAt(p, a, x, y, text) {
  await a.tap(x, y, 700)
  await a.fill(text)
}

// Register a throwaway Individual account (deleted afterwards). Returns on the code screen.
async function registerIndividual(p, a, phone) {
  await a.tap(280, 732, 3000)
  await a.wheel(600)
  await a.wheel(900)
  await fillAt(p, a, 206, 248, 'Ana')
  await fillAt(p, a, 206, 335, 'Reyes')
  await fillAt(p, a, 206, 422, phone)
  await a.tap(206, 509, 900)
  await p.keyboard.type('fab', { delay: 80 })
  await a.wait(900)
  await a.tap(206, 565, 1200)
  await fillAt(p, a, 206, 604, 'Purok 3')
  await fillAt(p, a, 206, 691, 'Pasada123')
  await fillAt(p, a, 206, 823, 'Pasada123')
  await a.wheel(900)
}
module.exports.registerIndividual = registerIndividual

GROUPS.reg3 = async (p, a) => {
  await registerIndividual(p, a, '09170000901')
  await a.shot('_r4')
}

GROUPS.reg4 = async (p, a) => {
  await registerIndividual(p, a, '09170000901')
  await a.shot('05-register-filled')
  await a.tap(33, 759, 800)
  await a.tap(206, 839, 6000)
  await a.shot('06-verify-number')
}

async function registerOrg(p, a, phone) {
  await a.tap(280, 732, 3000)
  await a.wheel(600)
  await a.tap(297, 392, 1500)
  await fillAt(p, a, 206, 473, 'Echague Manual Test Org')
  await fillAt(p, a, 206, 560, 'Ana')
  await fillAt(p, a, 206, 647, 'Reyes')
  await fillAt(p, a, 206, 734, phone)
  await a.tap(206, 821, 900)
  await p.keyboard.type('fab', { delay: 80 })
  await a.wait(900)
  await a.tap(206, 877, 1200)
  await a.wheel(900)
}

GROUPS.org1 = async (p, a) => {
  await registerOrg(p, a, '09170000902')
  await a.shot('_o1')
}

GROUPS.org2 = async (p, a) => {
  await registerOrg(p, a, '09170000902')
  await fillAt(p, a, 206, 778, 'Pasada123')
  await a.wheel(900)
  await a.shot('_o2')
}

GROUPS.org3 = async (p, a) => {
  await registerOrg(p, a, '09170000902')
  await fillAt(p, a, 206, 778, 'Pasada123')
  await a.wheel(900)
  await fillAt(p, a, 206, 700, 'Pasada123')
  await a.tap(33, 759, 800)
  await a.tap(206, 839, 6000)
  await fillAt(p, a, 206, 231, '555555')
  await a.tap(206, 303, 9000)
  await a.shot('07-awaiting-approval')
}

GROUPS.org4 = async (p, a) => {
  await login(p, '09170000902', 'Pasada123')
  await a.shot('_o4home')
  await a.tap(206, 885, 3000)
  await a.shot('08-awaiting-approval-services')
}

GROUPS.home = async (p, a) => {
  await a.key('Tab')
  await a.fill('09170000101')
  await a.key('Tab')
  await a.fill('Passw0rd!123')
  await a.key('Tab'); await a.key('Tab'); await a.key('Enter', 5000)
  await a.shot('09-login-code')
  await a.key('Tab')
  await a.fill('555555')
  await a.key('Tab'); await a.key('Enter', 9000)
  await a.shot('10-home')
  await a.wheel(900)
  await a.shot('_home2')
}

GROUPS.ind1 = async (p, a) => {
  await login(p, '09170000101')
  await a.tap(326, 64, 2500)
  await a.shot('11-notifications')
  await a.key('Escape', 1200)
  await a.tap(206, 885, 3000)
  await a.shot('12-services-individual')
  await a.wheel(900)
  await a.shot('_svc2')
}

GROUPS.form1 = async (p, a) => {
  await login(p, '09170000101')
  await a.tap(206, 885, 3000)
  await a.tap(206, 483, 3000)
  await a.shot('13-road-form-top')
  await a.wheel(900)
  await a.shot('_f2')
  await a.wheel(900)
  await a.shot('_f3')
}

const VALID_ID = process.env.VALID_ID

async function attach(p, a, x, y) {
  const [chooser] = await Promise.all([p.waitForEvent('filechooser', { timeout: 10000 }), a.tap(x, y, 300)])
  await chooser.setFiles(VALID_ID)
  await a.wait(1500)
}
module.exports.attach = attach

GROUPS.form2 = async (p, a) => {
  await login(p, '09170000101')
  await a.tap(206, 885, 5000)
  await a.shot('_dbg1')
  await a.tap(206, 521, 4000)
  await a.shot('_dbg2')
  await fillAt(p, a, 206, 299, 'A dog is trapped in the drainage canal beside Purok 2 chapel.')
  await attach(p, a, 206, 469)
  await a.shot('14-sample-form-filled')
  await a.tap(206, 744, 7000)
  await a.shot('15-submitted')
}

GROUPS.brgy = async (p, a) => {
  await login(p, '09170000102')
  await a.shot('16-home-barangay')
  await a.tap(206, 885, 5000)
  await a.shot('17-services-barangay')
  await a.wheel(900)
  await a.shot('17b-services-barangay-more')
}

GROUPS.org = async (p, a) => {
  await login(p, '09170000103')
  await a.tap(206, 885, 5000)
  await a.shot('18-services-organization')
  await a.wheel(900)
  await a.shot('18b-services-organization-more')
}

GROUPS.amb1 = async (p, a) => {
  await login(p, '09170000101')
  await a.tap(125, 885, 5000)
  await a.shot('19-ambulance-top')
  for (let i = 1; i <= 5; i++) { await a.wheel(900); await a.shot('_a' + i) }
}

async function openAmbulance(p, a) {
  await login(p, '09170000101')
  await a.tap(125, 885, 5000)
}

GROUPS.amb2 = async (p, a) => {
  await openAmbulance(p, a)
  await fillAt(p, a, 206, 476, 'Maria Santos')
  await fillAt(p, a, 206, 555, '62')
  await a.tap(206, 682, 900)
  await p.keyboard.type('fab', { delay: 80 })
  await a.wait(900)
  await a.shot('20-ambulance-barangay-search')
  await a.tap(206, 738, 1200)
  await fillAt(p, a, 206, 761, 'Purok 3')
  await a.wheel(900)
  await a.shot('21-ambulance-patient-filled')
  await a.wheel(900)
  await a.tap(206, 567, 1200)
  await a.shot('_from-open')
}

async function ambulanceToWhen(p, a) {
  await openAmbulance(p, a)
  await fillAt(p, a, 206, 476, 'Maria Santos')
  await fillAt(p, a, 206, 555, '62')
  await a.tap(206, 682, 900)
  await p.keyboard.type('fab', { delay: 80 })
  await a.wait(900)
  await a.tap(206, 738, 1200)
  await fillAt(p, a, 206, 761, 'Purok 3')
  await a.wheel(900)
  await a.wheel(900)
  await fillAt(p, a, 206, 647, 'Purok 3, San Fabian')
  await fillAt(p, a, 206, 726, 'Beside the chapel')
  await a.wheel(900)
  await a.tap(206, 579, 1200)
  await a.tap(206, 683, 1500)
  await a.shot('23-ambulance-trip-filled')
  await fillAt(p, a, 206, 722, 'Weakness and dizziness since this morning.')
  await a.wheel(900)
  await fillAt(p, a, 206, 645, 'Juan Dela Cruz')
  await a.shot('24-ambulance-relatives-when')
}

GROUPS.amb3 = async (p, a) => {
  await ambulanceToWhen(p, a)
  await a.tap(295, 787, 2500)
  await a.shot('25-ambulance-date-picker')
}

GROUPS.amb4 = async (p, a) => {
  await ambulanceToWhen(p, a)
  await a.tap(295, 787, 2500)
  await a.tap(350, 716, 2500)
  await a.shot('26-ambulance-time-picker')
  await a.tap(106, 516, 1200)
  await a.tap(333, 666, 4000)
  await a.shot('27-ambulance-scheduled')
  await a.wheel(900)
  await a.shot('28-ambulance-attachments-submit')
}

GROUPS.amb5 = async (p, a) => {
  await openAmbulance(p, a)
  await a.tap(206, 682, 900)
  await p.keyboard.type('oth', { delay: 80 })
  await a.wait(900)
  await a.tap(206, 738, 1500)
  await a.wheel(900)
  await fillAt(p, a, 206, 536, '12 Rizal St., Cauayan City')
  await a.shot('21b-ambulance-address-other')
}

GROUPS.trk1 = async (p, a) => {
  await login(p, '09170000101')
  await a.tap(330, 885, 5000)
  await a.shot('28-track-list')
  for (let i = 1; i <= 4; i++) { await a.wheel(900); await a.shot('_t' + i) }
}

GROUPS.trk2 = async (p, a) => {
  await login(p, '09170000101')
  await a.tap(330, 885, 5000)
  await a.tap(249, 185, 2000)
  await a.shot('31-track-booked')
  await a.tap(346, 185, 2000)
  await a.shot('32-track-responding')
}

GROUPS.trk3 = async (p, a) => {
  await login(p, '09170000101')
  await a.tap(330, 885, 5000)
  await a.tap(346, 185, 2000)
  await a.tap(206, 435, 2000)
  await a.shot('33-track-timeline')
  await a.tap(249, 185, 2000)
  await a.tap(206, 506, 2000)
  await a.shot('34-track-cancel-confirm')
  await a.key('Escape', 1000)
}

GROUPS.brw1 = async (p, a) => {
  await login(p, '09170000101')
  await a.tap(287, 885, 5000)
  await a.shot('35-borrow-list')
  await a.wheel(900)
  await a.shot('_b1')
  await a.wheel(1800)
  await a.shot('_b2')
}

GROUPS.brw2 = async (p, a) => {
  await login(p, '09170000101')
  await a.tap(287, 885, 5000)
  await a.tap(324, 264, 2500)
  await a.shot('37-borrow-sheet')
  await a.key('Escape', 1500)
  await a.tap(296, 188, 2500)
  await a.shot('38-borrow-my-requests')
}

GROUPS.lib1 = async (p, a) => {
  await login(p, '09170000101')
  await a.tap(206, 769, 4000)
  await a.shot('39-library')
  for (let i = 1; i <= 3; i++) { await a.wheel(900); await a.shot('_l' + i) }
}

GROUPS.lib2 = async (p, a) => {
  await login(p, '09170000101')
  await a.tap(206, 769, 4000)
  await a.tap(206, 716, 3000)
  await a.shot('41-guide-open')
}

GROUPS.prof1 = async (p, a) => {
  await login(p, '09170000101')
  await a.tap(374, 64, 3000)
  await a.shot('42-profile')
  await a.wheel(900)
  await a.shot('_pr1')
}

GROUPS.lang1 = async (p, a) => {
  await login(p, '09170000101')
  await a.tap(374, 64, 3000)
  await a.tap(206, 598, 2500)
  await a.shot('43-language-picker')
}

GROUPS.off1 = async (p, a) => {
  await login(p, '09170000101')
  await p.context().setOffline(true)
  await a.tap(330, 885, 3000)
  await a.tap(88, 885, 6000)
  await a.shot('45-offline')
  await p.context().setOffline(false)
}

GROUPS.lang2 = async (p, a) => {
  await login(p, '09170000101')
  await a.tap(374, 64, 3000)
  await a.tap(206, 598, 2500)
  await a.tap(60, 863, 2500)
  await a.tap(46, 64, 3000)
  await a.shot('44-home-filipino')
}

GROUPS.off2 = async (p, a) => {
  await login(p, '09170000101')
  await p.context().setOffline(true)
  await a.tap(367, 885, 6000)
  await a.shot('45-offline-track')
  await a.tap(44, 885, 6000)
  await a.shot('_off-home')
  await p.context().setOffline(false)
}

GROUPS.off3 = async (p, a) => {
  await login(p, '09170000101')
  await p.context().setOffline(true)
  await p.mouse.move(206, 300)
  await p.mouse.down()
  for (let y = 300; y <= 650; y += 25) { await p.mouse.move(206, y); await a.wait(30) }
  await p.mouse.up()
  await a.wait(6000)
  await a.shot('45-offline')
  await p.context().setOffline(false)
}
