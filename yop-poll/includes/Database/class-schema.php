<?php
namespace YopPoll\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schema {

	private function prefix() {
		global $wpdb;
		return $wpdb->prefix . YOP_POLL_TABLE_PREFIX;
	}

	public function create_tables() {
		global $wpdb;

		$prefix  = $this->prefix();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		/*
		 * `polls.skin_base varchar(255)` was dropped from this statement in
		 * 2026-09. Nothing ever read it — written on save, copied on
		 * duplicate, and asked for by no query, renderer or template.
		 *
		 * dbDelta does not drop columns, so an install that already has one
		 * keeps it and it stays harmlessly empty; a new install never gets it.
		 * Nothing writes it either way. Keep SQL comments out of the string
		 * below: dbDelta parses it with regexes and a comment in the column
		 * list is a good way to lose a column.
		 */
		$sql = "
CREATE TABLE {$prefix}polls (
	id int NOT NULL AUTO_INCREMENT,
	name varchar(255) NOT NULL,
	template int NOT NULL,
	template_base varchar(255) NOT NULL,
	author bigint NOT NULL,
	stype varchar(20) NOT NULL,
	status varchar(20) NOT NULL,
	meta_data longtext NOT NULL,
	total_submits int NOT NULL,
	total_submited_answers int NOT NULL,
	added_date datetime NOT NULL,
	modified_date datetime NOT NULL,
	PRIMARY KEY  (id)
) {$charset};

CREATE TABLE {$prefix}elements (
	id int NOT NULL AUTO_INCREMENT,
	poll_id int NOT NULL,
	etext text NOT NULL,
	author bigint NOT NULL,
	etype varchar(50) NOT NULL,
	status varchar(20) NOT NULL,
	sorder int NOT NULL,
	meta_data longtext NOT NULL,
	added_date datetime NOT NULL,
	modified_date datetime NOT NULL,
	PRIMARY KEY  (id)
) {$charset};

CREATE TABLE {$prefix}subelements (
	id int NOT NULL AUTO_INCREMENT,
	poll_id int NOT NULL,
	element_id int NOT NULL,
	stext text NOT NULL,
	author bigint NOT NULL,
	stype varchar(20) NOT NULL,
	status varchar(20) NOT NULL,
	sorder int NOT NULL,
	meta_data longtext NOT NULL,
	total_submits int NOT NULL,
	added_date datetime NOT NULL,
	modified_date datetime NOT NULL,
	PRIMARY KEY  (id)
) {$charset};

CREATE TABLE {$prefix}votes (
	id int NOT NULL AUTO_INCREMENT,
	poll_id int NOT NULL,
	user_id bigint NOT NULL,
	user_email varchar(255) DEFAULT NULL,
	user_type varchar(100) NOT NULL,
	ipaddress varchar(100) NOT NULL,
	tracking_id varchar(255) NOT NULL,
	voter_id varchar(255) NOT NULL,
	voter_fingerprint varchar(255) NOT NULL,
	vote_data longtext NOT NULL,
	status varchar(10) NOT NULL,
	added_date datetime NOT NULL,
	PRIMARY KEY  (id)
) {$charset};

CREATE TABLE {$prefix}logs (
	id int NOT NULL AUTO_INCREMENT,
	poll_id int NOT NULL,
	poll_author bigint NOT NULL,
	user_id bigint NOT NULL,
	user_email varchar(255) DEFAULT NULL,
	user_type varchar(100) NOT NULL,
	ipaddress varchar(100) NOT NULL,
	tracking_id varchar(255) NOT NULL,
	voter_id varchar(255) NOT NULL,
	voter_fingerprint varchar(255) NOT NULL,
	vote_data longtext NOT NULL,
	vote_message longtext NOT NULL,
	status varchar(20) NOT NULL,
	added_date datetime NOT NULL,
	PRIMARY KEY  (id)
) {$charset};

CREATE TABLE {$prefix}other_answers (
	id int NOT NULL AUTO_INCREMENT,
	poll_id int NOT NULL,
	element_id int NOT NULL,
	vote_id int NOT NULL,
	answer longtext NOT NULL,
	status varchar(10) NOT NULL,
	added_date datetime NOT NULL,
	PRIMARY KEY  (id)
) {$charset};

CREATE TABLE {$prefix}bans (
	id int NOT NULL AUTO_INCREMENT,
	author bigint NOT NULL,
	poll_id int NOT NULL,
	b_by varchar(255) NOT NULL,
	b_value varchar(255) NOT NULL,
	status varchar(20) NOT NULL,
	added_date datetime NOT NULL,
	modified_date datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY b_value (b_value(100))
) {$charset};

CREATE TABLE {$prefix}templates (
	id int NOT NULL AUTO_INCREMENT,
	name varchar(255) NOT NULL,
	base varchar(255) NOT NULL,
	rendering_base varchar(255) NOT NULL,
	description text NOT NULL,
	options longtext NOT NULL,
	status varchar(255) NOT NULL,
	added_date datetime NOT NULL,
	PRIMARY KEY  (id)
) {$charset};

";

		dbDelta( $sql );
	}

