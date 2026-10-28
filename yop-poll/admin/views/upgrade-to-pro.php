<?php
/**
 * The Upgrade to Pro screen.
 *
 * From the v9 design. English only, by decision: this page is not translated.
 * Both buttons lead to the sales page and open it in a new tab; nothing is
 * bought inside WordPress, and nothing here runs script or tracks.
 *
 * @package YopPoll
 */

use YopPoll\Admin\Admin_Page_Upgrade_To_Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$yop_poll_upgrade_url = Admin_Page_Upgrade_To_Pro::SALES_PAGE_URL;
$yop_poll_new_tab     = '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M11 3h6v6h-2V6.41l-6.29 6.3-1.42-1.42L13.59 5H11V3zM5 5h4v2H5v8h8v-4h2v6H3V5h2z"/></svg>';
?>
<div class="wrap yop-poll-upg">

	<section class="yop-poll-upg-panel yop-poll-upg-intro" aria-labelledby="yop-poll-upg-title">
		<p class="yop-poll-upg-label">Upgrade to <span class="yop-poll-upg-badge">Pro</span></p>
		<h1 id="yop-poll-upg-title">Discover what YOP Poll Pro adds to your polls.</h1>
		<p class="yop-poll-upg-lede">Go beyond single-question polls with visual choices, ratings, relevant follow-ups, stronger vote controls, and clearer results. Explore the features that fit what you want to ask—and how you want to use the answers.</p>
		<div class="yop-poll-upg-cta">
			<a class="yop-poll-upg-button" href="<?php echo esc_url( $yop_poll_upgrade_url ); ?>" target="_blank" rel="noopener" data-cta="pro-sales-page">
				Explore Pro features and plans
				<?php echo $yop_poll_new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG literal. ?>
				<span class="screen-reader-text">(opens in a new tab)</span>
			</a>
			<p class="yop-poll-upg-cta-note">Explore Pro features, try interactive examples, and compare plans on the YOP Poll website.<span aria-hidden="true"> Opens in a new tab.</span></p>
		</div>
	</section>

	<section class="yop-poll-upg-section" aria-labelledby="yop-poll-upg-preview-title">
		<h2 id="yop-poll-upg-preview-title">A preview of what’s possible with Pro</h2>
		<p class="yop-poll-upg-section-intro">New question formats, multi-question polls, conditional follow-ups, vote controls, results summaries, and integrations—here are some of the ways Pro expands what you can do.</p>
		<div class="yop-poll-upg-reasons">
			<article class="yop-poll-upg-panel yop-poll-upg-reason">
				<span class="yop-poll-upg-icon" aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false"><path fill="currentColor" d="M3 4h14v12H3V4zm2 2v6.6l3-3 3 3 2-2 2 2V6H5zm8 1.5a1.5 1.5 0 1 1 0 .01z"/></svg></span>
				<h3>Give people more ways to answer</h3>
				<p>Use images, video, or audio for choices people can see or hear. Collect feedback with stars, emoji faces, thumbs, a 0–10 score, or sliders.</p>
			</article>
			<article class="yop-poll-upg-panel yop-poll-upg-reason">
				<span class="yop-poll-upg-icon" aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false"><path fill="currentColor" d="M3 3h6v4H3V3zm8 0h6v4h-6V3zM3 13h6v4H3v-4zm3-5h2v2h6V8h2v4h-5v1h-2v-1H6V8z"/></svg></span>
				<h3>Build polls with more than one question</h3>
				<p>Ask several questions in one poll, show follow-ups based on earlier answers, and split longer polls into pages with a progress indicator.</p>
			</article>
			<article class="yop-poll-upg-panel yop-poll-upg-reason">
				<span class="yop-poll-upg-icon" aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false"><path fill="currentColor" d="M10 2l7 3v5c0 4-3 7-7 8-4-1-7-4-7-8V5l7-3zm-1 11l5-5-1.4-1.4L9 10.2 7.4 8.6 6 10l3 3z"/></svg></span>
				<h3>Choose stronger vote controls</h3>
				<p>Use email-confirmed voting, browser-fingerprint and email repeat-vote controls, and write-in moderation.</p>
			</article>
			<article class="yop-poll-upg-panel yop-poll-upg-reason">
				<span class="yop-poll-upg-icon" aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false"><path fill="currentColor" d="M3 16h14v2H3v-2zm1-6h3v5H4v-5zm5-4h3v9H9V6zm5-4h3v13h-3V2z"/></svg></span>
				<h3>Make more use of the responses</h3>
				<p>Summarise suitable questions as averages, NPS scores, or approval shares. Keep head-to-head figures current and send opted-in contacts to Mailchimp or Klaviyo.</p>
			</article>
		</div>
	</section>

	<section class="yop-poll-upg-panel yop-poll-upg-final" aria-labelledby="yop-poll-upg-final-title">
		<div>
			<h2 id="yop-poll-upg-final-title">See Pro in action</h2>
			<p class="yop-poll-upg-final-text">Try the interactive examples, explore what’s included, and compare plans on the YOP Poll website.</p>
		</div>
		<div class="yop-poll-upg-cta">
			<a class="yop-poll-upg-button" href="<?php echo esc_url( $yop_poll_upgrade_url ); ?>" target="_blank" rel="noopener" data-cta="pro-sales-page">
				Explore Pro features and plans
				<?php echo $yop_poll_new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG literal. ?>
				<span class="screen-reader-text">(opens in a new tab)</span>
			</a>
			<p class="yop-poll-upg-cta-note"><span aria-hidden="true">Opens in a new tab.</span></p>
		</div>
	</section>

</div>
