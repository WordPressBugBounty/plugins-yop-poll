=== YOP Poll ===
Contributors: yourownprogrammer
Donate Link: https://www.yop-poll.com
Tags: create poll, poll plugin, poll, voting, WordPress poll
Requires at least: 6.5
Tested up to: 6.9
Stable tag: 7.0.0
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

= 7.0.0 =
* Major release: complete rewrite using React and a modern REST API
* Faster, redesigned admin experience with no full-page reloads
* All existing polls, votes, and settings migrate automatically
* Modernized foundation, ready for what's coming next

= 6.5.40 =
* fixed broken link on the poll guide

= 6.5.39 =
* fixed bug with draft polls showing on the frontend

= 6.5.38 =
* fixed security issue

= 6.5.37 =
* fixed notice generated by custom fields char limit

= 6.5.36 =
* added support for Cloudflare Turnstile

= 6.5.35 =
* added max char limit to custom fields

= 6.5.34 =
* fixed issue with polls not displaying when switching from pro to free version

= 6.5.33 =
* removed h5 tag from question html structure

= 6.5.32 =
* fixed issue with scrolling when checking for maximum number of answers allowed

= 6.5.31 =
* accessibility enhancement for polls

= 6.5.30 =
* various design fixes
* fixed issue with builtin captcha failing on multiple attempts

= 6.5.29 =
* fixed issue with built in captcha not working on php 8
* fixed issue with built in captcha allowing multiple votes with the same captcha response

= 6.5.28 =
* fixed issue with voting when other answers option is enabled

= 6.5.27 =
* fixed TOCTOU vulnerability in the voting process
* display newest polls by default on View Polls page


= 6.5.26 =
* remove username and password sanitization for login. wp_signon will handle the sanitization
* added Create Account and Forgot Password links on login modal

= 6.5.25 =
* fixed php warning when using YOP Poll as a widget
* fixed issue with GDPR checkbox not displaying on Brave browser

= 6.5.24 =
* added question and custom field name as column headers in exported votes file
* removed poll name column from exported votes file
* cleaned the exported votes file
* fixed issue with displaying custom field records on results page

= 6.5.23 =
* updated admin javascript to load in footer to prevent conflict with other plugins

= 6.5.22 =
* fixed issue with disabling multiple answers per vote after it has been enabled
* added screen options for Bans page. You can now set the number of records per page
* added yop_poll_vote_recorded_with_details hook for a vote recorded. Poll id and Vote details are passed to the callback

= 6.5.21 =
* added sorting option for Start Date and End Date on View Polls page
* added column for shortcode on View Polls page. You can now get the shortcode for a poll without any extra steps/clicks.
* added screen options for View Logs page. You can now set the number of records per page.

= 6.5.2 =
* added screen options for View Polls page. You can now set the number of polls per page.
* added screen options for View Votes page. You can now set the number of votes per page.
* added Reset Votes option for each poll. You can now reset votes for a poll much easier.

= 6.5.1 =
* fixed issue with voting throwing a notice
* added an option for redirect after vote. You can now set the delay (in seconds) for the redirect
* added yop_poll_vote_recorded hook for a vote recorded. Poll id argument is passed to the callback

= 6.5.0 =
* fix conflict with JNews theme causing issues with "Add Votes Manually" feature
* fix conflict with plugins and themes using datetimepicker

= 6.4.9 =
* fixed voting issue when there are multiple polls on the same page

= 6.4.8 =
* added ajax login when allowing votes from wordpress users
* updated voting flow in facebook browser

= 6.4.7 =
* fixed issue with shortcode popup not showing on certain themes
* fixed issue with displaying current active poll

= 6.4.6 =
* fixed issue with google reCaptcha v2 Checkbox

= 6.4.5 =
* fixed issue with blocking voters per day by reseting at midnight instead of 24 hours from the time of the vote

= 6.4.4 =
* added sanitization for custom headers
* updated 5.x and 4.x importers

= 6.4.3 =
* fixed security issue
* fixed issue with vote button not working inside elementor popups
* added an option to choose if custom headers should be used when getting the ip address of the voters

= 6.4.2 =
* fixed issue with other answers not displaying in view votes details
* fixed issue with other answers not included in exports

= 6.4.1 =
* fixed issue with other answers not being included in notification emails
* added page/post id to the vote data

= 6.4.0 =
* fixed issue that was causing translation files not to load properly

= 6.3.9 =
* fixed issue that was preventing editing polls on windows servers

= 6.3.8 =
* updated sanitization for templates and skins
* fixed issue with total votes and answers not displaying correctly

