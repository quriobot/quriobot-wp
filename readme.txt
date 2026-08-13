=== Quriobot ===
Contributors: quriobot
Tags: quriobot,chatbots,surveys,support
Requires at least: 4.7
Requires PHP: 5.6
Tested up to: 7.1
Stable tag: 2.9.3
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.en.html

Increase conversion with an easy-to-use chatbot.

== Description ==

Quriobot works for you 24/7. It helps you increase conversion by 28% and lead retrieval rates by up to 21%, without getting in the way of the visitor's experience.

Make Quriobot answer questions of your website visitors, retrieve contact information, help people find what they are looking for, assist them with making reservations, booking or registrations and much more!

You can do all of this for a fraction of the cost of doing it yourself — and you can start for free.

== Installation ==

= Simple installation =

The Quriobot plugin is available to install via the WordPress plugin library and you can install it directly from within your WordPress dashboard.

= Manual installation =

Alternatively, you can install manually:

1. Download the Quriobot plugin as a zip file.
2. Upload the folder `quriobot` to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

= Configuration =

After installation, configure the plugin by visiting Settings -> Quriobot and entering your unique bot path. You can find your path from [Control Room](https://control.quriobot.com) in your bot edit page -> Channels -> Web.
See [Add your Quriobot to your WordPress page](https://academy.quriobot.com/publishing-your-bot-embedding-and-dedicated-pages/add-your-quriobot-to-your-wordpress-page/) for a walkthrough.

== Frequently Asked Questions ==

= Do I need a Quriobot account to use this plugin? =

Yes. You can [sign up for a free Quriobot account](https://control.quriobot.com/signup) which provides access to all our features.

= I've set up Quriobot — what now? =

You can enable, disable and configure all the Quriobot features from within the [Control Room](https://control.quriobot.com). Any changes will be reflected immediately on the site(s) where you have the Quriobot script installed.

For more information on getting started with Quriobot [visit our help center](https://academy.quriobot.com).

= Where can I find out more about Quriobot? =

[Visit our website](https://quriobot.com/) to learn more about Quriobot and how you can use it to improve the conversion on your website.

= What are the Quriobot Terms of Service? =

The full [terms of service are available here](https://quriobot.com/terms) together with other legal and privacy documentation. If you have further questions do not hesitate to reach out to our support.

= Is Quriobot GDPR compliant? =

We’ve built Quriobot with a [privacy by design](https://quriobot.com/gdpr) for our customers to be able to use Quriobot in a GDPR compliant manner.

= How do I remove Quriobot from my site? =

You can uninstall this plugin at any time to remove Quriobot from your site. Uninstalling also deletes the settings and cached data this plugin stored, leaving nothing behind in your database.

= I changed my bot, but my site still shows the old version =

The plugin caches your bot's embed code so it does not have to contact Quriobot on every page load. Saving the settings page clears that cache, so re-saving Settings -> Quriobot will pick up your change immediately.

== Screenshots ==

1. The settings interface. Add your unique Quriobot Path to the empty field, and Save Changes to install the Quriobot script for that site.
2. After saving you will see this success message. Visit [Control Room](https://control.quriobot.com) to configure your bot.

== Changelog ==

= 2.9.3 =
* Fixed the "how to find your bot path" link, which pointed at a retired help-desk article and returned a 404 for everyone.
* Saving the settings page now clears the cached embed code, so a change to your bot appears straight away instead of after the cache expires.
* Uninstalling the plugin now removes its settings and cached data, as the FAQ has always said it would.
* The save confirmation now lists every configured bot, and a blank-but-not-empty bot path is correctly reported as disabled.
* Corrected several wording and typing errors in the plugin description, the FAQ and the settings screen.

= 2.9.2 =
* Tested against WordPress 7.1.
* Replaced the deprecated `Requests` class with the WordPress HTTP API (`wp_remote_get`). The old class has been deprecated since WordPress 6.2 and is scheduled for removal.
* Restored TLS certificate verification for the AMP embed code request.
* Fixed the "Settings" link on the Plugins screen, which pointed at a page that did not exist and was never registered.
* Fixed the settings textareas mangling quotes in saved values on every re-save.
* Declared sanitization callbacks and correct types for both settings.
* Bot paths are now split on any line ending, so paths no longer keep a stray carriage return.
* Fixed a bot with no embed code being cached in a way that printed a stray script tag on every later page load.
* Added a one-minute backoff after a failed API call, so an outage no longer makes every page load issue its own blocking request.
* The widget is now set up on the `wp` action instead of `plugins_loaded`. This stops the AMP plugin reporting Quriobot for checking whether a request is an AMP request too early, makes that check reliable, and keeps the API call off admin, cron and REST requests.
* Changing the bot initialization code now requires the `unfiltered_html` capability, since it is injected as raw JavaScript.
* Pages that embed a logged-in visitor's details are now excluded from full-page caching.
* Corrected the declared minimums to WordPress 4.7 and PHP 5.6, which is what the code actually uses. Automated tests run against WordPress 6.2, 6.9, 7.0 and 7.1.
* Resolved every issue reported by the official Plugin Check tool.

= 2.9.1 =
* Updated to the latest WordPress version.

= 2.9.0 =
* Updated to the latest WordPress version.

= 2.8.2 =
* Updated to the latest WordPress version.

= 2.8.1 =
* Fixed a warning for the pluginc class magic methods.

= 2.7.9 =
* Fixed an issue with SSL verification for the API call.

= 2.7.8 =
* Correctly handle error codes when getting the bot embed codes.

= 2.7.7 =
* Correctly handle error codes when getting the bot embed codes.

= 2.7.6 =
* Use the latest version of the embed code.

= 2.7.5 =
* AMP support.

= 2.6.4 =
* Use the latest version of the embed code.

= 2.6.3 =
* Use the latest version of the embed code.

= 2.6.2 =
* Use the latest version of the embed code.

= 2.6.1 =
* Use the latest version of the embed code.

= 2.6.0 =
* WP Rocket Compatibility fixes.

= 2.5.10 =
* Use the latest version of the embed code.

= 2.5.7 =
* Use the latest version of the embed code.

= 2.5.6 =
* DO NOT USE THIS VERSION - has wrong SRI hash.

= 2.5.5 =
* Use the latest version of the embed code.

= 2.5.4 =
* Use the latest version of the embed code.

= 2.5.3 =
* Use the latest version of the embed code.

= 2.5.2 =
* Use the latest version of the embed code.

= 2.5.1 =
* Use the latest version of the embed code.

= 2.5.0 =
* Use the latest version of the embed code.

= 2.4.11 =
* Use the latest version of the embed code.

= 2.4.10 =
* Fix user ID deprecation warning.

= 2.4.9 =
* Use the latest version of the embed code.

= 2.4.8 =
* Use the latest version of the embed code.

= 2.4.7 =
* Use the latest version of the embed code.

= 2.4.6 =
* Use the latest version of the embed code.

= 2.4.5 =
* Use the latest version of the embed code.

= 2.4.4 =
* Use the latest version of the embed code.

= 2.4.0 =
* Pass the current user information to the Quriobot visitor context

= 2.3.4 =
* Fixes for the support for the gtranslate.io wordpress plugin's current selected language

= 2.3.0 =
* Add support for the gtranslate.io wordpress plugin's current selected language

= 2.2.10 =
* Use the latest version of the embed code.

= 2.2.9 =
* Use the latest version of the embed code.

= 2.2.7 =
* Use the latest version of the embed code.

= 2.2.6 =
* Use the latest version of the embed code.

= 2.2.5 =
* Use the latest version of the embed code.

= 2.2.4 =
* Use the latest version of the embed code.

= 2.2.3 =
* Use the latest version of the embed code.

= 2.2.2 =
* Use the latest version of the embed code.

= 2.2.1 =
* Fix an issue when embedding multiple bots.

= 2.2.0 =
* Use the latest version of the embed code.

= 2.1.0 =
* Move the script to head to improve the load speed

= 2.0.1 =
* Fix an incompatibility issue with PHP, set the minimum required PHP version to 5.3.0.

= 2.0.0 =
* Use the new, improved version of the embed code.

= 1.0.13 =
* Update tested WP version.

= 1.0.11 =
* Update tested WP version.

= 1.0.10 =
* Respect the current site language.

= 1.0.5 =
* Initial release.

== Upgrade Notice ==

= 2.9.3 =
Fixes a broken documentation link shown on the settings page, and clears the cached embed code when you save your settings so bot changes appear immediately. Deleting the plugin now also removes its settings and cached data.

= 2.9.2 =
Restores TLS certificate verification for the AMP embed request and replaces a WordPress API deprecated since 6.2. Recommended for all sites.
