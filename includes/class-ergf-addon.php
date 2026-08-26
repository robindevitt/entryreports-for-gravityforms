<?php
/**
 * ERGF Addon.
 *
 * @package entryreports-for-gravityforms
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Registers the "Entry Reports" feed with Gravity Forms: the feed settings UI, the feed
 * list columns/actions, and the cron wiring that hands off to ERGF_Report_Generator when
 * a report is due. Framework glue only - the actual report building/sending lives in
 * ERGF_Report_Generator.
 */
class ERGF_AddOn extends GFFeedAddOn {

	// The recurring cron event that checks every active feed for due reports.
	const CRON_HOOK = 'ergf_process_reports';

	// Nonce action for the "Send Test Now" feed list link.
	const TEST_SEND_NONCE_ACTION = 'ergf_send_test_report';

	// The underscore-prefixed names below are required overrides of GFAddOn/GFFeedAddOn
	// properties - the Gravity Forms framework reads these exact names, so they can't be
	// renamed to satisfy WordPress' no-underscore-prefix convention.

	/**
	 * The add-on version, shown to Gravity Forms on the Add-Ons screen.
	 *
	 * @var string
	 */
	protected $_version = ERGF_VERSION; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Minimum Gravity Forms version this add-on supports.
	 *
	 * @var string
	 */
	protected $_min_gravityforms_version = '2.5'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * The add-on's unique slug.
	 *
	 * @var string
	 */
	protected $_slug = 'entryreports'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Relative path to the main plugin file.
	 *
	 * @var string
	 */
	protected $_path = 'entryreports-for-gravityforms/entryreports-for-gravityforms.php'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	protected $_full_path = ERGF_PLUGIN_FILE; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Full add-on title, shown on the Add-Ons and settings screens.
	 *
	 * @var string
	 */
	protected $_title = 'Entry Reports for Gravity Forms'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Short add-on title, shown in the Forms admin menu.
	 *
	 * @var string
	 */
	protected $_short_title = 'Entry Reports'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Capabilities required to manage report feeds and to uninstall the add-on.
	 *
	 * @var array
	 */
	protected $_capabilities = array( 'gravityforms_entryreports', 'gravityforms_entryreports_uninstall' ); // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Capability required to access the add-on's settings page.
	 *
	 * @var string
	 */
	protected $_capabilities_settings_page = 'gravityforms_entryreports'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Capability required to uninstall the add-on.
	 *
	 * @var string
	 */
	protected $_capabilities_uninstall = 'gravityforms_entryreports_uninstall'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Holds the singleton instance returned by get_instance().
	 *
	 * @var ERGF_AddOn|null
	 */
	private static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Get an instance of this class.
	 *
	 * @return ERGF_AddOn
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new ERGF_AddOn();
		}

		return self::$_instance;
	}

	/**
	 * Handles hooks and cron registration.
	 */
	public function init() {
		parent::init();

		add_action( self::CRON_HOOK, array( $this, 'process_due_feeds' ) );
		$this->setup_cron();

		add_action( 'admin_init', array( $this, 'maybe_handle_test_send' ) );
		add_action( 'admin_notices', array( $this, 'render_test_send_notice' ) );
		add_action( 'gform_post_save_feed_settings', array( $this, 'maybe_set_report_anchor' ), 10, 4 );
	}

	/**
	 * Uninstall the add-on, clearing the scheduled cron event first.
	 */
	public function uninstall() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		parent::uninstall();
	}


	// # CRON -----------------------------------------------------------------------------------------------------

	/**
	 * Schedules the hourly cron event which checks feeds for reports that are due. Hourly is
	 * needed so a feed's chosen send hour is never missed between checks.
	 */
	public function setup_cron() {
		if ( wp_next_scheduled( self::CRON_HOOK ) && 'hourly' !== wp_get_schedule( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback. Checks every active feed across all forms and sends any reports that are due.
	 */
	public function process_due_feeds() {
		$feeds = $this->get_active_feeds();

		foreach ( $feeds as $feed ) {
			if ( ERGF_Report_Generator::is_report_due( $feed ) ) {
				ERGF_Report_Generator::send_report( $feed );
			}
		}
	}

	/**
	 * Stamps a feed with its creation time the first time it's saved, since the feeds table has
	 * no date_created column of its own. Used as the anchor for the first report period.
	 *
	 * @param int     $feed_id  The ID of the feed which was saved.
	 * @param int     $form_id  The current form ID associated with the feed.
	 * @param array   $settings The submitted feed settings.
	 * @param GFAddOn $addon   The add-on instance the save occurred for.
	 */
	public function maybe_set_report_anchor( $feed_id, $form_id, $settings, $addon ) {
		if ( ! $feed_id || $addon->get_slug() !== $this->get_slug() ) {
			return;
		}

		$feed = $this->get_feed( $feed_id );
		$meta = is_array( rgar( $feed, 'meta' ) ) ? $feed['meta'] : array();

		if ( empty( $meta['created_at'] ) ) {
			$meta['created_at'] = time();
			$this->update_feed_meta( $feed_id, $meta );
		}
	}

	/**
	 * Suffixes the framework's default feed name with the current form's title, so a newly
	 * created report's name already identifies the form it belongs to before the user has
	 * typed anything into the Report Name field.
	 *
	 * @return string
	 */
	public function get_default_feed_name() {
		$default_name = parent::get_default_feed_name();
		$form         = $this->get_current_form();
		$form_title   = $form ? rgar( $form, 'title' ) : '';

		if ( ! $form_title ) {
			return $default_name;
		}

		return $default_name . ' - ' . $form_title;
	}


	// # FEED LIST --------------------------------------------------------------------------------------------------

	/**
	 * Configures the columns which should be rendered on the feed list page.
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feedName'   => esc_html__( 'Name', 'entryreports-for-gravityforms' ),
			'recipients' => esc_html__( 'Recipients', 'entryreports-for-gravityforms' ),
			'frequency'  => esc_html__( 'Frequency', 'entryreports-for-gravityforms' ),
			'last_sent'  => esc_html__( 'Last Sent', 'entryreports-for-gravityforms' ),
		);
	}

	/**
	 * Renders the recipients column, truncating long lists.
	 *
	 * @param array $feed The feed being rendered.
	 *
	 * @return string
	 */
	public function get_column_value_recipients( $feed ) {
		$emails = $this->parse_recipient_emails( rgars( $feed, 'meta/recipients' ) );

		if ( empty( $emails ) ) {
			return '&mdash;';
		}

		if ( count( $emails ) > 3 ) {
			$shown = array_slice( $emails, 0, 3 );

			return esc_html(
				sprintf(
				/* translators: 1: comma separated list of email addresses, 2: number of additional recipients */
					__( '%1$s +%2$d more', 'entryreports-for-gravityforms' ),
					implode( ', ', $shown ),
					count( $emails ) - 3
				)
			);
		}

		return esc_html( implode( ', ', $emails ) );
	}

	/**
	 * Renders the frequency column using the human readable label.
	 *
	 * @param array $feed The feed being rendered.
	 *
	 * @return string
	 */
	public function get_column_value_frequency( $feed ) {
		$frequency = rgars( $feed, 'meta/frequency' );
		$label     = $frequency;

		foreach ( $this->get_frequency_choices() as $choice ) {
			if ( $choice['value'] === $frequency ) {
				$label = $choice['label'];
				break;
			}
		}

		$hour       = (int) rgars( $feed, 'meta/send_time', ERGF_Report_Generator::DEFAULT_SEND_HOUR );
		$time_label = gmdate( 'g:i a', gmmktime( $hour, 0, 0 ) );

		if ( 'monthly' === $frequency ) {
			return esc_html(
				sprintf(
				/* translators: 1: frequency label, 2: time of day */
					__( '%1$s (1st at %2$s)', 'entryreports-for-gravityforms' ),
					$label,
					$time_label
				)
			);
		}

		$day        = rgars( $feed, 'meta/day_of_week', ERGF_Report_Generator::DEFAULT_SEND_DAY );
		$day_labels = wp_list_pluck( $this->get_day_of_week_choices(), 'label', 'value' );
		$day_label  = rgar( $day_labels, $day, $day );

		return esc_html(
			sprintf(
			/* translators: 1: frequency label, 2: day of the week, 3: time of day */
				__( '%1$s (%2$s at %3$s)', 'entryreports-for-gravityforms' ),
				$label,
				$day_label,
				$time_label
			)
		);
	}

	/**
	 * Renders the last sent column.
	 *
	 * @param array $feed The feed being rendered.
	 *
	 * @return string
	 */
	public function get_column_value_last_sent( $feed ) {
		$last_sent = rgars( $feed, 'meta/last_sent' );

		if ( empty( $last_sent ) ) {
			return esc_html__( 'Never', 'entryreports-for-gravityforms' );
		}

		return esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sent ) );
	}

	/**
	 * Adds a "Send Test Now" action link alongside the framework's edit/duplicate/delete links.
	 *
	 * @return array
	 */
	public function get_action_links() {
		$links   = parent::get_action_links();
		$feed_id = '_id_';

		$test_url = add_query_arg(
			array(
				'ergf_action'  => 'send_test',
				'ergf_feed_id' => $feed_id,
			)
		);
		$test_url = wp_nonce_url( $test_url, self::TEST_SEND_NONCE_ACTION );

		$links['ergf_test'] = '<a href="' . esc_url( $test_url ) . '">' . esc_html__( 'Send Test Now', 'entryreports-for-gravityforms' ) . '</a>';

		return $links;
	}


	// # FEED SETTINGS ----------------------------------------------------------------------------------------------

	/**
	 * Configures the settings which should be rendered on the Form Settings > Entry Reports feed edit view.
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		return array(
			array(
				'title'  => esc_html__( 'Entry Report Settings', 'entryreports-for-gravityforms' ),
				'fields' => array(
					array(
						'name'     => 'feedName',
						'label'    => esc_html__( 'Report Name', 'entryreports-for-gravityforms' ),
						'type'     => 'text',
						'class'    => 'medium',
						'required' => true,
					),
					array(
						'name'                => 'recipients',
						'label'               => esc_html__( 'Recipient Email Addresses', 'entryreports-for-gravityforms' ),
						'type'                => 'text',
						'class'               => 'medium',
						'required'            => true,
						'tooltip'             => esc_html__( 'Enter recipient email addresses, separated by commas.', 'entryreports-for-gravityforms' ),
						'validation_callback' => array( $this, 'validate_recipients' ),
					),
					array(
						'name'     => 'frequency',
						'label'    => esc_html__( 'Report Frequency', 'entryreports-for-gravityforms' ),
						'type'     => 'select',
						'required' => true,
						'choices'  => $this->get_frequency_choices(),
					),
					array(
						'name'          => 'day_of_week',
						'label'         => esc_html__( 'Day of Week', 'entryreports-for-gravityforms' ),
						'type'          => 'select',
						'choices'       => $this->get_day_of_week_choices(),
						'default_value' => ERGF_Report_Generator::DEFAULT_SEND_DAY,
						'dependency'    => array(
							'live'   => true,
							'fields' => array(
								array(
									'field'  => 'frequency',
									'values' => array( 'weekly' ),
								),
							),
						),
						'tooltip'       => esc_html__( 'The day of the week weekly reports are sent on.', 'entryreports-for-gravityforms' ),
					),
					array(
						'name'          => 'send_time',
						'label'         => esc_html__( 'Send Time', 'entryreports-for-gravityforms' ),
						'type'          => 'select',
						'choices'       => $this->get_send_time_choices(),
						'default_value' => (string) ERGF_Report_Generator::DEFAULT_SEND_HOUR,
						'tooltip'       => esc_html__( 'The site-local time of day the report is sent. Monthly reports send on the 1st of the month at this time and cover the previous month.', 'entryreports-for-gravityforms' ),
					),
					array(
						'name'    => 'report_content',
						'label'   => esc_html__( 'Report Content', 'entryreports-for-gravityforms' ),
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'name'    => 'show_all_entries',
								'label'   => esc_html__( 'List all entries in the email body, not just the first 20', 'entryreports-for-gravityforms' ),
								'tooltip' => esc_html__( 'By default the email lists up to 20 entries. Enable this to list every entry received during the period instead.', 'entryreports-for-gravityforms' ),
							),
							array(
								'name'    => 'attach_entries',
								'label'   => esc_html__( 'Attach the entries as a CSV file', 'entryreports-for-gravityforms' ),
								'tooltip' => esc_html__( 'Attach a CSV file containing every entry received during the period to the email.', 'entryreports-for-gravityforms' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * The frequency choices available for a report.
	 *
	 * @return array
	 */
	public function get_frequency_choices() {
		return array(
			array(
				'label' => esc_html__( 'Weekly', 'entryreports-for-gravityforms' ),
				'value' => 'weekly',
			),
			array(
				'label' => esc_html__( 'Monthly', 'entryreports-for-gravityforms' ),
				'value' => 'monthly',
			),
		);
	}

	/**
	 * The day-of-week choices available for weekly reports.
	 *
	 * @return array
	 */
	public function get_day_of_week_choices() {
		return array(
			array(
				'label' => esc_html__( 'Sunday', 'entryreports-for-gravityforms' ),
				'value' => 'sunday',
			),
			array(
				'label' => esc_html__( 'Monday', 'entryreports-for-gravityforms' ),
				'value' => 'monday',
			),
			array(
				'label' => esc_html__( 'Tuesday', 'entryreports-for-gravityforms' ),
				'value' => 'tuesday',
			),
			array(
				'label' => esc_html__( 'Wednesday', 'entryreports-for-gravityforms' ),
				'value' => 'wednesday',
			),
			array(
				'label' => esc_html__( 'Thursday', 'entryreports-for-gravityforms' ),
				'value' => 'thursday',
			),
			array(
				'label' => esc_html__( 'Friday', 'entryreports-for-gravityforms' ),
				'value' => 'friday',
			),
			array(
				'label' => esc_html__( 'Saturday', 'entryreports-for-gravityforms' ),
				'value' => 'saturday',
			),
		);
	}

	/**
	 * The hour-of-day choices available for when a report is sent, labelled in a 12-hour
	 * format. Built from plain UTC times since these are just hour labels, not real moments
	 * in time - going through site-local formatting here would double up the timezone offset.
	 *
	 * @return array
	 */
	public function get_send_time_choices() {
		$choices = array();

		for ( $hour = 0; $hour < 24; $hour++ ) {
			$choices[] = array(
				'label' => gmdate( 'g:i a', gmmktime( $hour, 0, 0 ) ),
				'value' => (string) $hour,
			);
		}

		return $choices;
	}

	/**
	 * Validates the recipients field, ensuring at least one valid email address has been entered.
	 *
	 * @param array  $field The field being validated.
	 * @param string $value The submitted value.
	 */
	public function validate_recipients( $field, $value ) {
		$emails = $this->parse_recipient_emails( $value );

		if ( empty( $emails ) ) {
			$this->set_field_error( $field, esc_html__( 'Please enter at least one recipient email address.', 'entryreports-for-gravityforms' ) );

			return;
		}

		foreach ( $emails as $email ) {
			if ( ! GFCommon::is_valid_email( $email ) ) {
				$this->set_field_error(
					$field,
					sprintf(
					/* translators: %s: the invalid email address */
						esc_html__( '%s is not a valid email address.', 'entryreports-for-gravityforms' ),
						esc_html( $email )
					)
				);

				return;
			}
		}
	}

	/**
	 * Splits a comma/newline separated string of email addresses into a clean array.
	 *
	 * @param string $value The raw field value.
	 *
	 * @return array
	 */
	public function parse_recipient_emails( $value ) {
		$parts = preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );

		return array_values( array_unique( array_map( 'trim', $parts ) ) );
	}


	// # TEST SEND ---------------------------------------------------------------------------------------------------

	/**
	 * Handles clicks on the "Send Test Now" feed list action link.
	 */
	public function maybe_handle_test_send() {
		if ( rgget( 'ergf_action' ) !== 'send_test' ) {
			return;
		}

		if ( ! $this->current_user_can_any( $this->get_form_settings_capabilities() ) ) {
			return;
		}

		check_admin_referer( self::TEST_SEND_NONCE_ACTION );

		$feed_id      = absint( rgget( 'ergf_feed_id' ) );
		$feed         = $this->get_feed( $feed_id );
		$redirect_url = remove_query_arg( array( 'ergf_action', 'ergf_feed_id', '_wpnonce' ) );

		if ( $feed ) {
			$result = ERGF_Report_Generator::send_report( $feed, true );

			set_transient(
				'ergf_test_notice_' . get_current_user_id(),
				array(
					'type'    => is_wp_error( $result ) ? 'error' : 'success',
					'message' => is_wp_error( $result ) ? $result->get_error_message() : esc_html__( 'Test report sent.', 'entryreports-for-gravityforms' ),
				),
				60
			);
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Renders the result of a test send, if one just occurred.
	 */
	public function render_test_send_notice() {
		$key    = 'ergf_test_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! $notice ) {
			return;
		}

		delete_transient( $key );

		$class = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
		<?php
	}
}