= 6.3.7 =
* fixed issue with built in captcha
* updated sanitization for built in captcha
* updated the design of built in captcha

= 6.3.6 =
* fixed issue with polls not displaying correctly in widgets
* fixed archive shortcode to only display published polls
* added more sanitization for arrays and objects

= 6.3.5 =
* fixed typo in 4.x importer causing issues on some installs

= 6.3.4 =
* fixed issue with google reCaptcha v2 Invisible
* fixed issue with archive shortcode displaying polls not started when "show" is set to active
* fixed issue with displaying incorrect message when a poll is ended
* added support for hCaptcha
* added more sanitization

= 6.3.3 =
* fixed XSS bugs
* fixed issue with validating email addresses when sending notifications for votes
* added tags for messages - [strong][/strong], [u][/u], [i][/i], [br]
* added tags for elements - [strong][/strong], [u][/u], [i][/i], [br]
* added support for links in the consent text
* added new option for yop_poll_archive shortcode. Now it supports displaying only polls that accept votes. Usage - [yop_poll_archive sort=date_added/num_votes sortdir=asc/desc max=0/number-desired show=active/ended/all]

= 6.3.2 =
* fixed issue with migrating polls from versions lower than 6.0.0

= 6.3.1 =
* fixed XSS bugs CVE-2021-24833, CVE-2021-24834 - Props to Vishnupriya Ilango of Fortinet's FortiGuard Labs
* fixed issue with custom styles not applying to custom fields

= 6.3.0 =
* fixed issue with bans affecting all polls when creating a ban specific to a poll
* added support for %VOTER-EMAIL%, %VOTER-FIRST-NAME%, %VOTER-LAST-NAME%, %VOTER-USERNAME% in both subject and body. These tags can be used only when allowing votes from wordpress users

= 6.2.9 =
* fixed issue with "Ban by Username" not working as expected
* fixed display issue on View Bans page
* added support for %VOTER-EMAIL% in Recipients list when sending email notifications. The tag can be used only when allowing votes from wordpress users

= 6.2.8 =
* fixed XSS bug
* fixed issue with allowed formatting tags for answers not showing when displaying results

= 6.2.7 =
* fixed issue with answers set as default not showing selected
* added an option to choose the location for the notification section. When set to "Bottom" scrolling to the top of the poll is disabled

= 6.2.6 =
* fixed error showing up when activating the plugin via cli
* remove scrolling effect when voting
* added more parameters to [yop_poll_archive]

= 6.2.5 =
* fix issue with notification message not being updated on successfull votes
* added reset for radio and checkbox controls on page refresh

= 6.2.4 =
* fixed issue with GDPR/CCPA checkbox when having multiple polls on the same page
* fixed issue with Results and Get Code icons not showing
* fixed issue with cloning polls

= 6.2.3 =
* fixed issue with [br] tag showing on results page
* added more tags for answers - [strong][/strong], [p][/p], [b][/b], [u][/u], [i][/i]

= 6.2.2 =
* fixed issue with polls loading with ajax
* added %VOTER-FIRST-NAME%, %VOTER-LAST-NAME%, %VOTER-EMAIL%, %VOTER-USERNAME% to new vote email notifications

= 6.2.1 =
* removed 2 options from built in captcha
* updated icons for View Results and Get Shortcode
* fixed issue with duplicate answers when viewing results

= 6.2.0 =
* fixed issue with google reCaptcha loading intermitently when polls are loaded with ajax
* fixed issue with google reCaptcha when allowing votes from guests and wordpress users
* added support for google reCaptcha v3

= 6.1.9 =
* fixed issue with wp login window blocking voting if window is manually closed

= 6.1.8 =
* fixed issue with votes not being deleted when poll is removed
* fixed issue with logs not being deleted when poll is removed
* fixed issue with guest voting and limit number of votes
* fixed issue on edit poll screen that was causing polls to stop displaying when a new template was choosen

= 6.1.7 =
* fixed broken css rule
* added option to keep/remove plugin data on uninstall
* added default message with tags for email notifications

= 6.1.6 =
* fixed issue with blocking voters when wordpress voting is enabled

= 6.1.5 =
* fixed typos
* fixed security issue when previewing a poll
* fixed issue with loading language files
* fixed issue with loader not being shown when voting
* fixed issue with answers displayed below radio/checkbox controls on small screens

= 6.1.4 =
* fixed issue with polls loading in facebook inapp browser
* fixed issue with scroll location when there is an error in voting
* moved voting buttons at the bottom of poll container
* added links to answers when displaying results
* added support for adding custom fields on click

