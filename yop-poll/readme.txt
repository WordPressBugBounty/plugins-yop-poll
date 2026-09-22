=== YOP Poll ===
Contributors: yourownprogrammer
Donate Link: https://www.yop-poll.com
Tags: create poll, poll plugin, poll, voting, WordPress poll
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 7.0.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The flexible WordPress poll plugin — rebuilt for speed, security, and ease of use.

== Description ==

YOP Poll is back — completely rebuilt from the ground up.

After more than a decade of powering polls on WordPress sites, YOP Poll has been rewritten from scratch using modern web technologies. The result is a faster, more secure, and dramatically easier-to-use plugin — without losing any of the flexibility that made YOP Poll the go-to polling solution for WordPress.

**Faster, end to end.** The new admin interface responds instantly to your actions. Creating a poll, editing answers, switching templates, viewing results — everything happens in real time, with no full-page reloads slowing you down.

**More secure by design.** Internal data handling has been modernized to follow current security best practices.

**Easier for everyone.** The admin has been redesigned around how people actually build polls. Clear flows, sensible defaults, and fewer clicks to go from idea to published poll — whether you're running a single quick poll or managing dozens of campaigns at once.

**Safe to upgrade.** All your existing polls, votes, templates, and settings migrate automatically. Nothing to export, nothing to rebuild — just install the new version and pick up where you left off.

= Everything you can do with YOP Poll =

* Run unlimited polls at the same time, with no artificial limits
* Schedule polls to start and end automatically — perfect for time-bound campaigns
* Choose between single-choice and multiple-choice questions
* Allow voters to add their own "Other" answers
* Sort answers in any order — exact, alphabetical, by votes, ascending, or descending
* Display results before voting, after voting, on a custom date, after the poll ends, or never
* Show results as numbers, percentages, or both
* Collect extra information from voters with custom fields (name, email, age, anything you need)
* Set who can vote: guests, registered users, or both
* Block voters by cookie, IP, or username
* Display polls anywhere with shortcodes, widgets, or by poll ID
* Show a random active poll, the latest poll, or any specific poll
* Browse a full archive of past polls with statistics
* View detailed logs of every vote, with sorting, search, and export
* Manage bans by email, username, or IP — globally or per poll
* Built-in protection against spam votes, including Cloudflare Turnstile support

== Installation ==

= Automatic installation (recommended) =

1. In your WordPress admin, go to **Plugins → Add New**
2. Search for "YOP Poll"
3. Click **Install Now**, then **Activate**
4. You'll find the new **YOP Poll** menu in your dashboard sidebar

= Manual installation =

1. Download the YOP Poll plugin .zip file from WordPress.org
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**
3. Choose the .zip file and click **Install Now**, then **Activate**
4. You'll find the new **YOP Poll** menu in your dashboard sidebar

= Upgrading from an earlier version =

If you're upgrading from YOP Poll 6.x, all your existing polls, votes, templates, and settings will migrate automatically the first time you activate version 7.0. No manual export or import is needed.

We strongly recommend backing up your database before any major plugin update — but the migration is designed to be safe and reversible.

== Frequently Asked Questions ==

= How do I create a poll? =

Go to **YOP Poll → Add New** in your WordPress admin. Fill in the question and answers, choose your start and end dates, and adjust the settings for results, vote permissions, and display however you like. Click **Save** and your poll is ready to use.

= How do I add a poll to a page or post? =

Every poll has a shortcode you can copy and paste anywhere on your site — pages, posts, widgets, or template files. You'll find the shortcode for each poll on the **All Polls** page, in the dedicated shortcode column.

= Are there shortcuts for displaying polls? =

Yes. You can use these built-in shortcodes anywhere on your site:

* `[yop_poll id="-1"]` — displays the current active poll
* `[yop_poll id="-2"]` — displays the most recent poll
* `[yop_poll id="-3"]` — displays a random active poll
* `[yop_poll_archive max=0 sort="date_added|num_votes" sortdir="asc|desc"]` — displays a full archive of polls

= Can I run more than one poll at the same time? =

Yes. YOP Poll has no limit on the number of polls you can run simultaneously. You can also schedule polls to start and end on specific dates, so you can queue up campaigns to run one after another.

= Can I collect extra information from voters? =

Yes. When creating or editing a poll, add Custom Field elements to ask voters for things like name, email, age, or anything else you need. You can export this data from the votes page later.

= How do I see the results of a poll? =

On the **All Polls** page, each poll has a **View Results** option that shows the full results — vote counts, percentages, and any custom field responses collected from voters.

The **Logs** option is separate: it records every voting attempt on your site (successful or not), which is useful for auditing, troubleshooting, or investigating suspicious activity. It's not where you go to see poll results.

= How do I show results only after the poll ends? =

Edit the poll, find the **View Results** setting, choose **After Poll End Date**, and save. Voters will see only the question until the poll closes.

