=== Aluleth Membership – Paid Memberships & Members-Only Content ===
Contributors: aluleth
Tags: membership, paid memberships, restrict content, members only, paywall
Requires at least: 7.0
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 0.4.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell membership levels with WooCommerce, FluentCart or Aluleth Commerce, offer free sign-ups, restrict content with a teaser and a join card, and give members an area.

== Description ==

Aluleth Membership turns your site into a members' club: people get a level when they buy a product, sign up for free, send a form or when you add them. Your members-only content stays for members, and everyone else sees the beginning with a clean card to join.

No fee on your sales, no add-on to buy for the basics.

**Levels**

* As many levels as you want: free, paid, one-time or subscription.
* A length for each level: no end date, or a number of days, months or years.
* The price text, the description and the "Popular" mark shown on join cards.

**How people join**

* **FluentCart**: buying one of the products you tick gives the level. With a subscription, access follows it: paused when it is paused, kept until the end of the paid period when it is cancelled, ended when it expires. A full refund or a cancelled order ends the access.
* **WooCommerce**: a paid order with one of your products gives the level; a refund or a cancellation ends it. WooCommerce Subscriptions is followed too.
* **Aluleth Commerce**: buying one of the products you tick gives the level; a full refund ends it.
* **Free sign-up** from a join card: name and email, then one click in the email. No password.
* **Aluleth Forms**: sending a form gives the level (after the payment, for a payment form).
* **By hand**, with an end date if you want.

An account is created when the email has none. A refund of one order never takes away an access paid by another.

**Members-only content**

* A page, a post or any content type, from the "Membership" box of the editor.
* Everything in a category, a tag or another taxonomy, or every item of a content type.
* Addresses: `/members/*` sends visitors to your plans page.
* Any block: "Who sees this block" in the block settings.
* Elementor: Advanced › Membership on every widget, container, section and column.
* Shortcode: `[aluleth_members levels="club"]…[/aluleth_members]`, or `show="guests"` for the opposite.
* Menus: "Membership: who sees this item" on every menu item (Appearance › Menus).
* Bricks: Conditions › Aluleth Membership on every element (Member of, Can read this content).

Visitors see the beginning of a post (up to the "More" block, or a number of words) and the join card, the join card only, or are sent to your plans page. Members-only text never reaches them: not in the page, the excerpt, the feed, the comments or the REST API.

**Content released step by step (drip)**

A rule can open its content some days after the member joined: day 0 for the welcome lesson, day 7 for the next one. Until then members see when it opens, and their member area lists what opens soon.

**Free articles of the month (metered paywall)**

Let visitors read a few members-only articles each month (3 by default) before they see the join card, on the content you choose. A line tells them how many they have read. Search engines never get free articles, and pages read this way are never kept by page caches.

**Files for members**

Upload guides, templates or recordings in Content access and choose the levels that can download them. Members find them under "Downloads" in their member area, and you can link one in any content with `[aluleth_file id="12"]`. Files are kept out of the media library, each under a random name that cannot be opened from its address on any server, and they open only through your site after the access check.

**Join card and member area**

* A designed join card with your levels, their price and button, the free sign-up and the sign-in form. Three designs and your button colour.
* A member area: memberships with renewal date and billing link (FluentCart, WooCommerce or Aluleth Commerce), content opened for the member and opening soon, downloads, past memberships, profile.
* Both as a block, an Elementor widget and a shortcode (`[aluleth_join_card]`, `[aluleth_member_area]`).

**Move from another membership plugin**

Import from Paid Memberships Pro, Restrict Content (Kadence Memberships, formerly Restrict Content Pro) and Simple Membership: levels (new ones, or joined to yours), the members who have access today with their start and end dates, and the content rules. Check one member first, or test with the first 10. Nothing is changed or deleted in the other plugin, no email is sent, and you can run it again without doubles.

Payments stay with the other plugin: "Keep following" checks it every hour while it is active, gives the level to its new members and follows renewals and ends, until you are ready to sell with FluentCart, WooCommerce or Aluleth Commerce.

