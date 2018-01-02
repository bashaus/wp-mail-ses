<?php

class WP_Mail_SES {


	const VERSION = '0.0.1';

	const FILTER_EMAIL_SENT = 'wp_mail_ses_sent_email';

	public $ses;

	protected static $instance;

	public static function get_instance() {
		if ( ! isset( static::$instance ) ) {
			static::$instance = new self;
		}

		return static::$instance;
	}

	private function __construct() {
		if ( is_admin() ) {
			add_action( 'init', array( $this, 'init' ) );
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );

			register_activation_hook( __FILE__, array( $this, 'install' ) );
			register_deactivation_hook( __FILE__, array( $this, 'uninstall' ) );
		}

		add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), 1 );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), 1 );

		try {
			$this->ses = new SimpleEmailService(
				$this->get_aws_access_key_id(),
				$this->get_aws_secret_access_key(),
				$this->get_aws_endpoint()
			);
		} catch ( Exception $e ) {
			return null;
		}

		if ( function_exists( 'wp_mail' ) ) {
			add_action( 'admin_notices', array( $this, 'warning_wp_mail_exists' ) );
		}

		require_once 'functions.php';
	}

	public function init() {
		load_plugin_textdomain( 'wp-mail-ses', false, __DIR__ . '/lang/' );

		if ( ! function_exists( 'curl_version' ) ) {
			add_action( 'admin_notices', array( $this, 'warning_curl' ) );
		}

		if ( ! defined( 'WP_MAIL_SES_ACCESS_KEY_ID' ) ) {
			add_action( 'admin_notices', array( $this, 'warning_access_key_id' ) );
		}

		if ( ! defined( 'WP_MAIL_SES_SECRET_ACCESS_KEY' ) ) {
			add_action( 'admin_notices', array( $this, 'warning_secret_access_key' ) );
		}

		if ( ! defined( 'WP_MAIL_SES_ENDPOINT' ) ) {
			add_action( 'admin_notices', array( $this, 'warning_endpoint' ) );
		}
	}

	public function warning_curl() {
		?>
			<div class="error fade">
				<p><?php _e( 'WP Mail SES: CURL is required.', 'wp-mail-ses' ) ?></p>
			</div>
		<?php
	}

	public function warning_access_key_id() {
		?>
			<div class="error fade">
				<p>
					<?php _e( 'WP Mail SES: You must define WP_MAIL_SES_ACCESS_KEY_ID in wp-config.php.', 'wp-mail-ses' ) ?>
				</p>
			</div>
		<?php
	}

	public function warning_secret_access_key() {
		?>
			<div class="error fade">
				<p>
					<?php _e( 'WP Mail SES: You must define WP_MAIL_SES_SECRET_ACCESS_KEY in wp-config.php.', 'wp-mail-ses' ) ?>
				</p>
			</div>
		<?php
	}

	public function warning_endpoint() {
		?>
			<div class="error fade">
				<p>
					<?php _e( 'WP Mail SES: You must define WP_MAIL_SES_ENDPOINT in wp-config.php.', 'wp-mail-ses' ) ?>
				</p>
			</div>
		<?php
	}

	public function warning_wp_mail_exists() {
		?>
			<div class="error fade">
				<p>
					<?php _e( 'WP Mail SES: Another mail plugin is currently activated.', 'wp-mail-ses' ); ?>
					<?php _e( 'WP Mail SES will not work until it is disabled.', 'wp-mail-ses' ) ?>
				</p>
			</div>
		<?php
	}

	public function admin_menu() {
		add_options_page(
			__( 'WP Mail SES', 'wp-mail-ses' ),
			__( 'WP Mail SES', 'wp-mail-ses' ),
			'manage_options',
			'wp-mail-ses/controllers/admin.php',
			array( $this, 'controller_settings' )
		);

		if ( $this->is_statistics_enabled() ) {
			add_submenu_page(
				'index.php',
				'SES Statistics',
				'SES Statistics',
				'manage_options',
				'wp-mail-ses/controllers/stats.php',
				array( $this, 'controller_statistics' )
			);
		}
	}

	public function is_statistics_enabled() {
		if ( ! defined( 'WP_MAIL_SES_HIDE_STATISTICS' ) ) {
			return true;
		}

		return WP_MAIL_SES_HIDE_STATISTICS == false;
	}

	public function controller_settings() {
		require_once __DIR__ . '/controllers/class-wp-mail-ses-settings.php';
		WP_Mail_SES_Settings::get_instance()->index();
	}

	public function controller_statistics() {
		require_once __DIR__ . '/controllers/class-wp-mail-ses-statistics.php';
		WP_Mail_SES_Statistics::get_instance()->index();
	}

	public function install() {
	}

	public function uninstall() {
	}

	public function get_aws_access_key_id() {
		if ( ! defined( 'WP_MAIL_SES_ACCESS_KEY_ID' ) ) {
			throw new Exception( 'Missing required constant: WP_MAIL_SES_ACCESS_KEY_ID' );
		}

		return WP_MAIL_SES_ACCESS_KEY_ID;
	}

	public function get_aws_secret_access_key() {
		if ( ! defined( 'WP_MAIL_SES_SECRET_ACCESS_KEY' ) ) {
			throw new Exception( 'Missing required constant: WP_MAIL_SES_SECRET_ACCESS_KEY' );
		}

		return WP_MAIL_SES_SECRET_ACCESS_KEY;
	}

	public function get_aws_endpoint() {
		if ( defined( 'WP_MAIL_SES_ENDPOINT' ) ) {
			return WP_MAIL_SES_ENDPOINT;
		}

		return 'email.us-east-1.amazonaws.com';
	}

	public function filter_from_email( $default = null ) {
		if ( defined( 'WP_MAIL_SES_COMPOSER_EMAIL' ) ) {
			return WP_MAIL_SES_COMPOSER_EMAIL;
		}

		return $default;
	}

	public function filter_from_name( $default = null ) {
		if ( defined( 'WP_MAIL_SES_COMPOSER_NAME' ) ) {
			return WP_MAIL_SES_COMPOSER_NAME;
		}

		return $default;
	}

	public function get_verified_emails() {
		try {
			$result = $this->ses->listVerifiedEmailAddresses();

			if ( is_array( $result ) ) {
				return $result['Addresses'];
			}
		} catch ( Exception $e ) {

		}

		return array();
	}

	public function send_email( $recipients, $subject, $message, $headers = '', $attachments = '' ) {
		/*
        list($recipients, $subject, $message, $headers, $attachments) = apply_filters(
            'wp_mail', array(
                'to'        => $recipients,
                'subject'   => $subject,
                'message'   => $message,
                'headers'   => $headers
            )
        );
        */

		$m = new SimpleEmailServiceMessage;

		// Convert headers to string
		if ( is_array( $headers ) ) {
			$headers = implode( PHP_EOL, $headers );
		}

		// Recipients may contain comma separated emails
		$recipients = explode( ',', $recipients );

		foreach ( $recipients as $recipient ) {
			$m->addTo( $recipient );
		}

		// Message
		$html = $message;

		$text = strip_tags( $html );
		$text = html_entity_decode( $text, ENT_NOQUOTES, 'UTF-8' );

		$m->setFrom(sprintf(
			'%s <%s>',
			apply_filters( 'wp_mail_from_name', WP_MAIL_SES_COMPOSER_NAME ),
			apply_filters( 'wp_mail_from', WP_MAIL_SES_COMPOSER_EMAIL )
		));

		$m->setSubject( $subject );
		$m->setMessageFromString( $text, $html );

		// Attachments
		if ( ! empty( $attachments ) ) {
			if ( ! is_array( $attachments ) ) {
				$attachments = explode( PHP_EOL, $attachments );
			}

			foreach ( $attachments as $attachment ) {
				$m->addAttachmentFromFile( basename( $attachment ), $attachment );
			}
		}

		try {
			$result = $this->ses->sendEmail( $m );
		} catch ( Exception $e ) {
			// Silence
		}

		$mail_data = array(
			'to'                => $recipients,
			'subject'           => $subject,
			'message'           => $message,
			'headers'           => $headers,
			'attachments'       => $attachments,
		);

		return apply_filters(
			static::FILTER_EMAIL_SENT,
			is_array( $result ) ? $result['MessageId'] : null,
			$mail_data
		);
	}
}
