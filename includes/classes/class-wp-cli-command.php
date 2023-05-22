<?php
/**
 * Main WP CLI command integration
 */

namespace TenUpWPScrubber;

/**
 * Register migration commands.
 * Class WP_CLI_Command
 *
 * @package TenUpWPScrubber
 */
class WP_CLI_Command extends \WP_CLI_Command {

	/**
	 * Define which user email domains are allowed exist post-scrub.
	 *
	 * @var array
	 */
	public $allowed_domains = array(
		'get10up.com',
		'10up.com'
	);

	/**
	 * Define which specific user emails are allowed exist post-scrub.
	 *
	 * @var array
	 */
	public $allowed_emails = array();

	/**
	 * Scrub users
	 *
	 * Remove any user data from the database.
	 *
	 * @param $args
	 * @param $assoc_args
	 *
	 * @return bool
	 *
	 */
	public function all( $args, $assoc_args ) {

		define( 'WP_IMPORTING', true );
		define( 'WP_ADMIN', true );

		$defaults = apply_filters(
			'wp_scrubber_scrub_all_defaults',
			array(
				'allowed-domains' => '',
				'allowed-emails' => '',
			)
		);

		$assoc_args = wp_parse_args( $assoc_args, $defaults );

		// Add additional email domains which should not be scrubbed.
		if ( ! empty( $assoc_args['allowed-domains'] ) ) {
			$this->allowed_domains = array_merge( $this->allowed_domains, explode( ',', $assoc_args['allowed-domains'] ) );
		}

		// Add user emails which should not be scrubbed.
		if ( ! empty( $assoc_args['allowed-emails'] ) ) {
			$this->allowed_emails = array_merge( $this->allowed_emails, explode( ',', $assoc_args['allowed-emails'] ) );
		}

		do_action( 'wp_scrubber_before_scrub', $args, $assoc_args );

		// Check the environment. Do not allow
		if ( 'production' === wp_get_environment_type() && ! $this->allow_on_production() ) {
			\WP_CLI::error( 'This command cannot be run on a production environment.' );
		}

		// Run through the scrubbing process.
		$this->scrub_users();
		$this->scrub_comments();

		// Flush the cache.
		wp_cache_flush();

		do_action( 'wp_scrubber_after_scrub', $args, $assoc_args );
	}

