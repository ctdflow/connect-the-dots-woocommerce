=== Connect the Dots: Delivery Route Planner for WooCommerce ===
Contributors: ctdflow
Tags: delivery route, route planner, route optimization, local delivery, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plan the delivery routes for the orders of one day with a route planner. Choose the delivery date, pick your vans and get the best route for each.

== Description ==

Connect the Dots is a route planner for shops that deliver their own orders. Choose a delivery date, select the orders and the vans, and the plugin plans the delivery routes: which van drives where, in which order, and when it is expected to arrive at every stop.

Use it for local delivery with your own vans, bikes or cars. It works with the orders that are already in your shop, so there is nothing to copy over.

**What it does**

* Finds the orders of a delivery date. The delivery date comes from Order Delivery Date for WooCommerce or Delivery & Pickup Date Time for WooCommerce when one of them is installed. Without one of them, it takes the orders of the last 60 days with the status you choose that are not planned yet, and the orders that a route of a past day did not deliver.
* Plans the routes for all your vans at once, with route optimization that takes the time windows, the opening hours of each van and its capacity into account.
* Shows the routes per van with the expected arrival at every stop, and the orders that could not be planned with the reason, such as an address that cannot be found.
* Adds a Delivery route column to the order list, so you see the van and the arrival time of every order.
* Gives every van a link for the driver. The driver opens it on a phone, sees the stops in order, taps Navigate to open the navigation app and ticks off every delivery. No app and no login are needed.
* Adds an order note with the van and the expected arrival, and a note when the order is delivered. Changing the order status is optional and off by default.
* Plans the next day automatically at a time you choose (paid plans), and leaves a day alone that you already planned yourself. When orders came in after you planned that day, it mails you that they are not on the routes.
* Works with High-Performance Order Storage.

The plugin is available in English and Dutch.

**Free and paid**

You can plan 100 stops per month for free, with every feature of the planner. A stop is one address on one delivery day: planning a day again does not count its addresses twice. The 100 stops are shared with the other ways to use Connect the Dots, such as its own planner in the browser. Automatic planning and more stops need a paid plan. The plugin has no licence key and no locked features of its own. Connect the Dots decides what is free and what is paid when you plan, and the plugin shows you the answer.

**Connect the Dots**

The routes are calculated by Connect the Dots, an online service. To use the plugin you connect your shop to a Connect the Dots account. A new shop starts on the free level. See the External services section for the data that is sent.

== External services ==

This plugin plans routes with the Connect the Dots service. It cannot plan routes without it. The service is run by Connect the Dots, https://ctdflow.com.

**Why it is used and when data is sent**

The plugin sends data to https://api.ctdflow.com when you connect your shop, when you plan a day with the Plan button, when automatic planning runs (if you turned it on), when the plugin reads the result of a plan or your account level, and when it checks which deliveries the driver has ticked off. Nothing is sent when you only install or activate the plugin. The address can be changed with the CTDFLOW_API_URL constant.

**What is sent for every order**

* the order id, as a reference, and the order number the shop shows on invoices
* the delivery address: street with house number, postcode and city (the country of the orders is not sent, because only orders in the country of the depot are planned)
* the time window of the delivery, if the order has one
* the number of items
* how long a stop takes, which is the number of minutes per stop from your settings

**What is sent for every van**

* the name of the van
* its start address and end address
* the times it is available, from and until
* its capacity, if you set one

**What is sent with a plan**

* the delivery date and the time zone of your site
* the web address of your shop
* the depot address and its country, once per plan
* whether the plan was started by hand or by automatic planning

When you connect, the plugin registers your shop at Connect the Dots with the host name of your shop and the return address in your wp-admin that is used to connect. You then sign in at Connect the Dots in your browser and approve the connection. The plugin stores the access it receives in your database and sends it along with its requests.

Every request also carries the version of the plugin and of WordPress, in the User-Agent header.

The plugin never sends customer names, email addresses, phone numbers, amounts or products.

The driver link of a van opens a page on https://api.ctdflow.com that shows the stops with their address and order number, and no customer names or phone numbers. Anyone who has the link can open it until seven days after the delivery day.

Connect the Dots terms of service: https://ctdflow.com/terms
Connect the Dots privacy statement: https://ctdflow.com/privacy

== Installation ==

1. Install and activate WooCommerce, then install this plugin from the Plugins screen, or upload it to the `wp-content/plugins` directory.
2. Activate Connect the Dots: Delivery Route Planner for WooCommerce.
3. Go to WooCommerce, Delivery routes and choose Connect to Connect the Dots. Sign in or create an account and approve the connection.
4. Open the Settings tab. Check the depot address, which starts as the address of your shop, and add your vans with the times they are available.
5. On the Delivery routes tab, choose a delivery date, check the orders and press Plan.

== Frequently Asked Questions ==

= Do I need a Connect the Dots account? =

Yes. The routes are planned by the Connect the Dots service, so the plugin needs a connection to an account. You create the account when you connect, and a new account starts on the free level.

= What is free and what is paid? =

Planning delivery routes is free for 100 stops per month. A stop is one address on one delivery day, and planning a day again does not count its addresses twice. The plugin shows how many stops are left this month before you plan. For more stops or automatic planning you need a paid plan. Connect the Dots decides what is free and what is paid. The plugin has no licence key and does not lock any feature by itself. It only shows what the service answers.

= Which data leaves my shop? =

For every order the order id and order number, the delivery address, the time window and the number of items. For every van its name, addresses, availability and capacity. Never customer names, email addresses, phone numbers, amounts or products. The External services section lists all of it.

= Which delivery date plugins does it work with? =

Order Delivery Date for WooCommerce (the free version) and Delivery & Pickup Date Time for WooCommerce. Without one of them the plugin plans the orders of the last 60 days with the status you choose that are not planned yet, and the orders that a route of a past day did not deliver.

= Does it change the status of my orders? =

Only when you turn that on in the settings. By default the plugin adds a note and some order data, and leaves the status alone.

= Does it work with local pickup? =

Orders with a pickup shipping method are left out, because there is nothing to deliver. So are orders with only virtual products. In the settings you can also choose the shipping methods whose orders are planned.

= What happens when an address cannot be found? =

The other orders are planned anyway. The order with the address that cannot be found is listed under the plan with the reason, so you can correct the address and plan again.

= Does the driver need to log in or install an app? =

No. The driver link opens in the browser of the phone and works without an account.

= What happens to my data when I delete the plugin? =

The plugin removes its settings, its connection and its scheduled tasks. The route data on your orders stays, because it is part of your order history.

== Screenshots ==

1. The Delivery routes screen with the orders of a day.
2. A planned day with the routes per van and the driver links.
3. The settings with the depot, the vans and automatic planning.

== Changelog ==

= 0.1.0 =
* First version.