	public function upgrade() {
		global $wpdb;
		$prefix = $this->prefix();

		// elements.etype: widen from varchar(20) to varchar(50).
		$col = $wpdb->get_row( "SHOW COLUMNS FROM {$prefix}elements LIKE 'etype'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $prefix is $wpdb->prefix . YOP_POLL_TABLE_PREFIX, both safe identifiers.
		if ( $col && false !== strpos( $col->Type, 'varchar(20)' ) ) {
			$wpdb->query( "ALTER TABLE {$prefix}elements MODIFY etype varchar(50) NOT NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $prefix is $wpdb->prefix . YOP_POLL_TABLE_PREFIX, both safe identifiers.
		}

		// other_answers.total_submits_with_weight: a paid edition (platinum) shares this
		// table and may have created it with this column NOT NULL and no default, which
		// makes the free version's "Other" answer inserts fail. The column is unused by the
		// free version; if we find it without a default, add one so inserts succeed. (We do
		// not drop it, so a paid edition keeps working if it is re-activated.)
		$oa_col = $wpdb->get_row( "SHOW COLUMNS FROM {$prefix}other_answers LIKE 'total_submits_with_weight'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $prefix is $wpdb->prefix . YOP_POLL_TABLE_PREFIX, both safe identifiers.
		if ( $oa_col && 'NO' === $oa_col->Null && null === $oa_col->Default ) {
			$wpdb->query( "ALTER TABLE {$prefix}other_answers MODIFY total_submits_with_weight int NOT NULL DEFAULT 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $prefix is $wpdb->prefix . YOP_POLL_TABLE_PREFIX, both safe identifiers.
		}

		/*
		 * bans.b_value: an index, because `Model_Ban::is_banned()` runs three
		 * times on every vote — once each for the address, the email and the
		 * username — and the table had only its primary key, so each was a
		 * full scan.
		 *
		 * `b_value` alone, prefixed to 100 characters, not the composite on
		 * `(poll_id, b_by, b_value)` that looks right: two `varchar(255)`
		 * columns at utf8mb4 make a key of about 2044 bytes, which is over the
		 * 767-byte limit MySQL 5.6 defaults to and would simply fail to build
		 * there. `b_by` has three distinct values and earns nothing as an
		 * index column anyway; the seek lands on a handful of rows and the
		 * rest of the predicate is checked against those.
		 *
		 * The admin search is `b_value LIKE '%…%'` and cannot use this — a
		 * leading wildcard never can. This is for the vote path.
		 *
		 * Added here as well as in `create_tables()` because dbDelta compares
		 * schemas textually and is historically unreliable about index lines;
		 * left to it alone, it re-issues the ALTER on every run.
		 */
		$idx = $wpdb->get_row( "SHOW INDEX FROM {$prefix}bans WHERE Key_name = 'b_value'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $prefix is $wpdb->prefix . YOP_POLL_TABLE_PREFIX, both safe identifiers.
		if ( ! $idx ) {
			$wpdb->query( "ALTER TABLE {$prefix}bans ADD KEY b_value (b_value(100))" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $prefix is $wpdb->prefix . YOP_POLL_TABLE_PREFIX, both safe identifiers.
		}
	}
}
