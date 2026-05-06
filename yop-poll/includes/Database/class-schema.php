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

		$sql = "
CREATE TABLE {$prefix}polls (
	id int NOT NULL AUTO_INCREMENT,
	name varchar(255) NOT NULL,
	template int NOT NULL,
	template_base varchar(255) NOT NULL,
	skin_base varchar(255) NOT NULL,
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
	PRIMARY KEY  (id)
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

	}
}
