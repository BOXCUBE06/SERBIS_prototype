---
title: "SERBIS Admin Panel — User Manual"
subtitle: "MDRRMO Echague Disaster Communication & Service Coordination System"
date: "October 2026"
---

# Introduction

The SERBIS admin panel is where MDRRMO Echague staff handle resident requests, ambulance bookings, equipment loans, vehicles, responders, resident accounts and SMS blasts. Open it in a desktop browser and sign in with your staff email and password.

The sidebar groups every page under Overview, Requests, Resources, Community, Configuration and System. You only see the pages your account has access to. A super admin decides which pages each account gets. Pages marked **(Super admin only)** are hidden from everyone else.

# Signing in

Signs you in to the panel.

1. Enter your staff email and password.
2. Tick **Keep me signed in on this device** only on your own computer.
3. Click **Sign in**. If you forgot your password, ask another admin to reset it.

![Sign-in page](img/01-login.png)

To sign out, click your name at the bottom of the sidebar, then click **Logout**.

![Sign-out confirmation](img/34-sign-out.png)

# Overview

## Dashboard

Today's picture: available units and responders, ongoing trips, overdue loans and open requests.

1. Read the summary cards at the top.
2. Switch between the **Services** and **Ambulance** tabs under Open Requests.
3. Click a row to open that request.

![Dashboard](img/02-dashboard.png)

## Analytics

Request and service trends over a chosen period.

1. Set the date range (From / To) and filters in the bar at the top.
2. Scroll through the sections; the filter bar stays in view.
3. Switch a section between chart and table view to read exact numbers.

![Analytics](img/03-analytics.png)

# Requests

## Resident Requests

Service requests filed by residents, from Pending to Resolved.

1. Pick a status tab or search to find a request.
2. Click the row to open it.
3. Assign a unit or click **Select Responders**, then **Approve** (or **Disapprove** with a reason).
4. Once the work is done, mark it resolved.

![Resident Requests](img/04-resident-requests.png)

![Request detail](img/05-resident-request-detail.png)

To record a request that came in by phone or walk-in, click **Log Service Request** and fill in the form.

![Log Service Request](img/06-log-service-request.png)

## Ambulance Dispatch

Ambulance bookings (Bookings tab) and the trip record of each run (Trip Logs tab).

1. On **Bookings**, click a pending request to open it.
2. Click **Select** next to Unit and choose an ambulance.
3. Click **Approve & Dispatch**. A trip record is created automatically.
4. Use **Reschedule** or **Disapprove** when the booking cannot go ahead as filed.

![Ambulance Dispatch](img/07-ambulance-dispatch.png)

![Ambulance request detail](img/08-ambulance-request-detail.png)

![Log an ambulance request](img/09-ambulance-log-request.png)

On **Trip Logs**, filter by status, date or vehicle. Click **Ambulance Trip Record** to add a trip by hand.

![Trip Logs](img/10-ambulance-trip-records.png)

### Ambulance schedule

The schedule shows every ambulance's trips for one day, or for the whole month.

1. On **Bookings**, click **Day view**. The schedule opens on today.
2. Read the timeline: one row per ambulance and one block per trip. The red **Now** line marks the current time. The **Unassigned** row holds requests that still need a unit.
3. Click a trip block, or a row under **Trips on this day**, to highlight it in both places.
4. Use the arrows or **Today** to change the day. Click **Month** to see the whole month, then click a date to open that day.

<!-- TODO: screenshots pending: img/37-ambulance-schedule-day.png (Day view) and img/38-ambulance-schedule-month.png (Month view). -->

## Equipment Borrowing

Equipment loans from request to return, with overdue ones flagged.

1. Click a request to see the items, purpose and due date.
2. Click **Mark as Released to Resident** when the items leave the office.
3. Click **Confirm return** in the list when they come back.

![Equipment Borrowing](img/11-equipment-borrowing.png)

![Borrowing detail](img/12-borrowing-detail.png)

# Resources

## Vehicles

The fleet: ambulances, rescue vehicles, fire trucks and boats, with their status.

1. Click **Add Unit**.
2. Enter the unit details and save.
3. Click a row to edit a unit or change its status.