= Can I have more than one question per poll? =

No — each poll has a single question. If you need multiple questions, create a separate poll for each one.

= My existing polls — will they still work after upgrading to 7.0? =

Yes. Version 7.0 is a complete rewrite, but it's a drop-in upgrade. All your polls, votes, templates, custom fields, and settings migrate automatically the first time you activate the new version. Your existing shortcodes will continue to work without any changes.

= Signed-in visitors cannot vote after updating to 7.0.11 =

Version 7.0.11 changed how a vote from a signed-in visitor reaches your site. It now goes through WordPress's own `admin-ajax.php`, rather than through the WordPress REST API, so that no account-wide security token has to be placed on the page. Voting as a guest is unchanged.

A few security plugins and hosting firewall rules restrict `admin-ajax.php`, usually as a measure against brute-force or scraping traffic. On those sites, signed-in voting will stop working after the update while guest voting carries on normally — which is the symptom to look for. Allowing `admin-ajax.php` resolves it. The two actions the plugin uses are named `yop_poll_vote` and `yop_poll_results`, and both are refused outright for visitors who are not signed in.

= Where do I report a security issue? =

Please report security issues through the [Patchstack Vulnerability Disclosure Program](https://patchstack.com/database/vdp/68604c4b-5842-4926-b580-d14926a1a458). The Patchstack team will help with verification, CVE assignment, and notifying us of the issue.

== Screenshots ==

1. A live YOP Poll on the front end — clean, colorful, and mobile-friendly
2. Choose from multiple poll designs
3. Add your question and answers with a real-time preview as you type
4. Configure poll behavior — scheduling, statistics, and notifications
5. Decide who can vote and how to prevent duplicates
6. Control exactly when and how results are shown
7. Run multiple polls at the same time — track votes, status, and schedules at a glance

== Changelog ==

The full notes for every release, in the same wording, are in changelog.txt.

= 7.0.12 =
* Fixed "Limit Number Of Votes per User" still being applied after Guest voting was turned on. The builder hides that setting while guests can vote, but signed-in visitors were still being stopped at the limit. The limit now applies only when the setting is visible, and turns back on if Guest voting is switched off again.

= 7.0.11 =
* Security release, recommended for all sites. Fixes a vulnerability in the "Sign in with WordPress" voting option that could expose a signed-in user's security token. Polls now use a limited, poll-specific token instead.
* Improved sanitization of poll content, voter input and spreadsheet exports. Existing content is cleaned automatically on update.
* Fixed several vote-counting issues and made voting limits, bans and blocking settings work as intended.
* Vote records, exports and email notifications now match the counted results.
* Strengthened server-side validation of votes, custom CSS and links.
* Fixed issues with time zones, hidden results, voter privacy and deleted data reappearing.
* Tightened permissions on the Settings page and when saving polls. Fixed a conflict between automatic results reset and poll edits.
* Fixed a display issue with text fields in the block editor preview.
* Fixed voter-added answers and their votes being lost when a poll was edited.

= 7.0.10 =
* Fixed polls not displaying on sites that delay or defer JavaScript.
* Fixed polls incorrectly showing empty results to some signed-in visitors.
* Fixed several captcha-related voting and display issues.
* Fixed problems with new-vote notification emails.
* Fixed minor issues with how results are displayed.

= 7.0.9 =
* Added an Elementor widget and the option to use an image as the poll background. Fixed polls stuck as "Draft" while the poll's own Options tab showed "Published".

= 7.0.8 =
* Fixed the admin results screen listing answers in their saved order instead of by popularity.

= 7.0.7 =
* Added a customizable "Submitting…" label for the vote button, under Settings → Messages.

= 7.0.6 =
* Fixed a security issue that let someone get around "Block Voters by IP" by faking their IP address. Thanks to Melina Lentini (M3l3n) for the responsible disclosure.
* Fixed the poll builder sometimes erroring on save, and votes being lost when a visitor created an account in order to vote.

= 7.0.5 =
* Added a visual editor for the new-vote notification email. Ended polls now show their results when results are set to display after voting.

= 7.0.4 =
* Added an "Always show the Other text box" option. Fixed answer text not being editable in the builder, "Other" votes not appearing in results, ended polls still accepting votes after upgrading from 6.x, and several CSV export problems.

= 7.0.0 and earlier =
* See changelog.txt for the complete history, including the 7.0.0 rewrite and all of 6.x.

== Upgrade Notice ==

= 7.0.12 =
Fixes a vote limit that kept applying to signed-in visitors on polls open to guests, although the setting was hidden.

= 7.0.11 =
Security release. Closes a weakness in the "Sign in with WordPress" voting option that could expose a signed-in administrator's account-wide security token to another website, and removes that token from the poll entirely. Recommended for any site whose polls offer WordPress sign-in.