= 6.1.2 =
* fixed conflict with JNews theme
* fixed issue with answers being displayed twice in results
* improved flow for Edit Poll
* fixed XSS bug

= 6.1.1 =
* fixed display issue for Sort Results when "As Defined" is choosed
* removed select2 controls
* improved polls display when a start/end date is choosed
* added option to load polls via ajax
* added support for reCaptcha v2 Invisible

= 6.1.0 =
* fixed issue with limit votes
* fixed issue with other answers when "Show in results" is set to Yes
* fixed issue with fingerprint
* removed extra space on results page when an answer has no votes

= 6.0.9 =
* fixed issue with cloning polls
* fixed issue with editing poll duplicating new elements
* fixed issue with display results tag
* fixed issue with resetting settings when plugin was disabled
* fixed issue with customizing skin throwing an error on saving poll
* fixed issue with results not sorting "View Results" option
* fixed issue with recaptcha
* fixed issue with font size
* fixed issue with color for messages
* fixed issue with tracking ids
* improved email notifications
* added a new option for blocks
* added labels to answers for better user experience

= 6.0.8 =
* added ability to manually add votes
* added support for multisite
* fixed issue with built in captcha not working on nginx environments
* fixed issue with sorting results

= 6.0.7 =
* fixed issue with other answers when resetting votes
* fixed issue with timezones when using block feature

= 6.0.6 =
* fixed issue with blocking voters
* fixed issue with logs
* fixed issue with bans
* fixed issue with settings
* fixed issue with WordPress voting

= 6.0.5 =
* added skins
* redesigned templates
* improved ux for chosing templates
* cleaned add/edit poll screens
* cleaned files structure

= 6.0.4 =
* added ability to search votes
* added ability to delete votes
* added columns for username and email on View Votes screen
* added notifications messages to admin settings
* fixed css issue
* fixed issue with overlapping
* fixed compatibility issue with Elementor
* fixed bug with searching logs

= 6.0.3 =
* added support for reCaptcha v2
* added scroll to thank you/error message after voting
* fixed spacing with total votes
* fixed issue with thank you message not being displayed when GDPR enabled
* fixed XSS vulnerability
* updated notification messages for blocks and limits

= 6.0.2 =
* load plugin js and css only on plugin pages
* fixed issue with exporting custom fields data
* added column for each custom field when exporting votes
* fixed issue with "Show total answers" being set to "Yes" when "Show total votes" is set to "Yes"
* fixed issue with email notifications
* fixed issue with captcha
* added support for poll archive page
* added ability to set number of polls displayed per page
* fixed issue with results colour when poll is ended
* fixed issue with generating page for poll
* removed p tag from notification messages
* fixed issue with gdpr consent checkbox

= 6.0.1 =
* css cleanout
* fixed issue with css for custom fields
* fixed issue with the gridline
* fixed issue with results after vote
* fixed issue with displaying number of votes and percentages
* fixed issue with spacing between answers
* fixed issue with export
* fixed issue with redirect after vote time
* fixed issue with reset votes
* fixed issue with results set to Never
* fixed issue with deleted polls

= 6.0.0 =
* complete re-write
* add GDPR compliance

= 5.8.3 =
* fixed php7 issues

= 5.8.2 =
* fixed issue with notices showing up on front pages

= 5.8.1 =
* fixed security issue
* fixed issue with multisite
* compatibility with WordPress 4.7.2

= 5.8.0 =
* compatibility with WordPress 4.5.2
* fixed issue with navigation links on archive page
* fixed loading issue
* fixed issue with custom fields

= 5.7.9 =
* start date and end date easier to read on the front end
* Fixed issue with showing results before vote

= 5.7.8 =
* Fixed issue with reset stats
* Fixed security issue
* Fixed issue with automatically reset stats
* Fixed issue with custom loading image
* Fixed display issues
* Updated Get Code with more options

= 5.7.7 =
* Fixed issue with translations

= 5.7.6 =
* Fixed issues with cloning poll
* Fixed conflicts with different plugins
* Fixed issue with pagination on archive page
* Fixed issue with logs page
* Fixed issue with facebook voting
* Added new shortcuts for email notifications
* Added new column for username in view votes page

= 5.7.5 =
* Fixed issue with vote button not showing up
* Other minor fixes

