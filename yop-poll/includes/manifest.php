<?php
/**
 * Files this plugin must have on disk to boot.
 *
 * GENERATED FILE - do not edit by hand.
 * Run `php scripts/generate-manifest.php` after adding or removing a PHP file
 * under includes/. scripts/deploy-svn.sh refuses to ship a stale manifest.
 *
 * yop_poll.php verifies this list before registering any hooks, so a package
 * that is missing a class shows an admin notice instead of fataling the site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'includes/Admin/class-admin-page-add-new.php',
	'includes/Admin/class-admin-page-bans.php',
	'includes/Admin/class-admin-page-logs.php',
	'includes/Admin/class-admin-page-polls.php',
	'includes/Admin/class-admin-page-results.php',
	'includes/Admin/class-admin-page-settings.php',
	'includes/Admin/class-admin-page-upgrade-to-pro.php',
	'includes/Admin/class-admin-page-votes.php',
	'includes/Admin/class-admin.php',
	'includes/Admin/class-deactivation-feedback.php',
	'includes/Admin/class-guide.php',
	'includes/Captcha/class-captcha.php',
	'includes/Cron/class-cron-auto-reset.php',
	'includes/Database/class-migrator.php',
	'includes/Database/class-schema.php',
	'includes/Database/class-seeder.php',
	'includes/Frontend/class-block.php',
	'includes/Frontend/class-elementor-widget.php',
	'includes/Frontend/class-elementor.php',
	'includes/Frontend/class-frontend.php',
	'includes/Frontend/class-shortcode.php',
	'includes/Frontend/class-widget.php',
	'includes/Helpers/class-capabilities.php',
	'includes/Helpers/class-permissions.php',
	'includes/Helpers/class-sanitizer.php',
	'includes/Models/class-model-ban.php',
	'includes/Models/class-model-base.php',
	'includes/Models/class-model-element.php',
	'includes/Models/class-model-log.php',
	'includes/Models/class-model-other-answer.php',
	'includes/Models/class-model-poll.php',
	'includes/Models/class-model-subelement.php',
	'includes/Models/class-model-template.php',
	'includes/Models/class-model-vote.php',
	'includes/REST/class-rest-auth.php',
	'includes/REST/class-rest-bans.php',
	'includes/REST/class-rest-base.php',
	'includes/REST/class-rest-captcha.php',
	'includes/REST/class-rest-elements.php',
	'includes/REST/class-rest-logs.php',
	'includes/REST/class-rest-polls.php',
	'includes/REST/class-rest-settings.php',
	'includes/REST/class-rest-subelements.php',
	'includes/REST/class-rest-templates.php',
	'includes/REST/class-rest-votes.php',
	'includes/Templates/class-template-engine.php',
	'includes/Templates/classic/template.php',
	'includes/Validation/class-poll-validator.php',
	'includes/class-activator.php',
	'includes/class-assets.php',
	'includes/class-deactivator.php',
	'includes/class-plugin.php',
);
