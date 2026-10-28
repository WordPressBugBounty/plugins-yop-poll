=== YOP Poll ===
Contributors: yourownprogrammer
Donate Link: https://www.yop-poll.com
Tags: create poll, poll plugin, poll, voting, WordPress poll
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 7.1.0
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

= Why does a voter see "Too many votes from this device"? =

To stop a script from flooding a poll, each poll accepts at most 10 votes a minute from one IP address. A vote past that is refused with this message (HTTP 429), and the next minute it is accepted again. It is separate from the vote limits you set on a poll, and refusals are recorded in the logs.

Visitors who share one address, such as a school or an office behind one connection, can reach it during a busy minute. A developer can raise it, or turn it off by returning 0, with the `yop_poll_vote_rate_limit` filter:

`add_filter( 'yop_poll_vote_rate_limit', function ( $rule ) { $rule['limit'] = 60; return $rule; } );`

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

= 7.1.0 =
* **Faster polls.** Polls load quicker and use less of your visitors' data. Search engines can now read your poll questions and answers too.
* **New: Elementor support.** You can add a poll to any page with the new YOP Poll widget for Elementor. When you save a poll, YOP Poll now shows you how to add it to a page in the editor your site uses.
* **Simpler styling.** The Style tab is organised by the parts of a poll, so it's easier to find what you want to change. Pick one Accent colour and every selected answer and button uses it. Polls you've already styled keep their colours.
* **Easier integrations.** Settings → Integrations now shows all services in a grid you can search.
* **See votes waiting to be counted.** Votes that aren't counted yet, such as ones waiting for email confirmation, have their own tab in the votes list. The results page shows how many are waiting.
* **Better permissions.** Authors can only manage the polls they created, the same way posts work in WordPress. Only administrators can open Settings. The keys and passwords you save in Settings are also better protected.
* **Clearer messages for voters.** If someone has already voted or has no votes left, the poll now tells them so instead of showing "Vote not allowed". The "Limit votes per person" option now only applies when voters have to sign in to vote.
* **Fixed:** Saving a poll no longer deletes answers that voters added, or the votes on those answers.
* **Fixed:** Signing in to vote no longer ends with a "Login cancelled" error.
* **Fixed:** "New vote" emails are sent again for polls where the "From" field wasn't set to an email address.
* **Fixed:** If you upgraded from version 6, your old email tags and the "show results after the end date" setting work again.
* **Safer exports.** Exported vote files now open safely in Excel and other spreadsheet apps.
* **Correct voter addresses behind Cloudflare or similar services.** With "Trust proxy headers" turned on, YOP Poll now uses each voter's real address.
* **Cleanup.** The old picture captcha has been removed. Use the Built-in check instead, which gives you the same protection. We also removed some unused code behind the scenes.

= 7.0.13 =
* Fixed "Show results to: Registered" being ignored. On a poll that shows its results only to registered users, guests could still see the results after voting. Guests now see only the thank-you message and no Results button, while signed-in visitors see the results as before. A visitor who signs in from the poll's login popup and then votes now sees the results right away, without reloading the page.

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

= 7.0.9 and earlier =
* See changelog.txt for the complete history, including the 7.0.0 rewrite and all of 6.x.