![Vehicles](img/13-vehicles.png)

![Add a unit](img/14-add-vehicle.png)

## Responders

Staff who can be assigned to requests.

1. Click **Add Responder**.
2. Enter their details and save.
3. Click a row to edit a responder or change their availability.

![Responders](img/15-responders.png)

![Add a responder](img/16-add-responder.png)

## Resource Management

The equipment catalogue and stock that residents can borrow.

1. Click **Add Equipment**.
2. Enter the name and quantity, then save.
3. Click a row to edit an item's stock.

![Resource Management](img/17-resource-management.png)

![Add equipment](img/18-add-equipment.png)

## Procurement Reference

Borrow requests for items the catalogue does not carry. Read-only: use it to decide what to buy.

1. Review the list of uncatalogued requests.
2. Add any item you acquire in **Resource Management**. Only then can the request be released.

![Procurement Reference](img/19-procurement-reference.png)

# Community

## Accounts

Resident accounts: heads of the family, barangays and organizations.

1. Filter by account type, status or barangay, or search by name.
2. Click a row to open the account.
3. From the account, click **Edit profile**, **Deactivate account** or **Delete account**.

![Accounts](img/20-accounts.png)

![Account detail](img/21-account-detail.png)

To create an account for a resident, click **Add account** and fill in the form.

![Add account](img/22-add-account.png)

## Documents

Files published for residents to download: PDFs and images up to 10 MB.

1. Drop a file onto the upload box, or click it to browse.
2. Click **Mark verified** once the file is checked.
3. Use the actions column to open or delete a file.

![Documents](img/23-documents.png)

## Text Blast (SMS)

Sends an SMS to every resident in the chosen barangays.

1. Pick one or more barangays under **Target audience**.
2. Choose a template or type the message. The segment count shows the cost.
3. Click **Send Blast**, enter the 6-digit text blast code, and confirm.
4. Check **Recent blasts**. A message is delivered only when it shows Sent.

![Text Blast](img/24-text-blast.png)

Click **Text blast code** to change the code that confirms each send.

![Text blast code](img/25-text-blast-code.png)

# Configuration

## Manage Services

The list of services residents can request. Service names and categories are fixed.

1. Filter by status or category.
2. Click a row to edit its description, then click **Save changes**.
3. Use the eye icon to disable or re-enable a service.

![Manage Services](img/26-manage-services.png)

![Edit service](img/27-edit-service.png)

## Service Audience

Which account types can request each service.

1. Tick or untick a box for each service and account type.
2. An unticked service is hidden from that account type in the mobile app.

![Service Audience](img/28-service-audience.png)

## Service Vehicles

Which vehicle types can be sent on each service.

1. Tick the vehicle types allowed for each service.
2. Leave a row unticked to allow any available non-ambulance unit.

![Service Vehicles](img/29-service-vehicles.png)

# System

## Staff Accounts (Super admin only)

Admin panel accounts and the pages each one can open.

1. Click **Add staff account**, fill in the details and save. Pass the temporary password to the person; they must change it at first sign-in.
2. Click **Edit** on a row to change a person's details.
3. Click the **More** button (three dots) on a row, then choose:
   - **Manage access** to pick the pages that account can open, then click **Save access**.
   - **Reset password** if someone forgets theirs.
   - **Close account** when someone leaves. The account is deactivated, not deleted. To bring it back, open **More** on that row and click **Reactivate**.
4. To change several accounts at once, tick their checkboxes, then use **Change access**, **Close accounts** or **Reactivate** in the bar that appears.

You cannot close or select your own account.

![Staff Accounts](img/30-staff-accounts.png)

![More menu](img/35-staff-more-menu.png)

![Bulk actions bar](img/36-staff-bulk-bar.png)

![Add staff account](img/31-add-staff-account.png)

![Access dialog](img/32-staff-access.png)

## Activity Logs

A record of who did what, plus every SMS sent.

1. Open **System Activity** for panel and app actions, or **SMS History** for sent messages.
2. Type in **Search logs...** to find a name or record.

![Activity Logs](img/33-activity-logs.png)
