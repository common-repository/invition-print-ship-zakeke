=== Printeers Print & Ship Zakeke Extension ===
Contributors: Printeers
Tags: Printeers, Invition, Zakeke, Designer, Print on demand, phone case, dropship
Requires at least: 5.1
Tested up to: 6.0
Requires PHP: 7.1
Stable Tag: trunk
License: Modified BSD License
License URI: https://opensource.org/licenses/BSD-3-Clause
 
This plugin is an extension to the Printeers Print & Ship plugin. Add a phone case customizer to your website.
 
== Description ==
 
The Zakeke extension adds Zakeke functionality to the Printeers Print & Ship plugin. This extension can't run without the Printeers Print & Ship plugin and the Zakeke plugin. The plugin adds the following functionality:

= Add products to Zakeke =
If you want to add a phone case designer to a product, go to Printeers Print & Ship > Create a product > Simple product. Select the products you wish to add and select 'Add as Zakeke products'. The product is added to your WooCommerce and automatically imported to your Zakeke account. When you enable this product on your webshop, you will see that the add to cart button is replaced by a customize button. Your user can now design the product. 

= Download print images =
When you create a product like above, the plugin will recognize that it's both an Printeers and a Zakeke product and will automatically download the design when someone places an order. If Zakeke is not ready generating the print image, it's tried again automatically every 5 minutes through the WordPress cronjob. When the plugin is ready downloading images, the plugin changes the order status to the status set in Printeers Print & Ship.

= Extra order status: Ready for production =
To make automatic processing possible, an extra order status is added when the Zakeke extension is active. Change the Printeers Print & Ship setting to 'Ready for production'. The Zakeke extension will check for orders with the status processing and change the status to the Printeers Print & Ship setting when ready.

== Screenshots ==
1. Add a phone case desginer to your website

== Installation ==
 
There are three WordPress plugins required to make everything work together:

1. Printeers Print & Ship
2. Zakeke Online Product customizer
3. This plugin

Install these plugins first. Make sure you have a subscription at Zakeke and you have partner credentials from Printeers. In the Printeers Print & Ship plugin, go to settings and change the order status to 'Ready for production'. This is neccessary to prevent the software from trying to make the order when the print image is not available yet. How this works:

1. A user places an order
2. The order is paid and changed to the status 'Processing'.
3. This extension recognizes that an order is paid and contains an Printeers & Zakeke product. The designs are downloaded automatically.
4. When all designs are downloaded, the order status is changed to 'Ready for production'. 
5. Printeers Print & Ship knows that the order is ready for production and the order is transfered to Printeers automatically.

== Changelog ==
= 1.5.3 =
* Rename from Invition to Printeers

= 1.5.2 =
* Bugfix: Replaced array_key_exists for property_exists in client

= 1.5.1 =
* Bugfix: changed TaskID to taskID

= 1.5 =
* Bugfix: Invalid item can block the queue

= 1.4.2 =
* Added bulk action to product list: schedule a product for reimport to Zakeke

= 1.4.1 =
* Added product name to debug information for when Zakeke can't import

= 1.4 =
* Added 'needs update' flag to products
* Overwrite Zakeke product through API when product has 'needs update' flag

= 1.3 =
* Added a changelog
* Removed settings from Printeers Print & Ship, now fetches settings directly from Zakeke plugin
* Replaced curl with wp_remote_post to comply with WordPress plugin guidelines

= 1.2.1 =
* Added a changelog
