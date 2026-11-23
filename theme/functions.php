<?php
/**
 * Various functions for the dashboard.
 */

define( 'WP_CLI_DASHBOARD_BASE_DIR', dirname( __DIR__ ) );
define( 'WP_CLI_DASHBOARD_DATA_DIR', WP_CLI_DASHBOARD_BASE_DIR . '/github-data' );

require_once __DIR__ . '/charts.php';
require_once __DIR__ . '/data.php';

/**
 * Get a rendered template part
 *
 * @param string $template
 * @param array $vars
 * @return string
 */
function wp_cli_dashboard_get_template_part( $template, $vars = array() ) {

	$full_path = WP_CLI_DASHBOARD_BASE_DIR . '/theme/' . $template . '.php';

	if ( ! file_exists( $full_path ) ) {
		return '';
	}

	ob_start();
	// @codingStandardsIgnoreStart
	if ( ! empty( $vars ) ) {
		extract( $vars );
	}
	// @codingStandardsIgnoreEnd
	include $full_path;
	return ob_get_clean();
}

/**
 * Gets config data
 *
 * @param string $key Config key
 * @return mixed
 */
function wp_cli_dashboard_get_config_data( $key ) {
	static $config = null;
	if ( null === $config ) {
		$config_file = WP_CLI_DASHBOARD_BASE_DIR . '/config.yml';
		if ( ! file_exists( $config_file ) ) {
			WP_CLI::error( 'Unable to load ./config.yml' );
		}
		$config = Spyc::YAMLLoad( $config_file );
	}
	return isset( $config[ $key ] ) ? $config[ $key ] : null;
}

/**
 * Escapes a string for HTML output.
 *
 * @param mixed $value
 * @return string
 */
function wp_cli_dashboard_esc( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

/**
 * Whether a GitHub account is an automated account.
 *
 * Bots are identified by the GraphQL/REST account type, the `[bot]` suffix
 * GitHub Apps use, or the `bot_users` list in ./config.yml.
 *
 * @param string      $login GitHub login.
 * @param string|null $type  Account type ('Bot', 'User', ...), when known.
 * @return bool
 */
function wp_cli_dashboard_is_bot( $login, $type = null ) {
	static $bots = null;
	if ( null === $bots ) {
		$bots = array_map( 'strtolower', (array) wp_cli_dashboard_get_config_data( 'bot_users' ) );
	}
	if ( '' === (string) $login ) {
		return true; // Deleted ("ghost") accounts can't be attributed.
	}
	if ( null !== $type && 'Bot' === $type ) {
		return true;
	}
	$login = strtolower( $login );
	if ( '[bot]' === substr( $login, -5 ) ) {
		return true;
	}
	return in_array( $login, $bots, true ) || in_array( str_replace( '[bot]', '', $login ), $bots, true );
}

/**
 * Reads a JSON file from the data directory.
 *
 * @param string $path    Path relative to ./github-data.
 * @param mixed  $default Value to return when the file is missing or invalid.
 * @return mixed
 */
function wp_cli_dashboard_read_json( $path, $default = array() ) {
	$file = WP_CLI_DASHBOARD_DATA_DIR . '/' . $path;
	if ( ! file_exists( $file ) ) {
		return $default;
	}
	$data = json_decode( file_get_contents( $file ), true );
	return null === $data ? $default : $data;
}

/**
 * Writes a JSON file to the data directory.
 *
 * Output is one entry per line for top-level objects/lists so that hourly
 * commits produce small, readable diffs.
 *
 * @param string $path Path relative to ./github-data.
 * @param mixed  $data
 */
function wp_cli_dashboard_write_json( $path, $data ) {
	$file = WP_CLI_DASHBOARD_DATA_DIR . '/' . $path;
	if ( ! is_dir( dirname( $file ) ) ) {
		mkdir( dirname( $file ), 0777, true );
	}
	$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
	if ( is_array( $data ) && ! empty( $data ) ) {
		$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );
		$lines   = array();
		foreach ( $data as $key => $value ) {
			$lines[] = ( $is_list ? '' : json_encode( (string) $key, $flags ) . ':' ) . json_encode( $value, $flags );
		}
		$json = ( $is_list ? '[' : '{' ) . "\n" . implode( ",\n", $lines ) . "\n" . ( $is_list ? ']' : '}' ) . "\n";
	} else {
		$json = json_encode( $data, $flags ) . "\n";
	}
	file_put_contents( $file, $json );
}

/**
 * Default headers for GitHub API requests.
 *
 * @return array
 */
function wp_cli_dashboard_github_headers() {
	if ( ! getenv( 'GITHUB_TOKEN' ) ) {
		WP_CLI::error( 'GITHUB_TOKEN environment variable must be set.' );
	}
	return array(
		'Accept'        => 'application/vnd.github+json',
		'User-Agent'    => 'WP-CLI',
		'Authorization' => 'token ' . getenv( 'GITHUB_TOKEN' ),
	);
}

