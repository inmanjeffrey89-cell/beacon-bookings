=== Beacon Bookings – Bookings & Rentals for WooCommerce ===
Contributors: beaconevents
Tags: woocommerce bookings, rental, booking calendar, appointments, bookable products
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WooCommerce bookings: sell rentals, rooms, tours, classes and appointments as products, with a booking calendar and resources.

== Description ==

Beacon Bookings adds a **Bookable product** type to WooCommerce. Customers pick a date (and time), the number of people and, if you want, a room or a staff member, then check out like any other product. You get a bookings list, a calendar, emails with calendar files, and feeds you can subscribe to from Google Calendar.

It was built from what real store owners ask for in booking-plugin reviews. Everything they asked for is in the free plugin:

* **No double bookings.** Availability is checked when the slot is picked, held while it sits in the cart, and checked again under a lock at checkout. Two people can't buy the last slot.
* **Real capacity.** Capacity per slot, or per resource. Overlapping multi-day stays are counted correctly (a hotel with 3 rooms stays a hotel with 3 rooms).
* **Hourly rentals that run overnight.** A kayak or a trailer can go out at 2 PM and come back two days later: pickup and return fall inside your opening hours, closed days in between don't count, and the item stays booked the whole time.
* **Prices for longer rentals.** "4 hours for $80", "a week for $300": customers always get the cheapest mix, so a longer price also caps shorter bookings. Or sell only set lengths, like a 2, 4 or 8 hour charter or a 3, 5 or 7 day dumpster, at exactly those prices.
* **Multi-day and multi-night.** Start and end dates with a check-in / check-out label, minimum and maximum lengths, a buffer day for cleaning.
* **People or items.** Minimum and maximum, optionally each one takes a seat (or a kayak), optionally the price is per person, and you can rename the count to Canoes, Kayaks or Bikes.
* **Resources.** Rooms, staff, boats, bikes. Each has its own capacity and can have its own hours. A resource shared by several products can't be booked twice.
* **Buffers** before and after every booking, in minutes or days.
* **Hours that make sense.** Weekly opening hours with gaps, date overrides for holidays and special days. Later rules win. Nothing hidden.
* **Minimum notice and maximum advance.**
* **One calendar for everything.** A `[beacon_bookings_calendar]` shortcode and a block show which products are free on which day.
* **Reporting-friendly list.** Filter bookings by status, product and date; confirm, mark paid, complete or cancel in one click; bulk actions.
* **Emails that arrive.** Confirmed, received, cancelled, reminder (customer) and new booking (you), all editable under WooCommerce → Emails. Confirmations carry an .ics file.
* **Calendar feeds.** Subscribe to all bookings, one product or one resource from Google Calendar, Apple Calendar or Outlook.
* **Customers can cancel** from My Account, within a notice period you set. The slot frees up; refunds stay your call.
* **Manual bookings and blocked time** from Bookings → Add Booking.
* **Clean uninstall** when you ask for it.
* Works with the classic and the block checkout, and with high-performance order storage.

Beacon Bookings is free, with no add-ons required for any of the above.

== Installation ==

1. Install and activate WooCommerce, then install and activate Beacon Bookings.
2. Products → Add New → set the product type to **Bookable product**.
3. On the **Booking** tab set the block unit (minutes, hours, days or nights), the block length and the cost per block.
4. On the **Availability** tab add weekly hours and any date overrides.
5. Publish. The product page shows the booking calendar.

Bookings appear under **Bookings** in the admin menu. Emails are under WooCommerce → Settings → Emails. Feeds and cancellation rules are under Bookings → Settings.

== Frequently Asked Questions ==

= Does it work with the block-based cart and checkout? =

Yes. Bookings are held while they sit in the cart and become bookings when the order is created, with either checkout.

= What happens if someone leaves a slot in their cart and walks away? =

The slot is held for the number of minutes you set (default 15) and the hold is renewed while they stay on the cart or checkout page. After that it frees up automatically.

= How do resources work? =

A resource is anything a booking occupies: a room, a person, a boat. Give it a capacity (a room: 1; a pool with 8 lanes: 8) and, if it has its own hours, tick "own hours". On the product, choose whether the customer picks a resource or one is assigned automatically. A resource attached to several products is never double-booked across them.

= Can I take deposits or charge different prices per resource? =

Resources can carry an extra cost. Deposits and seasonal pricing are on the roadmap for a paid add-on; the core features above will always stay free.

= Can I block time off? =

Yes: Bookings → Add Booking, leave the customer empty. Or add a date override on the product.

= Does the calendar sync two ways with Google Calendar? =

The plugin publishes feeds you can subscribe to (one way, from your shop into your calendar). Two-way sync is planned for the paid add-on.

= Does it call any external service? =

No. Nothing leaves your site. Calendar feeds are read by whatever calendar app you point at them, protected by a key you can regenerate.

== Screenshots ==

1. The booking form on a product page: calendar, time slots, people, live total.
2. Bookings → All Bookings with filters and one-click actions.
3. Bookings → Calendar.
4. The Booking tab of a bookable product.
5. Availability: weekly hours and date overrides.
6. Resources with their own capacity and hours.

== Changelog ==

= 0.3.0 =
* Duration prices: "4 hours = 80", "1 week = 300" (Booking product → Booking → Duration prices). Customers always get the cheapest mix, and a longer price also caps shorter bookings. The product price lists them.
* "Only offer these lengths": customers choose one of the priced lengths (a 2, 4 or 8 hour charter; a 3, 5 or 7 day dumpster) at exactly that price; other lengths are refused.

= 0.2.2 =
* The booking form looks right on block themes (Twenty Twenty-Four, Twenty Twenty-Five): the Book now button uses the theme's button style and the fields have a comfortable size. Classic themes are unchanged.

= 0.2.1 =
* Name what customers count: "Canoes", "Kayaks" or "Bikes" instead of "People" (Booking product → People → Label shown to customers). Used on the booking form, in the cart, on orders and in emails.

= 0.2.0 =
* Overnight and multi-day hourly rentals: tick "Overnight rentals" on an hourly product and a booking can run past closing time and over several days. Customers pick up and return during your opening hours; closed days in between don't matter.
* Lengths of a day or more read as "2 days" in the duration list, and time slots show the return date.

= 0.1.4 =
* Listing wording and tags. No code changes.

= 0.1.3 =
* Review fixes: all stored keys, post types and menu slugs now use the full plugin prefix; the bookings list script is enqueued; request data is sanitised before it reaches extension hooks.

= 0.1.2 =
* Fix: reminder and confirmation emails could fail with a fatal error when sent from WP-Cron or front-end checkout (the .ics attachment used an admin-only function).

= 0.1.1 =
* Extension points for add-ons: cost, cart charge, product tabs/panels/save, row actions, email rows, account rows.
* The live total now prices the selected date (seasonal rules can use it).

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.1.0 =
First release.
