# TRACKIFY setup and testing

## Set up in XAMPP

Replace the website files in your existing XAMPP project folder. Start Apache and MySQL. In phpMyAdmin, select the database `db_mors_mutual` before importing SQL.

| Database you have | SQL to import, in this order |
| --- | --- |
| Fresh installation | Create `db_mors_mutual`, select it, then import only `sql/db_mors_mutual.sql`. |
| Latest prototype, with rental pricing and the discount checkbox | Back up your database, then import `sql/finish_trackify.sql` once. |
| Earlier Sprint 2, with Start New Rental and no pricing | Import `sql/sprint_2_rental_pricing.sql`, then `sql/finish_trackify.sql`. |
| Sprint 1, before Start New Rental | Import `sql/sprint_2_start_rental.sql`, then `sql/sprint_2_rental_pricing.sql`, then `sql/finish_trackify.sql`. |

Open the project in your browser, such as `http://localhost/trackify/`. Existing accounts and rentals remain in the upgraded database. The initial SQL dump includes example inventory statuses. If a sample scooter has a rented status and no active rental, reconcile its actual status from Scooter Inventory before using it to test availability.

## Test the workflows

1. **Customer** — Choose a stall, future pickup time, duration, and scooter quantity. Check the live available count, submit a reservation, and download the PNG from My Bookings.
2. **Staff / manager** — Open Manage Bookings, approve one reservation after confirming full payment, and reject another. The customer can see both decisions in My Bookings and Booking Updates.
3. **Staff / manager** — Check in an approved booking using its reference during the pickup window, or start a walk-in by entering a name, selecting the verified ID type, and recording cash received. For multiple scooters under one reference, check in each scooter separately. A pager label can be entered and is held until the rental is returned.
4. **Staff / manager** — Open Manage Rentals, extend a ride and record the extension payment, then end the rental with the scooter, ID, and pager return confirmations. Check Rental History and the scooter profile afterward.
5. **Manager** — Open Sales Reports. Check Today, This Week, This Month, This Year, and a past date. Record an operating expense and confirm that the profit total decreases by that amount. Check Audit Log for the entry.

## Operating rules used in this build

- Reservations use Philippine time and a 30-minute pickup window starting at the scheduled time. Pending and approved bookings hold capacity while the pickup window is open or upcoming. Payment is confirmed by staff. A prepaid reservation is counted once in Sales Reports, at approval.
- Each complete hour is ₱200 and a remaining half hour is ₱150. Custom minutes round up to the next 30 minutes for billing. The timer uses the original minutes. Extensions are charged as separate purchases and use the existing rental’s discount where applicable.
- The sales report totals payments posted in the selected Philippine calendar period. Weeks run Monday to Sunday. Profit subtracts expenses recorded for that period. Earlier rentals remain visible in Rental History. Historical quotes from the prototype are not treated as payments.
- Pager labels are tracked in the website. ESP32 and LoRa communication, vibration, and offline base-unit operation require device integration and physical testing. Booking updates and pickup reminders appear in the website.
