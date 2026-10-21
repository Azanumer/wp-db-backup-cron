<?php
/**
 * Plugin Name:       WP DB Backup Cron
 * Description:       Scheduled database backups via WP-Cron — mysqldump when available, wpdb fallback when not. Gzip, retention rotation, failure emails.
 * Version:           1.0.0
 * Author:            Azan Umer
 * License:           MIT
 * Text Domain:       wpdbc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPDBC_VERSION', '1.0.0' );
define( 'WPDBC_HOOK', 'wpdbc_cron_hook' );

/* ---------------------------------------------------------------------------
 * Options & defaults
 * ------------------------------------------------------------------------- */
function wpdbc_get_options() {
	$defaults = array(
		'schedule'  => 'daily',   // daily | weekly | disabled
		'retention' => 7,         // keep this many newest backups
		'notify'    => 1,         // email admin on failure
		'token'     => '',        // random filename prefix, set on first run
	);
	$opts = get_option( 'wpdbc_options', array() );
	return wp_parse_args( is_array( $opts ) ? $opts : array(), $defaults );
}

/* ---------------------------------------------------------------------------
 * Activation / deactivation — manage the cron event
 * ------------------------------------------------------------------------- */
function wpdbc_activate() {
	$opts = wpdbc_get_options();
	if ( empty( $opts['token'] ) ) {
		$opts['token'] = substr( wp_generate_password( 16, false ), 0, 8 );
		update_option( 'wpdbc_options', $opts );
	}
	wpdbc_reschedule( $opts['schedule'] );
}
register_activation_hook( __FILE__, 'wpdbc_activate' );

function wpdbc_deactivate() {
	wp_clear_scheduled_hook( WPDBC_HOOK );
}
register_deactivation_hook( __FILE__, 'wpdbc_deactivate' );

function wpdbc_reschedule( $schedule ) {
	wp_clear_scheduled_hook( WPDBC_HOOK );
	if ( in_array( $schedule, array( 'daily', 'weekly' ), true ) && ! wp_next_scheduled( WPDBC_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, WPDBC_HOOK );
	}
}
add_action( WPDBC_HOOK, 'wpdbc_run_backup' );

/* ---------------------------------------------------------------------------
 * Backup directory — protected uploads subfolder
 * ------------------------------------------------------------------------- */
function wpdbc_dir() {
	$upload = wp_upload_dir();
	$dir    = trailingslashit( $upload['basedir'] ) . 'wpdbc-backups';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	// Silence is golden + belt-and-suspenders for nginx-less Apache.
	if ( ! file_exists( $dir . '/index.php' ) ) {
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
	}
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		file_put_contents( $dir . '/.htaccess', "Require all denied\n" );
	}
	return $dir;
}

/* ---------------------------------------------------------------------------
 * Run a backup. Returns array( 'ok' => bool, 'file' => path|null, 'error' => string )
 * ------------------------------------------------------------------------- */
function wpdbc_run_backup() {
	global $wpdb;
	$opts = wpdbc_get_options();
	$dir  = wpdbc_dir();

	$stamp = gmdate( 'Y-m-d-His' );
	$file  = $dir . '/wpdbc-' . $opts['token'] . '-' . $stamp . '.sql.gz';

	$result = wpdbc_try_mysqldump( $file );
	if ( ! $result['ok'] ) {
		$result = wpdbc_wpdb_dump( $file ); // portable fallback
	}

	if ( ! $result['ok'] ) {
		if ( $opts['notify'] ) {
			wp_mail(
				get_option( 'admin_email' ),
				'[WP DB Backup Cron] Backup FAILED on ' . home_url(),
				"The scheduled database backup failed:\n\n" . $result['error'] . "\n\nTime: " . current_time( 'mysql' )
			);
		}
		update_option( 'wpdbc_last_error', $result['error'] );
		return $result;
	}

	delete_option( 'wpdbc_last_error' );
	update_option( 'wpdbc_last_ok', current_time( 'mysql' ) );
	wpdbc_prune( $dir, (int) $opts['retention'] );
	return array( 'ok' => true, 'file' => $file, 'error' => '' );
}