	/**
	 * Scrub comments
	 *
	 * Remove any comment data from the database.
	 *
	 * @param $args
	 * @param $assoc_args
	 *
	 * @return bool
	 *
	 */
	public function scrub_comments() {
		global $wpdb;

		// Drop tables if they exist.
		\WP_CLI::log( 'Scrubbing comments...' );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->comments}_temp" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->commentmeta}_temp" );

		\WP_CLI::log( ' - Duplicating comments table into temp table...' );
		$wpdb->query( "CREATE TABLE {$wpdb->comments}_temp LIKE $wpdb->comments" );
		$wpdb->query( "INSERT INTO {$wpdb->comments}_temp SELECT * FROM $wpdb->comments" );

		\WP_CLI::log( ' - Duplicating comment meta table into temp table...' );
		$wpdb->query( "CREATE TABLE {$wpdb->commentmeta}_temp LIKE $wpdb->commentmeta" );
		$wpdb->query( "INSERT INTO {$wpdb->commentmeta}_temp SELECT * FROM $wpdb->commentmeta" );

		// TODO: We may want more sophisticated scrubbing of comments later, but right now we'll just truncate the tables.
		\WP_CLI::log( ' - Scrubbing comments table...' );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->comments}_temp" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->commentmeta}_temp" );

		\WP_CLI::log( ' - Replacing comment tables with the scrubbed versions...' );
		$wpdb->query( "DROP TABLE {$wpdb->comments}" );
		$wpdb->query( "DROP TABLE {$wpdb->commentmeta}" );
		$wpdb->query( "RENAME TABLE {$wpdb->comments}_temp TO {$wpdb->comments}" );
		$wpdb->query( "RENAME TABLE {$wpdb->commentmeta}_temp TO {$wpdb->commentmeta}" );
	}

	/**
	 * Scrub WordPress Users
	 *
	 * @return void
	 */
	private function scrub_users() {

		global $wpdb;

		// Drop tables if they exist.
		\WP_CLI::log( 'Scrubbing users...' );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->usermeta}_temp" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->users}_temp" );

		\WP_CLI::log( ' - Duplicating users table into temp tables...' );
		$wpdb->query( "CREATE TABLE {$wpdb->users}_temp LIKE $wpdb->users" );
		$wpdb->query( "INSERT INTO {$wpdb->users}_temp SELECT * FROM $wpdb->users" );
		
		\WP_CLI::log( ' - Scrubbing each user record...' );
		$dummy_users = $this->get_dummy_users();

		$offset = 0;
		$user_ids = [];

		while ( true ) {
			$users = $wpdb->get_results( $wpdb->prepare( "SELECT ID, user_login, user_email FROM {$wpdb->users}_temp LIMIT 1000 OFFSET %d", $offset ), 'ARRAY_A' );

			if ( empty( $users ) ) {
				break;
			}

			if ( 1000 <= $offset ) {
				usleep( 100 );
			}

			foreach ( $users as $user ) {
				$user_id    = (int) $user['ID'];
				$user_ids[] = $user_id;
				$dummy_user = $dummy_users[ $user_id % 1000 ];

				$this->scrub_user( $user, $dummy_user );
			}

			$offset += 1000;
		}

		\WP_CLI::log( ' - Duplicating user meta table into temp table...' );

		$wpdb->query( "CREATE TABLE {$wpdb->usermeta}_temp LIKE $wpdb->usermeta" );
		$wpdb->query( "INSERT INTO {$wpdb->usermeta}_temp SELECT * FROM $wpdb->usermeta" );

		// Just truncate user description and session tokens.
		$wpdb->query( "UPDATE {$wpdb->usermeta}_temp SET meta_value='' WHERE meta_key='description' OR meta_key='session_tokens'" );

		$user_ids_count = count( $user_ids );
		for ( $i = 0; $i < $user_ids_count; $i++ ) {
			if ( 1 < $i && 0 === $i % 1000 ) {
				usleep( 100 );
			}

			$user_id = $user_ids[ $i ];

			$dummy_user = $dummy_users[ $user_id % 1000 ];

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->usermeta}_temp SET meta_value=%s WHERE meta_key='first_name' AND user_id=%d",
					$dummy_user['first_name'],
					(int) $user_id
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->usermeta}_temp SET meta_value=%s WHERE meta_key='last_name' AND user_id=%d",
					$dummy_user['last_name'],
					$user_id
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->usermeta}_temp SET meta_value=%s WHERE meta_key='nickname' AND user_id=%d",
					$dummy_user['first_name'],
					$user_id
				)
			);
		}

		\WP_CLI::log( ' - Replacing user tables with the scrubbed versions...' );

		$wpdb->query( "DROP TABLE {$wpdb->usermeta}" );
		$wpdb->query( "DROP TABLE {$wpdb->users}" );
		$wpdb->query( "RENAME TABLE {$wpdb->usermeta}_temp TO {$wpdb->usermeta}" );
		$wpdb->query( "RENAME TABLE {$wpdb->users}_temp TO {$wpdb->users}" );
	}

	/**
	 * Scrub the user data
	 *
	 * @param array $user User array from wpdb query.
	 * @param array $dummy_user User array from dummy user csv.
	 * @return void
	 */
	private function scrub_user( $user, $dummy_user ) {

		global $wpdb;

		$scrub_user = true;

		if ( ! $this->should_scrub_user( $user ) ) {
			return false;
		}

		$password = wp_hash_password( apply_filters( 'wp_scrubber_scrubbed_password', 'password' ) );

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->users}_temp SET user_pass=%s, user_email=%s, user_url='', user_activation_key='', display_name=%s WHERE ID=%d",
				$password,
				$dummy_user['email'],
				$user['user_login'],
				$user['ID']
			)
		);
	}

	/**
	 * Add conditions to check whether a user should be scrubbed or not.
	 *
	 * @param array $user User array from wpdb query.
	 * @return boolean
	 */
	private function should_scrub_user( $user ) {

		$scrub = true;

		// Check if the user is part of list of allowed email domains.
		$allowed_email_domains = apply_filters( 'wp_scrubber_allowed_email_domains', $this->allowed_domains );
		foreach ( $allowed_email_domains as $domain ) {
			if ( str_contains( $user['user_email'], $domain ) ) {
				$scrub = false;
			}
		}

		// Check if the user has been specifically allowed.
		$allowed_emails = apply_filters( 'wp_scrubber_allowed_emails', $this->allowed_emails );
		foreach ( $allowed_emails as $email ) {
			if ( $user['user_email'] === $email ) {
				$scrub = false;
			}
		}

		return apply_filters( 'wp_scrubber_should_scrub_user', $scrub, $user );
	}

	/**
	 * Get dummy users from csv file.
	 *
	 * @return array
	 */
	private function get_dummy_users() {
		static $users = [];

		if ( empty( $users ) ) {
			$file = fopen( trailingslashit( TENUP_WP_SCRUBBER_INC ) . 'data/users.csv', 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen

			$line = fgetcsv( $file );
			while ( false !== $line ) {

				$user = [
					'username'   => $line[0],
					'first_name' => $line[1],
					'last_name'  => $line[2],
					'email'      => $line[3],
				];

				$users[] = $user;

				$line = fgetcsv( $file );
			}

			fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		}

		return $users;
	}

	/**
	 * Check if we should allow scrubbing on production.
	 *
	 * @return boolean
	 */
	function allow_on_production() {
		return apply_filters( 'wp_scrubber_allow_on_production', false );
	}
}