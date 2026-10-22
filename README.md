# Beacon Bookings

A free bookings plugin for WooCommerce. It adds a **Bookable product** type, so rentals, rooms, tours, classes and appointments sell like any other product: the customer picks a date and time, the number of people and, if you want, a room or a staff member, then checks out as usual. This is the same code as the plugin on wordpress.org; install it from there so you get updates:

**https://wordpress.org/plugins/beacon-bookings/**

## What it does

- No double bookings. Availability is checked when the slot is picked, held while it sits in the cart, and checked again at checkout under a MySQL advisory lock (`GET_LOCK`), so two people cannot buy the last slot.
- Capacity per slot or per resource. Capacity is computed as the peak concurrent load across the requested span, so multi-day stays that only partly overlap are counted right. A hotel with 3 rooms stays a hotel with 3 rooms.
- Hourly rentals that run overnight. A kayak can go out at 2 pm and come back two days later; pickup and return fall inside opening hours and closed days in between do not count.
- Duration prices ("4 hours for $80", "a week for $300"). The customer always gets the cheapest mix of prices, and a longer price also caps shorter bookings. Or sell only set lengths at exactly those prices.
- Multi-day and multi-night stays with check-in and check-out labels, minimum and maximum lengths, and a buffer day for cleaning.
- People or items, with a minimum and maximum, optionally each taking a seat, optionally priced per person, and a label you can rename to Canoes or Bikes.
- Resources (rooms, staff, boats) with their own capacity and hours. A resource shared by several products cannot be booked twice.
- Buffers before and after every booking, weekly opening hours with gaps, date overrides for holidays, minimum notice and maximum advance.
- A booking calendar as a block (`beacon-bookings/calendar`) and a shortcode (`[beacon_bookings_calendar]`) showing which products are free on which day.
- A bookings list with filters and one-click confirm, paid, complete or cancel; manual bookings and blocked time.
- Emails for confirmed, received, cancelled and reminder (customer) and new booking (shop), all editable under WooCommerce, Emails, with an .ics file attached.
- Calendar feeds you can subscribe to from Google Calendar, Apple Calendar or Outlook: all bookings, one product or one resource, each behind a key you can regenerate.
- Customers can cancel from My Account within a notice period you set.
- Works with the classic and the block checkout, and with high-performance order storage. Nothing leaves your site; the plugin makes no outbound HTTP calls.
- Uninstall clears the scheduled tasks always and removes the data only when you asked for that in the settings.

Deposits, seasonal pricing and two-way calendar sync are planned for a paid add-on and are not in this repository.

## Where things are

- `includes/engine/` is plain PHP with no WordPress calls: `class-availability.php` (which starts are possible, is this request bookable, how much capacity is left), `class-pricing.php` (the cost arithmetic and the cheapest-mix duration pricing), `class-rules.php` (weekly windows in minutes since midnight, overrides applied in order), `class-config.php` and `class-result.php`.
- `includes/class-repo.php` is the bookings table and the `claim()` method that runs the final availability check under `GET_LOCK` before inserting.
- `includes/class-cart.php` turns a cart line into a hold row that counts against capacity, is renewed while the cart is open and expires on its own.
- `includes/class-rest.php` registers `beacon-bookings/v1/availability` and `beacon-bookings/v1/price` for the booking form.
- `includes/class-product.php` and `class-product-admin.php` are the `WC_Product` subclass and the Booking and Availability tabs.
- `includes/class-ics.php` serves the keyed feeds and the per-booking .ics downloads; `class-email.php`, `class-emails.php` and `templates/emails/` are the `WC_Email` classes.
- `includes/class-cron.php` and `class-schema.php` set up the five-minute and hourly tasks (expired holds, reminders) and the table via `dbDelta`.
- `includes/class-calendar.php` is the block and the shortcode; `blocks/calendar/` the editor side; `assets/js/booking-form.js` the front-end form.
- `uninstall.php`.

## Bugs and ideas

Open an issue here or a thread on the [wordpress.org support forum](https://wordpress.org/support/plugin/beacon-bookings/). Pull requests are welcome.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