= 5.7.4 =
* Fixed security issue. A big thank you to [g0blin Research](https://twitter.com/g0blinResearch) for his help in getting this issue fixed

= 5.7.3 =
* Fixed display poll issue

= 5.7.2 =
* Display poll improvements

= 5.7.1 =
* Fixed issue with polls not being displayed

= 5.7 =
* Fixed issue with random polls
* Fixed issue with tabulated display
* Removed autoscroll after a failed vote
* Fixed issue with inserted code when using html editor
* Fixed issue with blocking voters option
* Fixed issue with in_array causing errors
* Fixed twig compatibility
* Added Print Votes page

= 5.6 =
* Fixed issue with login popup
* Fixed issue with vote button
* Fixed issue with html

= 5.5 =
* Fixed issue with clone poll
* Fixed issue with archive page
* Fixed issue with captcha

= 5.3 =
* Fixed issue with links color being overwritten
* Fixed issue with start date and end date not displaying corectly
* Fixed issue with widget
* Added email notifications customization per poll

= 5.2 =
* Complete new design
* Wizard to guide you when creating a poll
* You can now change the order answers are being displayed

= 4.9.3 =
* Fixed security issue. Many thanks to Antonio Sanchez for all his help.

= 4.9.2 =
* Fixed security issue

= 4.9.1 =
* Fixed issue with Template preview not working in IE8
* Fixed issue with wpautop filter
* Redefined admin area allowed tags: a(href, title, target), img( src, title), br
* Fixed issue with Other answers

= 4.9 =
* Added templates preview when adding/editing a poll
* Added sidebar scroll
* Typos fixes
* CSS and Javascript improvements
* Various bugs fixes

= 4.8 =
* Re-added ability to use html tags
* Added new tags: %POLL-SUCCESS-MSG% and %POLL-ERROR-MSG%
* Various bug fixes

= 4.7 =
* Fixed bug with Other answers. Html code is no longer allowed

= 4.6 =
* Added ability to send email notifications when a vote is recorded
* Various bug fixes

= 4.5 =
* Added ability to choose date format when displaying polls
* Added ability to limit viewing results only for logged in users
* Added ability to add custom answers to poll answers
* Added new shortcode [yop_poll id="-4"] that displays latest closed poll
* Added an offset for shortcodes. [yop_poll id="-1" offset="0"] displays the first active poll found, [yop_poll id="-1" offset="1"] displays the second one
* Added WPML compatibility
* Various bugs fixes

= 4.4 =
* Added ability to reset polls
* Added ability to to add a custom message to be displayed after voting
* Added ability to allow users to vote multiple times on the same poll
* Various bugs fixes

= 4.3 =
* Added multisite support
* Added ability to redirect to a custom url after voting
* Added ability to edit polls and templates author
* Added ability to set a response as default
* Improvements on View Results
* Added ability to edit number of votes (very usefull when migrating polls)
* Added tracking capabilities
* Various improvements on logs

= 4.2 =
* Added captcha
* Fixed issue with start date and end date when adding/editing a poll
* Fixed issue with the message displayed when editing a poll

= 4.1 =
* Fixed js issue causing the widget poll not to work

= 4.0 =
* Added ability to use custom loading animation
* Added capabilities and roles
* Fixed issue with update overwritting settings

= 3.9 =
* Fixed display issue with IE7 and IE8

= 3.8 =
* Fixed compatibility issue with Restore jQuery plugin
* Added ability to link poll answers

= 3.7 =
* Fixed issue with Loading text displayed above the polls
* Fixed issue with deleting answers from polls

= 3.6 =
* Fixed issue with missing files

= 3.5 =
* Added french language pack
* Added loading animation when vote button is clicked
* Fixed issue with characters encoding

= 3.4 =
* Fixed issue with menu items in admin area
* Fixed issue with language packs

= 3.3 =
* Added option to auto generate a page when a poll is created
* Fixed compatibility issues with IE
* Fixed issues with custom fields

= 3.2 =
* Fixed bug that was causing issues with TinyMCE Editor

= 3.1 =
* Various bugs fixed

= 3.0 =
* Added export ability for logs
* Added date filter option for logs
* Added option to view logs grouped by vote or by answer
* Various bugs fixed

= 2.0 =
* Fixed various bugs with templates

= 1.9 =
* Fixed various bugs with templates

= 1.8 =
* Fixed bug with WordPress editor

= 1.7 =
* Fixed bug that was causing poll not to update it's settings

= 1.6 =
* Added ability to change the text for Vote button
* Added ability to display the answers for Others field

= 1.5 =
* Fixed sort_answers_by_votes_asc_callback() bug

= 1.4 =
* Fixed compatibility issues with other plugins

= 1.3 =
* Fixed bug that was causing widgets text not to display

= 1.2 =
* Fixed do_shortcode() with missing argument bug

= 1.1 =
* Fixed call_user_func_array() bug
