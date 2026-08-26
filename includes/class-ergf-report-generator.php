<?php
/**
 * ERGF Report Generator.
 *
 * @package entryreports-for-gravityforms
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Builds and sends entry summary reports for a feed. Kept independent of the GFFeedAddOn
 * glue in ERGF_AddOn so the reporting logic stays easy to follow and test.
 */
class ERGF_Report_Generator {

	// Default cap on the number of entries listed in the email body when a feed is not
	// configured to show every entry.
	const MAX_ENTRIES_LISTED = 20;

	// Used as the page size when a feed is configured to include every entry (in the body and/or
	// as a CSV attachment) instead of just the first MAX_ENTRIES_LISTED.
	const UNLIMITED_PAGE_SIZE = 100000;

	// Defaults for newly created feeds: weekly reports go out Monday at 9am site time.
	const DEFAULT_SEND_DAY  = 'monday';
	const DEFAULT_SEND_HOUR = 9;

	/**
	 * Determines whether a feed's report is due to be sent: weekly feeds fire on their chosen
	 * day of the week, monthly feeds fire on the 1st of the month, both at their chosen hour
	 * (site time). Relies on the cron running hourly so the target hour is never skipped.
	 *
	 * @param array $feed The feed row, including its decoded meta.
	 *
	 * @return bool
	 */
	public static function is_report_due( $feed ) {
		$now  = new DateTimeImmutable( 'now', wp_timezone() );
		$hour = (int) rgars( $feed, 'meta/send_time', self::DEFAULT_SEND_HOUR );

		if ( (int) $now->format( 'G' ) !== $hour ) {
			return false;
		}

		if ( 'monthly' === rgars( $feed, 'meta/frequency' ) ) {
			if ( '1' !== $now->format( 'j' ) ) {
				return false;
			}
		} else {
			$day = rgars( $feed, 'meta/day_of_week', self::DEFAULT_SEND_DAY );

			if ( strtolower( $now->format( 'l' ) ) !== $day ) {
				return false;
			}
		}

		$last_sent = rgars( $feed, 'meta/last_sent' );

		// Guard against sending twice if the hourly cron fires more than once during the target hour.
		return empty( $last_sent ) || ( time() - (int) $last_sent ) > DAY_IN_SECONDS;
	}

	/**
	 * The start and end timestamps (UTC) of the calendar month before the current one, in the
	 * site's timezone. Used so monthly reports always cover "last month" regardless of exactly
	 * when the send fires.
	 *
	 * @return array{0: int, 1: int}
	 */
	public static function get_previous_month_bounds() {
		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$start = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
		$end   = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );

		return array( $start->getTimestamp(), $end->getTimestamp() - 1 );
	}

	/**
	 * The timestamp the current reporting period should be measured from: the last time
	 * a report was sent, or the feed's creation time if it has never been sent.
	 *
	 * @param array $feed The feed row, including its decoded meta.
	 *
	 * @return int
	 */
	public static function get_period_anchor( $feed ) {
		$last_sent = rgars( $feed, 'meta/last_sent' );

		if ( ! empty( $last_sent ) ) {
			return (int) $last_sent;
		}

		$created_at = rgars( $feed, 'meta/created_at' );

		return ! empty( $created_at ) ? (int) $created_at : time();
	}

	/**
	 * Fetches the entries submitted for a form between two timestamps.
	 *
	 * @param int $form_id           The form ID.
	 * @param int $period_start_time Start of the period (inclusive), as a Unix timestamp.
	 * @param int $period_end_time   End of the period (inclusive), as a Unix timestamp.
	 * @param int $limit             Maximum number of entries to return. Pass 0 to fetch every
	 *                                entry in the period (bounded by UNLIMITED_PAGE_SIZE).
	 *
	 * @return array{0: array, 1: int} The entries (capped at $limit) and the total count.
	 */
	public static function get_entries_for_period( $form_id, $period_start_time, $period_end_time, $limit = self::MAX_ENTRIES_LISTED ) {
		$search_criteria = array(
			'status'     => 'active',
			'start_date' => self::to_site_datetime( 1786614272 ),
			'end_date'   => self::to_site_datetime( $period_end_time ),
		);

		$sorting = array(
			'key'       => 'date_created',
			'direction' => 'DESC',
		);
		$paging  = array(
			'offset'    => 0,
			'page_size' => $limit > 0 ? $limit : self::UNLIMITED_PAGE_SIZE,
		);

		$total_count = 0;
		$entries     = GFAPI::get_entries( $form_id, $search_criteria, $sorting, $paging, $total_count );

		if ( is_wp_error( $entries ) ) {
			return array( array(), 0 );
		}

		return array( $entries, (int) $total_count );
	}

	/**
	 * Converts a Unix timestamp to a MySQL datetime string in the site's configured timezone,
	 * matching what GFAPI::get_entries() expects for start_date/end_date search criteria.
	 *
	 * @param int $timestamp A Unix (UTC) timestamp.
	 *
	 * @return string
	 */
	public static function to_site_datetime( $timestamp ) {
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), 'Y-m-d H:i:s' );
	}

	/**
	 * Builds the email subject and HTML body for a report. The subject is the feed's own
	 * Report Name, so recipients see exactly what the feed was named when it was set up.
	 *
	 * @param array $form              The form the entries belong to.
	 * @param array $feed              The feed being processed.
	 * @param array $entries           The entries to list (already sized to the feed's settings).
	 * @param int   $total_count       The total number of entries in the period.
	 * @param int   $period_start_time Start of the period, as a Unix timestamp.
	 * @param int   $period_end_time   End of the period, as a Unix timestamp.
	 *
	 * @return array{0: string, 1: string} The subject and HTML body.
	 */
	public static function build_email( $form, $feed, $entries, $total_count, $period_start_time, $period_end_time ) {
		$date_format = get_option( 'date_format' );
		$form_title  = rgar( $form, 'title' );
		$subject     = rgars( $feed, 'meta/feedName' );

		$period_start_label = date_i18n( $date_format, $period_start_time );
		$period_end_label   = date_i18n( $date_format, $period_end_time );
		$entries_list_url   = admin_url( 'admin.php?page=gf_entries&id=' . absint( rgar( $form, 'id' ) ) );

		ob_start();
		?>
		<div style="font-family: sans-serif; font-size: 14px; color: #23282d;">
			<h2 style="margin-bottom: 4px;"><?php echo esc_html( $form_title ); ?></h2>
			<p style="margin-top: 0; color: #666;">
				<?php
				printf(
					/* translators: 1: period start date, 2: period end date */
					esc_html__( 'Entries received %1$s – %2$s', 'entryreports-for-gravityforms' ),
					esc_html( $period_start_label ),
					esc_html( $period_end_label )
				);
				?>
			</p>
			<p>
				<strong>
					<?php
					printf(
						/* translators: %d: number of entries */
						esc_html( _n( '%d entry received', '%d entries received', $total_count, 'entryreports-for-gravityforms' ) ),
						(int) $total_count
					);
					?>
				</strong>
			</p>
			<?php if ( ! empty( $entries ) ) : ?>
				<table cellpadding="8" cellspacing="0" style="border-collapse: collapse; width: 100%; max-width: 600px;">
					<thead>
						<tr style="text-align: left; border-bottom: 2px solid #ddd;">
							<th><?php esc_html_e( 'Entry ID', 'entryreports-for-gravityforms' ); ?></th>
							<th><?php esc_html_e( 'Date Submitted', 'entryreports-for-gravityforms' ); ?></th>
							<th><?php esc_html_e( 'View', 'entryreports-for-gravityforms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<tr style="border-bottom: 1px solid #eee;">
								<td>#<?php echo esc_html( rgar( $entry, 'id' ) ); ?></td>
								<td><?php echo esc_html( date_i18n( $date_format . ' ' . get_option( 'time_format' ), strtotime( rgar( $entry, 'date_created' ) . ' UTC' ) ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_entries&view=entry&id=' . absint( rgar( $form, 'id' ) ) . '&lid=' . absint( rgar( $entry, 'id' ) ) ) ); ?>">
										<?php esc_html_e( 'View Entry', 'entryreports-for-gravityforms' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $total_count > count( $entries ) ) : ?>
					<p>
						<?php
						printf(
							/* translators: %d: number of additional entries not listed */
							esc_html__( '+%d more entries.', 'entryreports-for-gravityforms' ),
							(int) $total_count - count( $entries )
						);
						?>
						<a href="<?php echo esc_url( $entries_list_url ); ?>"><?php esc_html_e( 'View all entries', 'entryreports-for-gravityforms' ); ?></a>
					</p>
				<?php endif; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'No entries were received during this period.', 'entryreports-for-gravityforms' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		$body = ob_get_clean();

		return array( $subject, $body );
	}

	/**
	 * Builds a CSV file of the given entries, one row per entry with a column per form field.
	 * The caller is responsible for deleting the file once it's done with it.
	 *
	 * @param array $form    The form the entries belong to.
	 * @param array $entries The entries to include.
	 *
	 * @return string|null The path to the generated CSV file, or null if there are no entries.
	 */
	public static function build_entries_csv( $form, $entries ) {
		if ( empty( $entries ) ) {
			return null;
		}

		$fields = array();

		foreach ( $form['fields'] as $field ) {
			if ( in_array( $field->type, array( 'page', 'section', 'html', 'captcha' ), true ) ) {
				continue;
			}

			$fields[] = $field;
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// wp_tempnam() always suffixes the file with .tmp regardless of the name passed in, so
		// rename it to end in .csv - that's the extension/filename email clients will show.
		// Direct filesystem functions are used for this transient CSV rather than WP_Filesystem
		// because fputcsv() needs a stream resource, which WP_Filesystem doesn't provide.
		$temp_path = wp_tempnam( 'entry-report-' . absint( rgar( $form, 'id' ) ) . '.csv' );
		$file_path = preg_replace( '/\.tmp$/', '.csv', $temp_path );
		rename( $temp_path, $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename

		$file = fopen( $file_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		$header = array( esc_html__( 'Entry ID', 'entryreports-for-gravityforms' ), esc_html__( 'Date Submitted', 'entryreports-for-gravityforms' ) );

		foreach ( $fields as $field ) {
			$header[] = $field->get_field_label( true, '' );
		}

		fputcsv( $file, $header );

		$date_time_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		foreach ( $entries as $entry ) {
			$row = array(
				rgar( $entry, 'id' ),
				date_i18n( $date_time_format, strtotime( rgar( $entry, 'date_created' ) . ' UTC' ) ),
			);

			foreach ( $fields as $field ) {
				$row[] = $field->get_value_export( $entry, $field->id, false, true );
			}

			fputcsv( $file, $row );
		}

		fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $file_path;
	}

	/**
	 * Builds and sends the report for a feed.
	 *
	 * @param array $feed    The feed row, including its decoded meta.
	 * @param bool  $is_test Whether this is a manual test send. Test sends don't update last_sent.
	 *
	 * @return true|WP_Error
	 */
	public static function send_report( $feed, $is_test = false ) {
		$form_id = (int) rgar( $feed, 'form_id' );
		$form    = GFAPI::get_form( $form_id );

		if ( ! $form ) {
			return new WP_Error( 'ergf_invalid_form', esc_html__( 'The form for this report no longer exists.', 'entryreports-for-gravityforms' ) );
		}

		$recipients = ergf_addon()->parse_recipient_emails( rgars( $feed, 'meta/recipients' ) );

		if ( empty( $recipients ) ) {
			return new WP_Error( 'ergf_no_recipients', esc_html__( 'No recipient email addresses are configured for this report.', 'entryreports-for-gravityforms' ) );
		}

		$show_all_entries = '1' === (string) rgars( $feed, 'meta/show_all_entries' );
		$attach_entries   = '1' === (string) rgars( $feed, 'meta/attach_entries' );

		$fetch_every_entry = $attach_entries || $show_all_entries;

		if ( 'monthly' === rgars( $feed, 'meta/frequency' ) ) {
			list( $period_start_time, $period_end_time ) = self::get_previous_month_bounds();
		} else {
			$period_end_time   = time();
			$period_start_time = self::get_period_anchor( $feed );
		}

		list( $entries, $total_count ) = self::get_entries_for_period(
			$form_id,
			$period_start_time,
			$period_end_time,
			$fetch_every_entry ? 0 : self::MAX_ENTRIES_LISTED
		);

		list( $subject, $message ) = self::build_email( $form, $feed, $entries, $total_count, $period_start_time, $period_end_time );

		if ( $is_test ) {
			$subject = '[' . esc_html__( 'TEST', 'entryreports-for-gravityforms' ) . '] ' . $subject;
		}

		$from_email = get_bloginfo( 'admin_email' );
		$from_name  = get_bloginfo( 'name' );
		$to         = implode( ',', $recipients );

		$attachment_path = $attach_entries ? self::build_entries_csv( $form, $entries ) : null;
		$attachments     = $attachment_path ? array( $attachment_path ) : array();

		// GFCommon::send_email() doesn't return a success/failure value, so capture errors via its hooks instead.
		$mail_error       = null;
		$capture_gf_error = function ( $error ) use ( &$mail_error ) {
			$mail_error = is_wp_error( $error ) ? $error->get_error_message() : $error;
		};
		$capture_wp_error = function ( $wp_error ) use ( &$mail_error ) {
			$mail_error = $wp_error->get_error_message();
		};

		add_action( 'gform_send_email_failed', $capture_gf_error );
		add_action( 'wp_mail_failed', $capture_wp_error );

		GFCommon::send_email( $from_email, $to, '', '', $subject, $message, $from_name, 'html', $attachments );

		remove_action( 'gform_send_email_failed', $capture_gf_error );
		remove_action( 'wp_mail_failed', $capture_wp_error );

		if ( $attachment_path && file_exists( $attachment_path ) ) {
			wp_delete_file( $attachment_path );
		}

		if ( $mail_error ) {
			return new WP_Error( 'ergf_send_failed', $mail_error );
		}

		if ( ! $is_test ) {
			$meta              = is_array( rgar( $feed, 'meta' ) ) ? $feed['meta'] : array();
			$meta['last_sent'] = $period_end_time;
			ergf_addon()->update_feed_meta( rgar( $feed, 'id' ), $meta );
		}

		return true;
	}
}