/**
 * Attempt a real mysqldump (fast, exact). Only when MySQL is local and the
 * binary + exec() are available. Password is passed via MYSQL_PWD env var so
 * it never appears in the process list.
 */
function wpdbc_try_mysqldump( $file ) {
	if ( ! function_exists( 'exec' ) ) {
		return array( 'ok' => false, 'error' => 'exec() disabled' );
	}
	$host = DB_HOST;
	if ( strpos( $host, ':' ) !== false ) {
		$host = strstr( $host, ':', true ); // strip :port
	}
	if ( $host !== '' && ! in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
		return array( 'ok' => false, 'error' => 'DB_HOST is remote; mysqldump skipped' );
	}
	$bin = trim( (string) shell_exec( 'command -v mysqldump' ) );
	if ( $bin === '' ) {
		return array( 'ok' => false, 'error' => 'mysqldump binary not found' );
	}

	putenv( 'MYSQL_PWD=' . DB_PASSWORD );
	// NOTE: wrapped in bash with `set -o pipefail` so $code reflects a
	// mysqldump failure, not gzip's (which would happily compress nothing).
	$dump_cmd = sprintf(
		'set -o pipefail; %s --single-transaction --quick --skip-lock-tables -h %s -u %s %s 2>/dev/null | gzip > %s',
		escapeshellcmd( $bin ),
		escapeshellarg( $host === '' ? 'localhost' : $host ),
		escapeshellarg( DB_USER ),
		escapeshellarg( DB_NAME ),
		escapeshellarg( $file )
	);
	exec( 'bash -c ' . escapeshellarg( $dump_cmd ), $out, $code );
	putenv( 'MYSQL_PWD' ); // clear from environment immediately

	if ( $code !== 0 || ! file_exists( $file ) || filesize( $file ) === 0 ) {
		@unlink( $file );
		return array( 'ok' => false, 'error' => 'mysqldump exited with code ' . $code );
	}
	return array( 'ok' => true, 'file' => $file, 'error' => '' );
}

/**
 * Portable fallback: dump every table with $wpdb. Slower, but works on any
 * host — including shared hosting without shell access.
 */