**AI agents (MCP)**

Off by default. When you turn it on in Aluleth › Settings › Access, an AI assistant connected to WordPress (through the MCP Adapter) can read the levels and numbers, prepare levels for you to review, and, only if you allow it, read members and give or end memberships given by hand. It acts with the rights of the WordPress user it signs in with and never ends an access paid in your store.

**Sign-in by email**

Members get a sign-in link by email instead of typing a password. A link works once, for 30 minutes, and opening it shows a "Sign in" button so email scanners can't use it. Administrators and editors always use their password.

**Emails**

Welcome, sign-up confirmation, sign-in link, "ends soon" (7 days before) and "has ended". Edit their text, turn them off, send yourself a test. They go out with your Aluleth email settings and Aluleth SMTP when you use it.

**Contacts**

Members are Aluleth contacts with a "Member: level" tag and "Became a member" / "Membership ended" in their history, shared with Aluleth Forms and Aluleth Popups.

== Installation ==

1. Install and activate the plugin.
2. Go to Aluleth › Membership › Levels and create a level.
3. Choose how people join it: a FluentCart, WooCommerce or Aluleth Commerce product, the free sign-up, a form.
4. In Content access, choose what is for members, or use the "Membership" box when you edit a post. Coming from another membership plugin? Use Import.
5. In Settings, create the member area page and, if you want, a plans page.

== Frequently Asked Questions ==

= Do I need a store plugin (FluentCart, WooCommerce or Aluleth Commerce)? =

No. Without a store, people join with the free sign-up, a form or when you add them. With a store, they join by buying.

= Does it take a fee on my sales? =

No. Payments are made in your store, with your payment provider.

= What happens when a subscription is cancelled? =

The member keeps access until the end of the period already paid, then it ends and they receive the "has ended" email (if it is on).

= Can search engines read members-only content? =

They see what visitors see: the beginning of the post and the join card.

= Are member files safe on Nginx? =

Each file is saved under a long random name ending in .bin, so it is never run or shown as a page, and nobody can guess its address. The folder is also closed to web visits by its .htaccess (Apache, LiteSpeed) and web.config (IIS) rules. Members download a file only through your site, after the access check, with its original name and content. Files uploaded with earlier versions are moved to this form automatically the next time an administrator opens the dashboard (or when someone downloads them).

= I use Paid Memberships Pro, Restrict Content or Simple Membership. Can I move? =

Yes: Aluleth › Membership › Import. Your members keep their WordPress accounts and their dates. Keep the other plugin active while its subscriptions are billed: Aluleth follows it every hour. Restrictions by role or by access level (Restrict Content) are not imported: Aluleth opens content by membership level.

= Why does it create WordPress accounts? =

A membership belongs to a WordPress account. An account is created only when a person buys a membership product in your store, joins a level on which you turned on the free sign-up (the level opens only after they click the link sent to their email), sends an Aluleth form that gives a level, or when you add or import members. Accounts are created with the WordPress function `wp_insert_user()` and always get a low-privilege role: Subscriber, or another role you choose that can do nothing more than read. No account is created for an email that already has one.

= How do members sign in? =

With their password on the usual WordPress sign-in page, or with a sign-in link sent to their email. A link works once and for a limited time (30 minutes when asked for from the site, a few days in a welcome or confirmation email), and opens a page with a button (so email scanners do not use it up). Accounts that can write posts (contributors, authors, editors, administrators) never sign in with a link. Plugins that block sign-ins through the `wp_authenticate_user` filter also block the links.

= What is deleted when I uninstall? =

Nothing, unless you tick it in Aluleth › Settings. WordPress accounts are never deleted.

== External services ==

Aluleth Membership itself does not contact any outside service. Levels, members, rules, sign-in links and member emails stay on your site; member emails go through your site's own email sending. Purchases are read from FluentCart, WooCommerce or Aluleth Commerce on your site.

= Shared Aluleth screens =

