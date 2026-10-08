# Shot list

Capture each image as `screenshots/raw/<ID>.png`. Then set the numbered boxes for it in `screenshots/annotations.json` (see `tools/README.md`).

- **Admin panel:** browser window 1280×800 or smaller (ADM-09 and ADM-22 at 1440×900, so no column is cut off), cropped to the area the boxes need. Signed in as a super admin unless the state says otherwise. Use the capture database, not `serbis_test_db`.
- **Mobile app:** Android emulator, 390×844, English, signed in as the Head of the Family account unless the state says otherwise.

Box numbers follow the caption order. Each caption is the legend line printed under the image.

## Admin Panel (ADM)

| ID | Page / state to capture | Elements to box (in number order) | Captions |
|---|---|---|---|
| **Part 1, Section 1: Getting started** |||
| ADM-01 | Login page, signed out | 1 Username field<br>2 Password field<br>3 Sign in button | 1 Your username<br>2 Your password<br>3 Sign in |
| ADM-02 | Login, code step (SMS sign-in on; capture DB with fake SMS) | 1 Code boxes<br>2 Resend code<br>3 Verify | 1 The 6-digit code from the text message<br>2 Ask for a new code<br>3 Finish signing in |
| ADM-03 | Change password page (staff account on a temporary password) | 1 Temporary password<br>2 New password<br>3 Confirm new password<br>4 Save button | 1 The password the super admin gave you<br>2 Your own new password<br>3 Type it again<br>4 Save it |
| ADM-04 | Dashboard, signed in as the staff account with 3–4 sections | 1 Sidebar group headings<br>2 Highlighted current page<br>3 Notifications bell<br>4 Profile card | 1 The pages you can open<br>2 Where you are now<br>3 System notices<br>4 Your account and Sign out |
| ADM-05 | Sign out → "Confirm Logout" dialog | 1 Logout button | 1 Confirm signing out |
| **Section 2: Check today's work** |||
| ADM-06 | Dashboard, super admin, live data | 1 KPI tiles row<br>2 Live board tabs (Ambulance, Bookings, Services, Borrowing)<br>3 Time-filed cell of the first row<br>4 That whole row | 1 Today's figures<br>2 Switch between boards<br>3 How long it has waited<br>4 A request waiting for you |
| ADM-07 | Analytics, top of page | 1 Period buttons (This month … Custom)<br>2 Barangay filter<br>3 Service filter | 1 Period to report on<br>2 Limit to one barangay<br>3 Limit to one service |
| ADM-08 | Analytics, "Requests by month and service" section | 1 Section title<br>2 Chart / Table toggle<br>3 Data table | 1 What this section measures<br>2 Switch chart and table<br>3 The figures behind the chart |
| **Section 3: Handle resident requests** |||
| ADM-09 | Resident Requests list, several statuses (1440×900) | 1 Status tabs<br>2 Search and filters bar<br>3 One request row | 1 Requests by status<br>2 Narrow the list<br>3 Open a request |
| ADM-10 | Resident Requests, detail of a Pending Road Clearing request with photo | 1 Requester block<br>2 Details and site photo<br>3 Assignment<br>4 Disapprove / Approve & assign | 1 Who filed it<br>2 What they need<br>3 Unit and responders to send<br>4 Approve or disapprove |
| ADM-11 | "Assign to this request" panel (Select vehicle in the request panel), a vehicle ticked | 1 Vehicle list<br>2 Responders tab<br>3 Done | 1 Pick a unit<br>2 Pick the responders<br>3 Back to the request |
| ADM-12 | Same request, Approve & assign with no vehicle: "Approve without a vehicle" dialog | 1 Approve | 1 Approve when no unit is needed |
| ADM-13 | Disapprove dialog for a Pending request | 1 Reason field<br>2 Disapprove request | 1 Why (the resident sees this)<br>2 Confirm |
| ADM-14 | Detail of a Responding request | 1 Status: Responding<br>2 Mark as resolved | 1 Work in progress<br>2 Mark the work done |
| ADM-15 | Log service request dialog (walk-in) | 1 No account / Registered Head of the Family choice<br>2 Service<br>3 Description<br>4 File request | 1 Is the person registered?<br>2 What they need<br>3 Details<br>4 Save the request |
| ADM-16 | Print / Export → Export all … matching dialog | 1 Print / PDF, CSV, XLSX buttons<br>2 Field list | 1 Choose the output<br>2 Columns to include |
| **Section 4: Dispatch the ambulance** |||
| ADM-17 | Ambulance Dispatch, detail of a Pending "now" request filed from the resident API (TXN-000072, no scheduled time) | 1 Submitted time<br>2 Pickup location and destination<br>3 Patient block<br>4 Approve & dispatch | 1 When it was sent<br>2 From and to<br>3 Who is being carried<br>4 Approve and send the unit |
| ADM-18 | "Select a unit" panel (Select unit in a Pending request's panel), a unit chosen | 1 Unit list<br>2 Available tag (busy units are hidden)<br>3 Done | 1 Ambulance units<br>2 Free for this request<br>3 Back to the request |
| ADM-47 | Booked request filed from the resident API, no unit (TXN-000074): Select unit, a unit ticked, before Done | 1 "free for this request" line<br>2 Ticked unit row<br>3 Done | 1 Only free units are listed<br>2 The unit you picked<br>3 Save it |
| ADM-48 | Booked request with a unit assigned through Select unit and Done (TXN-000073), scrolled to Assignment | 1 Assignment (unit, Reassign)<br>2 Dispatch<br>3 Disapprove this booking | 1 The unit assigned<br>2 Send it when the unit leaves<br>3 Refuse the booking |
| ADM-19 | Reschedule booking dialog on a Booked request | 1 Starts<br>2 Ends<br>3 Reason for the change<br>4 Reschedule | 1 New start<br>2 New end<br>3 Why it moved (the resident sees this)<br>4 Save the new time |
| ADM-20 | Ambulance schedule (Day view button), Day view, a day with 2–3 trips | 1 Day / Month switch<br>2 Today<br>3 A trip block<br>4 Status colours legend | 1 Change the view<br>2 Jump to today<br>3 One booked trip<br>4 What the colours mean |
| ADM-21 | Trip logs tab → trip → Update trip log, a returned trip | 1 Departed / arrival times<br>2 Odometer at departure / on return<br>3 Did not reach destination + Reason<br>4 Save trip log | 1 Times of each leg<br>2 Meter readings<br>3 Only if the patient was not carried<br>4 Save |
| ADM-22 | Trip logs tab with a trip's panel open (1440×900) | 1 Trip row<br>2 Print button in the panel | 1 One trip<br>2 Print its conduction form |
| ADM-23 | Printed conduction request form (print preview) | 1 Patient and trip table<br>2 Drivers, passengers, relatives table<br>3 Trip log table<br>4 Signature row | 1 Patient and route<br>2 People on the trip<br>3 Times and meter readings<br>4 Signatures |
| **Section 5: Lend equipment** |||
| ADM-24 | Equipment Borrowing list with a Pending row | 1 Equipment filter<br>2 Barangay filter<br>3 Pending row | 1 Limit to one item<br>2 Limit to one barangay<br>3 A request to review |
| ADM-25 | Detail of a Pending borrowing (pickup) | 1 Borrower<br>2 Item and quantity<br>3 Handover (pickup or delivery)<br>4 Approve request<br>5 Deny request | 1 Who asked<br>2 What and how many<br>3 How it reaches them<br>4 Approve<br>5 Refuse |
| ADM-26 | Deny dialog | 1 Reason for denial<br>2 Deny request | 1 Why (the resident sees this)<br>2 Confirm |
| ADM-27 | "Approve this request" dialog of a Pending borrowing (Release itself has no dialog) | 1 Due back on<br>2 Approve request | 1 Return date<br>2 Approve |
| ADM-28 | Detail of a Released borrowing with condition photos | 1 Condition photos<br>2 Confirm items returned | 1 Photos taken at the counter<br>2 Record the return |
| **Part 2, Section 6: Keep resources up to date** |||
| ADM-29 | Vehicles → Add unit dialog | 1 Unit identifier<br>2 Type<br>3 Specification<br>4 Status<br>5 Add unit | 1 Name of the unit<br>2 Kind of vehicle<br>3 Model or notes<br>4 Available or Maintenance<br>5 Save |
| ADM-30 | Responders → Add responder dialog | 1 Name<br>2 Position<br>3 Contact number<br>4 Add responder | 1 Full name<br>2 Role on the team<br>3 Mobile number<br>4 Save |
| ADM-31 | Resource Management → Add equipment dialog | 1 Item name<br>2 Total owned<br>3 Status<br>4 Add | 1 Name residents see<br>2 How many the office owns<br>3 Available or Unavailable<br>4 Save |
| ADM-32 | Procurement Reference, rows loaded (no placeholder rows) | 1 Status filter<br>2 Refresh | 1 Limit the list<br>2 Reload it |
| **Section 7: Work with residents** |||
| ADM-33 | Accounts list, rows loaded (no placeholder rows) | 1 Search<br>2 Barangay filter<br>3 Status filter<br>4 Account row | 1 Find by name or number<br>2 Limit to one barangay<br>3 Active, pending or deactivated<br>4 Open the account |
| ADM-34 | Accounts, a Pending organization's account opened | 1 Pending note<br>2 Approve organization<br>3 Reject organization | 1 Waiting for approval<br>2 Let it request services<br>3 Refuse it |
| ADM-35 | Documents, a file chosen in the upload area (inline bar, no dialog) | 1 Title shown to residents<br>2 Chosen file<br>3 Publish | 1 Name in the app's library<br>2 The file<br>3 Publish it |
| ADM-36 | Text Blast, New blast form filled in, one blast in Recent blasts; cropped below the page header so the SkySMS key line is out | 1 Template<br>2 Message<br>3 Target audience / barangays<br>4 Send blast | 1 Start from a template<br>2 The text<br>3 Who receives it<br>4 Continue |
| ADM-37 | "Confirm this blast" dialog, code typed | 1 Text blast code<br>2 Send blast | 1 The code your office set<br>2 Send |
| ADM-38 | "Text blast code" dialog | 1 Current code<br>2 New code<br>3 Set code | 1 The code in use<br>2 The new code<br>3 Save it |
| **Section 8: Configure the system** |||
| ADM-39 | Manage Services → Edit service dialog (name and category are locked) | 1 Filipino name<br>2 Description<br>3 Save changes | 1 Filipino name<br>2 Helps residents pick the right service<br>3 Save |
| ADM-40 | Service Audience matrix | 1 A service row<br>2 One account-type checkbox | 1 The service<br>2 Who may request it |
| ADM-41 | Service Vehicles matrix | 1 A service row<br>2 One vehicle-type checkbox | 1 The service<br>2 Units offered when approving it |
| ADM-42 | Emergency Hotlines → Add hotline dialog | 1 Name<br>2 Name in Filipino<br>3 Numbers<br>4 Add hotline | 1 English name<br>2 Filipino name<br>3 One or more numbers<br>4 Save |
| **Section 9: Manage staff (super admin)** |||
| ADM-43 | Staff Accounts → Add staff account dialog | 1 First and last name<br>2 Username<br>3 Mobile number<br>4 Create account | 1 Their name<br>2 What they sign in with<br>3 For sign-in codes<br>4 Create the account |
| ADM-44 | More actions → Manage access dialog for one staff account | 1 Section checkboxes<br>2 Super admin switch<br>3 Save access | 1 Pages they may open<br>2 Full access, including Staff<br>3 Save |
| ADM-45 | Staff row More actions menu open, plus bulk bar | 1 More actions menu<br>2 Reset password<br>3 Close accounts | 1 Actions for one account<br>2 New temporary password<br>3 Close the selected accounts |
| ADM-46 | Activity Logs | 1 Search logs<br>2 One entry | 1 Find by user or action<br>2 Who did what, and when |

## Mobile App (MOB)

| ID | Screen / state to capture | Elements to box (in number order) | Captions |
|---|---|---|---|
| **Section 1: Get started** |||
| MOB-01 | Register step 1 (Who you are), signed out | 1 "Registering as" choice<br>2 First and last name<br>3 Mobile number<br>4 Next: Where you live | 1 Head of the Family or Organization<br>2 Your name<br>3 Your mobile number<br>4 Go on |
| MOB-02 | Register step 2 (Where you live) | 1 Barangay<br>2 Street / Purok (optional)<br>3 Note above the barangay | 1 Where you live<br>2 Street or purok<br>3 Read this before you choose |
| MOB-03 | Register step 3 (Password and review) | 1 Password<br>2 Confirm password<br>3 Review list<br>4 Create account | 1 At least 8 characters<br>2 Type it again<br>3 Check your details<br>4 Finish |
| MOB-04 | "Check your messages" verify screen | 1 Verification code<br>2 Verify<br>3 Resend code in … / Send a new code<br>4 Back to log in | 1 The 6-digit code<br>2 Confirm your number<br>3 Ask for a new code when it reaches 0<br>4 Wrong number? Start again |
| MOB-05 | Log in screen | 1 Mobile number<br>2 Password<br>3 Log in<br>4 Forgot password?<br>5 Register | 1 Your number<br>2 Your password<br>3 Log in<br>4 Reset your password<br>5 Make a new account |
| MOB-06 | "Check your messages" code screen (Finish signing in) | 1 Code field<br>2 Verify | 1 The code from the text message<br>2 Finish |
| MOB-07 | Forgot password, step 3 "Choose a new password" | 1 New password<br>2 Confirm new password<br>3 Change password | 1 Your new password<br>2 Type it again<br>3 Save it |
| MOB-08 | "Awaiting MDRRMO approval" screen (organization not yet activated) | 1 Status message<br>2 Check again | 1 MDRRMO is checking your account<br>2 See if it is approved |
| **Section 2: Find your way around** |||
| MOB-09 | Home with one open request | 1 Your latest request<br>2 What do you need? tiles<br>3 Notifications bell<br>4 Bottom tabs | 1 Your newest open request<br>2 Shortcuts<br>3 Notifications<br>4 Main parts of the app |
| MOB-10 | Notifications sheet with new and older updates | 1 Earlier section<br>2 An MDRRMO advisory | 1 Older updates<br>2 A notice from MDRRMO |
| **Section 3: Ask for a service** |||
| MOB-11 | Services tab | 1 Category heading<br>2 Service row | 1 Services are grouped<br>2 Open a service |
| MOB-12 | Road Clearing form, sent once with the valid ID missing (field error showing) | 1 Valid ID field and its error<br>2 Site photo<br>3 Send request | 1 Photo of a valid ID (required)<br>2 Photo of the place (optional)<br>3 Send |
| MOB-13 | Relief Goods Distribution, step 1 of 3 (Household) | 1 Step line and progress bar<br>2 Household fields<br>3 Next: Assistance and delivery | 1 Where you are in the form<br>2 About your household<br>3 Go on |
| MOB-14 | DRRM Trainings and Seminars form (barangay or organization account) | 1 Preferred date<br>2 Send request | 1 When you want it<br>2 Send |
| MOB-15 | MDRRMO Certification form | 1 Purpose / details<br>2 Request letter (optional) | 1 What the certification is for<br>2 Attach a letter if you have one |
| MOB-16 | Others form | 1 Description | 1 Say what you need |
| MOB-17 | Request sent sheet | 1 Reference number<br>2 View in Track<br>3 Done | 1 Keep this number<br>2 Follow the request<br>3 Close |
| **Section 4: Book an ambulance** |||
| MOB-18 | Ambulance tab, step 1 Patient | 1 Step progress<br>2 Patient name<br>3 Age<br>4 Next: Trip | 1 Step 1 of 5<br>2 Who will ride<br>3 Their age<br>4 Go on |
| MOB-19 | Step 2 Trip | 1 From<br>2 To (hospital list)<br>3 Next: Condition | 1 Pickup place<br>2 Destination<br>3 Go on |
| MOB-20 | Step 3 Condition | 1 Condition<br>2 Relatives | 1 Why the patient needs transport<br>2 Who will come along |
| MOB-21 | Step 4 Schedule and ID, "Scheduled" chosen | 1 Now, when available / Scheduled choice<br>2 Date and time<br>3 Unit availability note<br>4 Valid ID photo | 1 Now or a set time<br>2 When to pick up<br>3 Whether a unit looks free<br>4 Photo of a valid ID |
| MOB-22 | Step 5 Review | 1 Summary<br>2 Edit link<br>3 Send request | 1 Check your answers<br>2 Change a step<br>3 Send |
| MOB-23 | "Discard this request?" dialog | 1 Discard<br>2 Keep editing | 1 Leave and delete your answers<br>2 Stay on the form |
| **Section 5: Follow your requests** |||
| MOB-24 | Track tab with open and past requests | 1 In progress section<br>2 Status line<br>3 Updated time | 1 Requests still open<br>2 Where it stands<br>3 When it last changed |
| MOB-25 | An open request opened in Track (Under review) | 1 Main status<br>2 Progress steps<br>3 What happens next<br>4 Cancel request | 1 Where it stands<br>2 Steps done and to come<br>3 What MDRRMO does next<br>4 Withdraw it |
| MOB-26 | "Cancel request?" dialog | 1 Cancel request<br>2 Keep request | 1 Withdraw it<br>2 Go back |
| **Section 6: Borrow equipment** |||
| MOB-27 | Borrow tab, catalogue | 1 Search equipment<br>2 Item and stock<br>3 Borrow | 1 Find an item<br>2 How many are free<br>3 Ask to borrow it |
| MOB-28 | Borrow sheet for Wheel Chair | 1 Quantity<br>2 Pickup or delivery<br>3 Purpose<br>4 Send button | 1 How many<br>2 How you get it<br>3 What it is for<br>4 Send |
| MOB-29 | "Request another item" sheet | 1 Item name<br>2 Quantity<br>3 Send request | 1 What you need<br>2 How many<br>3 Send |
| MOB-30 | Borrow → My requests with a Released loan | 1 Status line<br>2 "Please return it by …" line<br>3 Handover photos (label and thumbnail) | 1 Where it stands<br>2 When to bring it back<br>3 Photos taken at the counter |
| **Section 7: Safety guides and hotlines** |||
| MOB-31 | Safety library | 1 Guide row<br>2 Download (shows Saved) | 1 Open a guide<br>2 Keep it on your phone |
| MOB-32 | Article reader | 1 Title<br>2 Back | 1 The guide<br>2 Return to the library |
| MOB-33 | Emergency hotlines page | 1 Hotline name<br>2 Number | 1 Who you are calling<br>2 Tap to call |
| **Section 8: Your profile** |||
| MOB-34 | My profile | 1 Mobile number and barangay line<br>2 Edit my details<br>3 MDRRMO text alerts<br>4 Language<br>5 Offline materials<br>6 Log out | 1 Your number and barangay<br>2 Name and address<br>3 Turn text alerts on or off<br>4 English or Filipino<br>5 Saved guides<br>6 Sign out |
| MOB-35 | Edit my details sheet (Head of the Family) | 1 Name fields<br>2 Barangay<br>3 Save changes | 1 Your name<br>2 Where you live now<br>3 Save |
| MOB-36 | Edit my details → Change: "Change your mobile number" sheet | 1 New mobile number<br>2 Current password<br>3 Send code | 1 Your new number<br>2 To prove it is you<br>3 Get a code on the new number |
| MOB-37 | Language sheet | 1 English<br>2 Filipino | 1 English<br>2 Filipino |
| **Section 9: Common problems** |||
| MOB-38 | Track while offline (airplane mode) | 1 Offline bar ("No connection to MDRRMO…")<br>2 Last updated time | 1 You are offline<br>2 How old the shown data is |
| MOB-39 | Services tab when the list cannot load | 1 Message<br>2 Try again | 1 What went wrong<br>2 Load it again |
| **Added screens** |||
| MOB-40 | Log in screen → Emergency hotlines page, signed out | 1 Hotline name<br>2 Number | 1 Who you are calling<br>2 Tap to call |
| MOB-41 | Home, top: emergency bar | 1 "Emergency? Call the hotline" bar | 1 Call the MDRRMO |
| MOB-42 | Ambulance tab "Not available" (activated organization, Ambulance unticked for organizations) | 1 "Not offered right now" header line<br>2 The Not available card | 1 Not offered to your account type<br>2 Why |
| MOB-43 | Track with a Released loan under Borrowed items | 1 Borrowed items section<br>2 Loan row | 1 Items you have out<br>2 Open it in Borrow |
| MOB-44 | Profile → Offline materials page, one guide downloaded | 1 Downloaded documents<br>2 Included in the app | 1 Guides you downloaded<br>2 Guides that come with the app |
| MOB-45 | Change mobile number, code step "Enter the code" | 1 Verification code<br>2 Resend code in … / Send a new code<br>3 Confirm new number | 1 The code sent to the new number<br>2 Ask for a new code<br>3 Finish the change |
| MOB-46 | Profile → "Profile photo" sheet (account with a photo) | 1 Choose a photo<br>2 Remove photo | 1 Pick a new picture<br>2 Take the photo off |
| MOB-47 | Profile scrolled to Contact MDRRMO | 1 Contact MDRRMO row | 1 Call for help with your account |

## Test data and accounts (capture database)

Create a separate database, for example `serbis_capture`, seed it there, and point the API at it only while capturing. Never use `serbis_test_db`.

**Admin**
- 1 super admin.
- 1 staff account with 3–4 sections (for ADM-04).
- 1 staff account on a temporary password (for ADM-03).
- SMS sign-in turned on with fake SMS, for ADM-02.

**Mobile accounts**
- 1 Head of the Family with history.
- 1 barangay hall account (for MOB-14).
- 1 organization still awaiting approval (MOB-08, ADM-34).
- 1 activated organization.

**Service requests**
- At least one in each status: Pending, Booked, Responding, Resolved, Disapproved, Cancelled.
- One Pending Road Clearing request with a site photo (ADM-10, ADM-11).
- One ambulance trip marked "Not transported".

**Ambulance**
- Bookings spread over the week, 2–3 on one day (ADM-20).
- One Pending booking scheduled tomorrow (ADM-17).
- One fully filled trip log (ADM-21–23).

**Borrowing**
- One each of Pending, Approved, Released with handover photos, Returned, and Denied.

**Other**
- Two uploaded documents.
- One sent text blast and the blast code set.
- Emergency hotlines filled in.
- Two announcements.
- On the emulator, at least one guide saved for offline (MOB-31, MOB-34) and notifications from the last few days (MOB-10).