function wpdbc_wpdb_dump( $file ) {
	global $wpdb;

	$tables = $wpdb->get_col( 'SHOW TABLES' );
	if ( empty( $tables ) ) {
		return array( 'ok' => false, 'error' => 'SHOW TABLES returned nothing: ' . $wpdb->last_error );
	}

	$tmp = $file . '.tmp';
	$fh  = fopen( $tmp, 'w' );
	if ( ! $fh ) {
		return array( 'ok' => false, 'error' => 'Cannot write to backup directory' );
	}

	fwrite( $fh, "-- WP DB Backup Cron " . WPDBC_VERSION . ' / ' . gmdate( 'c' ) . "\n" );
	fwrite( $fh, "-- Site: " . home_url() . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n" );

	foreach ( $tables as $table ) {
		$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
		if ( ! $create ) {
			fclose( $fh );
			@unlink( $tmp );
			return array( 'ok' => false, 'error' => 'SHOW CREATE TABLE failed for ' . $table );
		}
		fwrite( $fh, "\nDROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n" );

		$offset = 0;
		$chunk  = 500;
		while ( true ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$offset}, {$chunk}", ARRAY_N );
			if ( empty( $rows ) ) {
				break;
			}
			$values = array();
			foreach ( $rows as $row ) {
				$escaped = array();
				foreach ( $row as $val ) {
					$escaped[] = is_null( $val ) ? 'NULL' : "'" . esc_sql( $val ) . "'";
				}
				$values[] = '(' . implode( ',', $escaped ) . ')';
			}
			fwrite( $fh, "INSERT INTO `{$table}` VALUES\n" . implode( ",\n", $values ) . ";\n" );
			$offset += $chunk;
		}
	}
	fwrite( $fh, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
	fclose( $fh );

	// Gzip the dump. gzopen keeps memory flat even on big tables.
	$in  = fopen( $tmp, 'rb' );
	$out = gzopen( $file, 'wb9' );
	if ( ! $in || ! $out ) {
		@unlink( $tmp );
		return array( 'ok' => false, 'error' => 'Gzip stage failed' );
	}
	while ( ! feof( $in ) ) {
		gzwrite( $out, fread( $in, 1048576 ) );
	}
	fclose( $in );
	gzclose( $out );
	@unlink( $tmp );

	if ( ! file_exists( $file ) || filesize( $file ) === 0 ) {
		return array( 'ok' => false, 'error' => 'Backup file is empty after gzip' );
	}
	return array( 'ok' => true, 'file' => $file, 'error' => '' );
}

/** Delete oldest backups beyond the retention count. */
function wpdbc_prune( $dir, $retention ) {
	$files = glob( $dir . '/wpdbc-*.sql.gz' );
	if ( ! $files || count( $files ) <= $retention ) {
		return;
	}
	usort(
		$files,
		function ( $a, $b ) {
			return filemtime( $a ) - filemtime( $b );
		}
	);
	foreach ( array_slice( $files, 0, count( $files ) - $retention ) as $old ) {
		@unlink( $old );
	}
}

/* ---------------------------------------------------------------------------
 * Admin: Tools → DB Backups
 * ------------------------------------------------------------------------- */
add_action( 'admin_menu', 'wpdbc_admin_menu' );
function wpdbc_admin_menu() {
	add_management_page(
		'DB Backups',
		'DB Backups',
		'manage_options',
		'wpdbc',
		'wpdbc_admin_page'
	);
}

function wpdbc_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}
	$opts = wpdbc_get_options();

	// Save settings.
	if ( isset( $_POST['wpdbc_save'] ) && check_admin_referer( 'wpdbc_settings' ) ) {
		$opts['schedule']  = in_array( $_POST['wpdbc_schedule'], array( 'daily', 'weekly', 'disabled' ), true ) ? $_POST['wpdbc_schedule'] : 'daily';
		$opts['retention'] = max( 1, min( 60, (int) $_POST['wpdbc_retention'] ) );
		$opts['notify']    = isset( $_POST['wpdbc_notify'] ) ? 1 : 0;
		update_option( 'wpdbc_options', $opts );
		wpdbc_reschedule( $opts['schedule'] );
		echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
	}

	// Manual run.
	if ( isset( $_POST['wpdbc_run_now'] ) && check_admin_referer( 'wpdbc_run' ) ) {
		$res = wpdbc_run_backup();
		if ( $res['ok'] ) {
			echo '<div class="notice notice-success"><p>Backup completed: ' . esc_html( basename( $res['file'] ) ) . ' (' . esc_html( size_format( filesize( $res['file'] ) ) ) . ')</p></div>';
		} else {
			echo '<div class="notice notice-error"><p>Backup failed: ' . esc_html( $res['error'] ) . '</p></div>';
		}
	}

	$files     = glob( wpdbc_dir() . '/wpdbc-*.sql.gz' );
	$last_ok   = get_option( 'wpdbc_last_ok', '—' );
	$last_err  = get_option( 'wpdbc_last_error', '' );
	$next      = wp_next_scheduled( WPDBC_HOOK );
	?>
	<div class="wrap">
		<h1>DB Backups</h1>
		<p>
			Last successful backup: <strong><?php echo esc_html( $last_ok ); ?></strong> &nbsp;|&nbsp;
			Next scheduled run: <strong><?php echo $next ? esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ) ) ) : '— (disabled)'; ?></strong>
			<?php if ( $last_err ) : ?>
				<br><span style="color:#b32d2e;">Last error: <?php echo esc_html( $last_err ); ?></span>
			<?php endif; ?>
		</p>

		<h2>Settings</h2>
		<form method="post">
			<?php wp_nonce_field( 'wpdbc_settings' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="wpdbc_schedule">Schedule</label></th>
					<td>
						<select name="wpdbc_schedule" id="wpdbc_schedule">
							<option value="daily" <?php selected( $opts['schedule'], 'daily' ); ?>>Daily</option>
							<option value="weekly" <?php selected( $opts['schedule'], 'weekly' ); ?>>Weekly</option>
							<option value="disabled" <?php selected( $opts['schedule'], 'disabled' ); ?>>Disabled</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="wpdbc_retention">Keep backups</label></th>
					<td><input type="number" name="wpdbc_retention" id="wpdbc_retention" value="<?php echo (int) $opts['retention']; ?>" min="1" max="60" class="small-text"> newest backups</td>
				</tr>
				<tr>
					<th><label for="wpdbc_notify">Failure email</label></th>
					<td><label><input type="checkbox" name="wpdbc_notify" id="wpdbc_notify" value="1" <?php checked( $opts['notify'], 1 ); ?>> Email the admin when a backup fails</label></td>
				</tr>
			</table>
			<?php submit_button( 'Save Settings', 'primary', 'wpdbc_save' ); ?>
		</form>

		<h2>Run now</h2>
		<form method="post">
			<?php wp_nonce_field( 'wpdbc_run' ); ?>
			<?php submit_button( 'Backup Now', 'secondary', 'wpdbc_run_now' ); ?>
		</form>

		<h2>Stored backups</h2>
		<?php if ( empty( $files ) ) : ?>
			<p>No backups yet.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>File</th><th>Date</th><th>Size</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $files as $f ) : $base = basename( $f ); ?>
					<tr>
						<td><code><?php echo esc_html( $base ); ?></code></td>
						<td><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', filemtime( $f ) ) ) ); ?></td>
						<td><?php echo esc_html( size_format( filesize( $f ) ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpdbc_download&file=' . rawurlencode( $base ) ), 'wpdbc_dl_' . $base ) ); ?>">Download</a> |
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpdbc_delete&file=' . rawurlencode( $base ) ), 'wpdbc_del_' . $base ) ); ?>" onclick="return confirm('Delete this backup?')">Delete</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/** Resolve a backup filename safely (no path traversal, must live in our dir). */
function wpdbc_resolve_file() {
	$base = isset( $_GET['file'] ) ? basename( wp_unslash( $_GET['file'] ) ) : '';
	if ( ! preg_match( '/^wpdbc-[A-Za-z0-9]{8}-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$/', $base ) ) {
		return false;
	}
	$path = wpdbc_dir() . '/' . $base;
	return file_exists( $path ) ? $path : false;
}

add_action( 'admin_post_wpdbc_download', 'wpdbc_handle_download' );
function wpdbc_handle_download() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['file'] ) || ! check_admin_referer( 'wpdbc_dl_' . basename( wp_unslash( $_GET['file'] ) ) ) ) {
		wp_die( 'Unauthorized' );
	}
	$path = wpdbc_resolve_file();
	if ( ! $path ) {
		wp_die( 'File not found' );
	}
	nocache_headers();
	header( 'Content-Type: application/gzip' );
	header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
	header( 'Content-Length: ' . filesize( $path ) );
	readfile( $path ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

add_action( 'admin_post_wpdbc_delete', 'wpdbc_handle_delete' );
function wpdbc_handle_delete() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['file'] ) || ! check_admin_referer( 'wpdbc_del_' . basename( wp_unslash( $_GET['file'] ) ) ) ) {
		wp_die( 'Unauthorized' );
	}
	$path = wpdbc_resolve_file();
	if ( $path ) {
		@unlink( $path );
	}
	wp_safe_redirect( admin_url( 'tools.php?page=wpdbc' ) );
	exit;
}