Aluleth Membership comes with the screens shared by all Aluleth plugins (Aluleth › Settings and Aluleth › Connections). They are mostly used by Aluleth Forms and the other Aluleth plugins. None of the services below is contacted unless you paste your own key or address in one of these screens. With Aluleth Membership alone, that is only when you click a "Save and check", "Connect" or "Send a test" button there, and, once captcha keys are saved, when you open Aluleth › Settings › Security: it shows the service's test check, which loads its script in your browser.

* Mailchimp (`*.api.mailchimp.com`), email marketing. When you connect your account in Aluleth › Connections, your API key is sent to read your lists, tags and fields. [Terms of use](https://mailchimp.com/legal/terms/), [Privacy policy](https://www.intuit.com/privacy/statement/).
* Brevo (`api.brevo.com`), email marketing. Same use and data as Mailchimp. [Terms of use](https://www.brevo.com/legal/termsofuse/), [Privacy policy](https://www.brevo.com/legal/privacypolicy/).
* MailerLite (`connect.mailerlite.com`), email marketing. Same use and data. [Terms of service](https://www.mailerlite.com/legal/terms-of-service), [Privacy policy](https://www.mailerlite.com/legal/privacy-policy).
* Kit (`api.kit.com`), email marketing. Same use and data. [Terms of service](https://kit.com/terms), [Privacy policy](https://kit.com/privacy).
* Mautic, marketing automation that you host yourself: the address of your own Mautic and its API user, sent only to that address. [Mautic privacy policy](https://mautic.org/privacy-policy/).
* Telegram (`api.telegram.org`), messaging. When you connect a bot, its token is sent to read the bot's name and the chats that wrote to it. [Terms of service](https://telegram.org/tos), [Privacy policy](https://telegram.org/privacy).
* Discord (`https://discord.com/api/webhooks/…`; the `discordapp.com`, `canary.discord.com` and `ptb.discord.com` forms of the same address are accepted), chat. A message is posted to the channel webhook address you paste, and nowhere else. It is sent only when another Aluleth plugin sends an alert (for example, with Aluleth Forms, a new form entry with its answers and a link to it), or when you click "Send a test" (a sample message with no personal data). Aluleth Membership alone sends no message to Discord. [Terms of service](https://discord.com/terms), [Privacy policy](https://discord.com/privacy).
* Slack (`https://hooks.slack.com/services/…`), chat. Same use and data as Discord, to the Slack webhook address you paste. [Terms of service](https://slack.com/terms-of-service/user), [Privacy policy](https://slack.com/trust/privacy/privacy-policy).
* Your own webhook addresses receive the same kind of message, only when another Aluleth plugin sends one or when you click "Send a test".
* Captcha services, in Aluleth › Settings › Security: "Save and check the keys" sends your secret key and a test answer to the service you chose, to tell you whether the keys work. Cloudflare Turnstile (`challenges.cloudflare.com`, for the test check and the key check): [Terms of use](https://www.cloudflare.com/website-terms/), [Privacy policy](https://www.cloudflare.com/privacypolicy/). hCaptcha (`js.hcaptcha.com` for the test check, `api.hcaptcha.com`): [Terms of service](https://www.hcaptcha.com/terms), [Privacy policy](https://www.hcaptcha.com/privacy). Google reCAPTCHA (`www.google.com/recaptcha`, for the test check and the key check): [Terms of service](https://policies.google.com/terms), [Privacy policy](https://policies.google.com/privacy). CleanTalk (`moderate.cleantalk.org`), a short test message with your access key: [Terms of service](https://cleantalk.org/publicoffer), [Privacy policy](https://cleantalk.org/privacy). These services check visitors only on the forms of Aluleth Forms; Aluleth Membership adds no captcha to your pages.
* PayPal: the Payments settings and the PayPal code are only used with Aluleth Forms; they are hidden and never run with Aluleth Membership alone. [User agreement](https://www.paypal.com/webapps/mpp/ua/useragreement-full), [Privacy statement](https://www.paypal.com/webapps/mpp/ua/privacy-full).

== Changelog ==

= 0.4.4 =
* Aluleth Commerce as a store: buying one of the products you tick on a level gives it; a full refund ends it; the member area links to the store account for billing.
* Member files are kept as plain files with a long random name and the .bin extension (no PHP file is written in the uploads folder any more); files kept by 0.4.2 and 0.4.3 are turned into plain files once, the next time an administrator opens the dashboard or someone downloads them.
* Shared Aluleth core 0.18.8: plain CSV import files in `uploads/<plugin>/imports/`, no PHP file written.

= 0.4.3 =
* Member files can no longer be opened from their address on Nginx or any other server: each one is saved under a random .php name behind a line that stops PHP, and only the download link sends it after the access check. Files uploaded with earlier versions are moved to this form automatically.
* Shared Aluleth screens: contact import files are protected the same way, and texts sent to PayPal, Slack, Discord and Telegram are shortened without the PHP mbstring extension.

= 0.4.2 =
* New accounts always get a low-privilege role (one that can do nothing more than read); other roles are no longer offered in Settings.
* The free sign-up and sign-in link forms check a nonce; on pages served from a cache the site script takes a fresh one before sending.
* The sign-in button of a sign-in link checks a nonce, and plugins that block sign-ins through the `wp_authenticate_user` filter also block sign-in links.
* The sign-in link page loads its style with wp_enqueue_style().
* The notice about an older shared core only shows on the Plugins screen, to people who can activate plugins.
* Readme: External services described one by one, with Discord and Slack.

= 0.4.1 =
* Plugin header: the author link now points to the WordPress.org profile.
* Shared Aluleth core 0.18.4.

= 0.4.0 =
* Longer, unique names for the WordPress.org guidelines: the tables are now "aluleth_members" and "aluleth_member_rules", and the settings of levels, menus, files and sign-in links use "_aluleth_" keys. Existing members, rules and settings are moved automatically on update.

= 0.3.4 =
* The member area page and the plans page always open, even when a rule covers them (someone without a membership could not reach their member area).
* For add-ons (Aluleth Membership Pro): a place at the top of the member area, under the membership cards.

= 0.3.3 =
* For add-ons (Aluleth Membership Pro): hooks for sections of the level editor, the plans of the join card, fields of content rules, and the emails of a source.

= 0.3.2 =
* Members list: accesses given by a team (Aluleth Membership Pro) show "Team · team #ID".
* For add-ons: filters for the words of an access source and reference.

= 0.3.1 =
* Import: values that are not level numbers in Restrict Content data are ignored.
* For add-ons (Aluleth Membership Pro): hooks to decide who reads some content and when it opens, to show another locked view, to add tabs to the Membership screen and sections to the member area.

= 0.3.0 =
* Import from Paid Memberships Pro, Restrict Content (Kadence Memberships) and Simple Membership: levels, members with their dates, content rules; check one member, test with 10, run again without doubles.
* Keep following the other plugin while it bills its members: new members, renewals and ends are checked every hour, without double emails.
* AI agents (MCP), off by default: get-overview, create-level, get-members, give-access, end-access.

= 0.2.0 =
* Drip: a rule can open its content some days after the member joined. Members see when it opens, and "Opening soon" in their member area.
* Free articles of the month (metered paywall): visitors read a few members-only articles each month before the join card, on the content you choose.
* Menus: choose who sees each menu item.
* Bricks: Aluleth Membership conditions on every element.
* Files for members: protected downloads by level, "Downloads" in the member area and the `[aluleth_file]` shortcode.

= 0.1.2 =
* A join card placed on a page (block, Elementor widget, shortcode) now says "Choose your membership" instead of the text meant for members-only content.
* The join card preview in the admin keeps its spacing.

= 0.1.1 =
* Elementor: the Membership section (Who sees this) now also appears on headings, text, images, buttons and the other optimized widgets of Elementor 4.

= 0.1.0 =
* First version: levels; access from FluentCart (one-time and subscriptions), WooCommerce (and WooCommerce Subscriptions), the free sign-up, Aluleth Forms and by hand; members-only posts, categories, content types, addresses, blocks, Elementor elements and shortcode; teaser and join card; member area; sign-in links by email; member emails.