/**
 * Performs a GitHub REST request, retrying transient failures.
 *
 * @param string $url   Full API URL.
 * @param array  $query Query arguments.
 * @return array|null Decoded body (associative), or null on failure.
 */
function wp_cli_dashboard_github_get( $url, $query = array() ) {
	$response = wp_cli_dashboard_github_request( 'GET', $url, $query );
	if ( null === $response ) {
		return null;
	}
	return json_decode( $response->body, true );
}

/**
 * Performs a GitHub request, retrying 5xx responses and timeouts.
 *
 * @param string       $method
 * @param string       $url
 * @param array|string $data
 * @param bool         $quiet_404 Don't warn on 404 (expected for optional resources).
 * @return object|null Response, or null on failure.
 */
function wp_cli_dashboard_github_request( $method, $url, $data = array(), $quiet_404 = false ) {
	$headers = wp_cli_dashboard_github_headers();
	if ( 'POST' === $method ) {
		$headers['Content-Type'] = 'application/json';
	}
	for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
		try {
			$response = WP_CLI\Utils\http_request( $method, $url, $data, $headers, array( 'timeout' => 60 ) );
		} catch ( Exception $e ) {
			WP_CLI::warning( sprintf( 'Request to %s failed: %s', $url, $e->getMessage() ) );
			sleep( 2 * $attempt );
			continue;
		}
		$status = (int) $response->status_code;
		if ( $status >= 200 && $status < 300 ) {
			return $response;
		}
		if ( 404 === $status && $quiet_404 ) {
			return null;
		}
		if ( $status >= 500 || 403 === $status || 429 === $status ) {
			sleep( 5 * $attempt );
			continue;
		}
		break;
	}
	WP_CLI::warning( sprintf( 'Request to %s failed (HTTP %d): %s', $url, isset( $status ) ? $status : 0, isset( $response ) ? substr( $response->body, 0, 300 ) : '' ) );
	return null;
}

/**
 * Runs a GitHub GraphQL query.
 *
 * @param string $query
 * @param array  $variables
 * @return array|null The `data` member, or null on failure.
 */
function wp_cli_dashboard_graphql( $query, $variables = array() ) {
	$body     = json_encode( array( 'query' => $query, 'variables' => (object) $variables ) );
	$response = wp_cli_dashboard_github_request( 'POST', 'https://api.github.com/graphql', $body );
	if ( null === $response ) {
		return null;
	}
	$result = json_decode( $response->body, true );
	if ( ! empty( $result['errors'] ) ) {
		WP_CLI::warning( 'GraphQL errors: ' . json_encode( $result['errors'] ) );
	}
	return isset( $result['data'] ) ? $result['data'] : null;
}

if ( ! function_exists( 'human_time_diff' ) ) {
	/**
	 * Determines the difference between two timestamps.
	 *
	 * The difference is returned in a human readable format such as "1 hour",
	 * "5 mins", "2 days".
	 *
	 * @since 1.5.0
	 *
	 * @param int $from Unix timestamp from which the difference begins.
	 * @param int $to   Optional. Unix timestamp to end the time difference. Default becomes time().
	 * @return string Human readable time difference.
	 */
	function human_time_diff( $from, $to = '' ) {
		if ( empty( $to ) ) {
			$to = time();
		}
		$diff = (int) abs( $to - $from );
		if ( $diff < HOUR_IN_SECONDS ) {
			$mins = max( 1, (int) round( $diff / MINUTE_IN_SECONDS ) );
			$since = sprintf( _n( '%s min', '%s mins', $mins ), $mins );
		} elseif ( $diff < DAY_IN_SECONDS ) {
			$hours = max( 1, (int) round( $diff / HOUR_IN_SECONDS ) );
			$since = sprintf( _n( '%s hour', '%s hours', $hours ), $hours );
		} elseif ( $diff < WEEK_IN_SECONDS ) {
			$days  = max( 1, (int) round( $diff / DAY_IN_SECONDS ) );
			$since = sprintf( _n( '%s day', '%s days', $days ), $days );
		} elseif ( $diff < MONTH_IN_SECONDS ) {
			$weeks = max( 1, (int) round( $diff / WEEK_IN_SECONDS ) );
			$since = sprintf( _n( '%s week', '%s weeks', $weeks ), $weeks );
		} elseif ( $diff < YEAR_IN_SECONDS ) {
			$months = max( 1, (int) round( $diff / MONTH_IN_SECONDS ) );
			$since  = sprintf( _n( '%s month', '%s months', $months ), $months );
		} else {
			$years = max( 1, (int) round( $diff / YEAR_IN_SECONDS ) );
			$since = sprintf( _n( '%s year', '%s years', $years ), $years );
		}
		return $since;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number ) {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
}
if ( ! defined( 'MONTH_IN_SECONDS' ) ) {
	define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
}
if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
	define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );
}
