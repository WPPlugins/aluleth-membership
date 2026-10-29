<?php
/**
 * Setup assistants: a few short questions a plugin asks once, opened by the
 * site owner (never forced on activation), every step can be skipped.
 *
 * A plugin adds its assistant on the `aluleth/setup/register` action with
 * Setup::add(). The core draws the screen (Aluleth › Get started), keeps the
 * answers, shows one notice on the Plugins screen until the plugin is set up
 * (each person can hide it) and gives the plugin a card for its empty screens.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Setup assistants.
 */
final class Setup {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'aluleth-setup';

	/**
	 * Option with the progress of every assistant.
	 */
	const OPTION = 'aluleth_setup';

	/**
	 * User meta: assistants whose Plugins-screen notice the person hid.
	 */
	const HIDDEN = 'aluleth_setup_hidden';

	/**
	 * Assistants added so far.
	 *
	 * @var array<string, array>|null
	 */
	private static $all = null;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 99 );
		add_action( 'admin_post_aluleth_setup', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_aluleth_setup_hide', array( __CLASS__, 'hide' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Adds an assistant (call it on `aluleth/setup/register`).
	 *
	 * @param string $id   Assistant id (the plugin slug).
	 * @param array  $args {
	 *     Settings of the assistant.
	 *
	 *     @type string   $title   Title ("Set up Aluleth Funnels").
	 *     @type string   $plugin  Plugin name ("Aluleth Funnels").
	 *     @type string   $cap     Capability needed.
	 *     @type string   $back    Address of the plugin's main screen.
	 *     @type callable $ready   Returns true once the plugin is in use (no notice, no card).
	 *     @type callable $finish  Address to go to at the end, from the answers.
	 *     @type array    $steps   Step id => title, hint, render( answers ): string HTML,
	 *                             save( posted, answers ): array answers|\WP_Error, summary( answers ): string.
	 * }
	 * @return void
	 */
	public static function add( $id, array $args ) {
		$id = sanitize_key( $id );
		if ( '' === $id || empty( $args['steps'] ) || ! is_array( $args['steps'] ) ) {
			return;
		}
		$steps = array();
		foreach ( $args['steps'] as $key => $step ) {
			if ( is_array( $step ) && ! empty( $step['title'] ) && isset( $step['render'] ) && is_callable( $step['render'] ) ) {
				$steps[ sanitize_key( $key ) ] = $step;
			}
		}
		if ( ! $steps ) {
			return;
		}
		self::$all[ $id ] = array(
			'id'     => $id,
			'title'  => isset( $args['title'] ) ? (string) $args['title'] : $id,
			'plugin' => isset( $args['plugin'] ) ? (string) $args['plugin'] : $id,
			'cap'    => isset( $args['cap'] ) ? (string) $args['cap'] : 'manage_options',
			'back'   => isset( $args['back'] ) ? (string) $args['back'] : admin_url(),
			'ready'  => isset( $args['ready'] ) && is_callable( $args['ready'] ) ? $args['ready'] : '__return_false',
			'finish' => isset( $args['finish'] ) && is_callable( $args['finish'] ) ? $args['finish'] : null,
			'steps'  => $steps,
		);
	}

	/**
	 * Every assistant (asks the plugins once).
	 *
	 * @return array<string, array>
	 */
	public static function all() {
		if ( null === self::$all ) {
			self::$all = array();
			/**
			 * Plugins add their setup assistant here, with Setup::add().
			 */
			do_action( 'aluleth/setup/register' );
		}
		return self::$all;
	}

	/**
	 * One assistant, or null.
	 *
	 * @param string $id Assistant id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Progress of an assistant.
	 *
	 * @param string $id Assistant id.
	 * @return array{step: string, answers: array, done: bool, closed: bool}
	 */
	public static function state( $id ) {
		$all   = get_option( self::OPTION, array() );
		$state = is_array( $all ) && isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
		return array(
			'step'    => isset( $state['step'] ) ? sanitize_key( (string) $state['step'] ) : '',
			'answers' => isset( $state['answers'] ) && is_array( $state['answers'] ) ? $state['answers'] : array(),
			'done'    => ! empty( $state['done'] ),
			'closed'  => ! empty( $state['closed'] ),
		);
	}

	/**
	 * Saves the progress of an assistant.
	 *
	 * @param string $id    Assistant id.
	 * @param array  $state Progress.
	 * @return void
	 */
	private static function put( $id, array $state ) {
		$all        = get_option( self::OPTION, array() );
		$all        = is_array( $all ) ? $all : array();
		$all[ $id ] = array(
			'step'    => isset( $state['step'] ) ? (string) $state['step'] : '',
			'answers' => isset( $state['answers'] ) && is_array( $state['answers'] ) ? $state['answers'] : array(),
			'done'    => ! empty( $state['done'] ),
			'closed'  => ! empty( $state['closed'] ),
		);
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Answers given in an assistant.
	 *
	 * @param string $id Assistant id.
	 * @return array
	 */
	public static function answers( $id ) {
		return self::state( $id )['answers'];
	}

	/**
	 * Whether an assistant was finished.
	 *
	 * @param string $id Assistant id.
	 * @return bool
	 */
	public static function is_done( $id ) {
		return self::state( $id )['done'];
	}

	/**
	 * Address of an assistant (at its current step, or at a given one).
	 *
	 * @param string $id   Assistant id.
	 * @param string $step Step id ('' = where the person left it).
	 * @return string
	 */
	public static function url( $id, $step = '' ) {
		$args = array(
			'page'      => self::PAGE,
			'assistant' => $id,
		);
		if ( '' !== $step ) {
			$args['step'] = $step;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Whether an assistant should still be offered (not finished, not closed, plugin not in use yet).
	 *
	 * @param array $a Assistant.
	 * @return bool
	 */
	private static function pending( array $a ) {
		$state = self::state( $a['id'] );
		return ! $state['done'] && ! $state['closed'] && ! call_user_func( $a['ready'] );
	}

	/**
	 * The hidden page (opened from the Plugins notice, the plugin's empty screens or its settings).
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page( '', __( 'Get started', 'aluleth-membership' ), __( 'Get started', 'aluleth-membership' ), 'read', self::PAGE, array( __CLASS__, 'screen' ) );
	}

	/**
	 * Card for a plugin's empty screen ('' when the assistant is not to be offered).
	 *
	 * @param string $id   Assistant id.
	 * @param string $text Short text.
	 * @return string HTML.
	 */
	public static function card( $id, $text = '' ) {
		$a = self::get( $id );
		if ( ! $a || ! current_user_can( $a['cap'] ) || ! self::pending( $a ) ) {
			return '';
		}
		$text = '' !== $text ? $text : __( 'A few short questions to get you going. Every step can be skipped.', 'aluleth-membership' );
		return '<div class="al-setup-card"><div><h2>' . esc_html( $a['title'] ) . '</h2><p>' . esc_html( $text ) . '</p></div><a class="al-button al-button--accent" href="' . esc_url( self::url( $id ) ) . '">' . esc_html__( 'Get started', 'aluleth-membership' ) . '</a></div>';
	}

	/**
	 * One notice per assistant on the Plugins screen, until the plugin is set up or the person hides it.
	 *
	 * @return void
	 */
	public static function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}
		$hidden = get_user_meta( get_current_user_id(), self::HIDDEN, true );
		$hidden = is_array( $hidden ) ? $hidden : array();
		foreach ( self::all() as $a ) {
			if ( in_array( $a['id'], $hidden, true ) || ! current_user_can( $a['cap'] ) || ! self::pending( $a ) ) {
				continue;
			}
			$hide = wp_nonce_url(
				add_query_arg(
					array(
						'action'    => 'aluleth_setup_hide',
						'assistant' => $a['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'aluleth_setup_hide'
			);
			/* translators: %s: plugin name. */
			echo '<div class="notice notice-info"><p>' . esc_html( sprintf( __( '%s is active. A few short questions set it up for your site.', 'aluleth-membership' ), $a['plugin'] ) ) . ' <a href="' . esc_url( self::url( $a['id'] ) ) . '">' . esc_html__( 'Set it up', 'aluleth-membership' ) . '</a> · <a href="' . esc_url( $hide ) . '">' . esc_html__( 'Hide this notice', 'aluleth-membership' ) . '</a></p></div>';
		}
	}

	/**
	 * Hides an assistant's Plugins-screen notice for the current person.
	 *
	 * @return void
	 */
	public static function hide() {
		check_admin_referer( 'aluleth_setup_hide' );
		$id = isset( $_GET['assistant'] ) ? sanitize_key( wp_unslash( $_GET['assistant'] ) ) : '';
		$a  = self::get( $id );
		if ( ! $a || ! current_user_can( $a['cap'] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'aluleth-membership' ), 403 );
		}
		$hidden = get_user_meta( get_current_user_id(), self::HIDDEN, true );
		$hidden = is_array( $hidden ) ? $hidden : array();
		if ( ! in_array( $id, $hidden, true ) ) {
			$hidden[] = $id;
		}
		update_user_meta( get_current_user_id(), self::HIDDEN, $hidden );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Step ids in order.
	 *
	 * @param array $a Assistant.
	 * @return string[]
	 */
	private static function keys( array $a ) {
		return array_map( 'strval', array_keys( $a['steps'] ) );
	}

	/**
	 * The assistant screen.
	 *
	 * @return void
	 */
	public static function screen() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen choices.
		$id   = isset( $_GET['assistant'] ) ? sanitize_key( wp_unslash( $_GET['assistant'] ) ) : '';
		$want = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		// phpcs:enable
		$a = self::get( $id );
		if ( ! $a || ! current_user_can( $a['cap'] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see this page.', 'aluleth-membership' ), 403 );
		}
		$state = self::state( $id );
		$keys  = self::keys( $a );
		$step  = in_array( $want, $keys, true ) ? $want : ( in_array( $state['step'], $keys, true ) ? $state['step'] : $keys[0] );
		$index = (int) array_search( $step, $keys, true );
		$def   = $a['steps'][ $step ];
		$error = get_transient( 'aluleth_setup_error_' . get_current_user_id() );
		if ( false !== $error ) {
			delete_transient( 'aluleth_setup_error_' . get_current_user_id() );
		}
		echo '<div class="wrap al-wrap al-setup">';
		echo '<p class="al-setup-crumbs"><a href="' . esc_url( $a['back'] ) . '">' . esc_html( $a['plugin'] ) . '</a> › ' . esc_html__( 'Get started', 'aluleth-membership' ) . '</p>';
		echo '<h1>' . esc_html( $a['title'] ) . '</h1>';
		echo '<hr class="wp-header-end">';
		echo '<div class="al-setup-box">';
		echo '<ol class="al-setup-steps" aria-label="' . esc_attr__( 'Steps', 'aluleth-membership' ) . '">';
		foreach ( $keys as $i => $key ) {
			$s       = $a['steps'][ $key ];
			$done    = $i < $index || $state['done'];
			$class   = $key === $step ? 'is-current' : ( $done ? 'is-done' : '' );
			$summary = $done && isset( $s['summary'] ) && is_callable( $s['summary'] ) ? (string) call_user_func( $s['summary'], $state['answers'] ) : ( isset( $s['hint'] ) ? (string) $s['hint'] : '' );
			echo '<li class="' . esc_attr( $class ) . '"' . ( $key === $step ? ' aria-current="step"' : '' ) . '><a href="' . esc_url( self::url( $id, $key ) ) . '"><b>' . esc_html( $s['title'] ) . '</b>';
			if ( '' !== $summary ) {
				echo '<span>' . esc_html( $summary ) . '</span>';
			}
			echo '</a></li>';
		}
		echo '</ol>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="al-setup-body">';
		wp_nonce_field( 'aluleth_setup' );
		echo '<input type="hidden" name="action" value="aluleth_setup"><input type="hidden" name="assistant" value="' . esc_attr( $id ) . '"><input type="hidden" name="step" value="' . esc_attr( $step ) . '">';
		/* translators: 1: step number, 2: number of steps. */
		echo '<p class="al-setup-count">' . esc_html( sprintf( __( 'Step %1$d of %2$d', 'aluleth-membership' ), $index + 1, count( $keys ) ) ) . '</p>';
		echo '<h2>' . esc_html( $def['title'] ) . '</h2>';
		if ( is_string( $error ) && '' !== $error ) {
			echo '<div class="al-setup-error" role="alert">' . esc_html( $error ) . '</div>';
		}
		echo wp_kses( (string) call_user_func( $def['render'], $state['answers'] ), Admin::allowed_html() );
		$last = count( $keys ) - 1 === $index;
		echo '<div class="al-setup-actions">';
		if ( $index > 0 ) {
			echo '<button type="submit" name="go" value="back" class="al-button al-button--ghost" formnovalidate>' . esc_html__( '← Back', 'aluleth-membership' ) . '</button>';
		} else {
			echo '<span></span>';
		}
		echo '<span class="al-setup-main">';
		if ( ! $last ) {
			echo '<button type="submit" name="go" value="skip" class="al-button al-button--ghost" formnovalidate>' . esc_html__( 'Skip', 'aluleth-membership' ) . '</button> ';
		}
		echo '<button type="submit" name="go" value="next" class="al-button al-button--accent">' . esc_html( $last ? __( 'Finish', 'aluleth-membership' ) : __( 'Save and continue', 'aluleth-membership' ) ) . '</button>';
		echo '</span></div>';
		echo '<p class="al-setup-foot"><button type="submit" name="go" value="later" class="button-link" formnovalidate>' . esc_html__( 'Finish later', 'aluleth-membership' ) . '</button> · <button type="submit" name="go" value="close" class="button-link" formnovalidate>' . esc_html__( 'Don’t show this again', 'aluleth-membership' ) . '</button></p>';
		echo '</form></div></div>';
	}

	/**
	 * A step's answers, a move, or leaving (admin-post).
	 *
	 * @return void
	 */
	public static function save() {
		check_admin_referer( 'aluleth_setup' );
		$id = isset( $_POST['assistant'] ) ? sanitize_key( wp_unslash( $_POST['assistant'] ) ) : '';
		$a  = self::get( $id );
		if ( ! $a || ! current_user_can( $a['cap'] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'aluleth-membership' ), 403 );
		}
		$keys  = self::keys( $a );
		$step  = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		$step  = in_array( $step, $keys, true ) ? $step : $keys[0];
		$go    = isset( $_POST['go'] ) ? sanitize_key( wp_unslash( $_POST['go'] ) ) : 'next';
		$index = (int) array_search( $step, $keys, true );
		$state = self::state( $id );

		if ( 'later' === $go || 'close' === $go ) {
			$state['step']   = $step;
			$state['closed'] = 'close' === $go;
			self::put( $id, $state );
			wp_safe_redirect( $a['back'] );
			exit;
		}
		if ( 'back' === $go ) {
			$state['step'] = $keys[ max( 0, $index - 1 ) ];
			self::put( $id, $state );
			wp_safe_redirect( self::url( $id, $state['step'] ) );
			exit;
		}
		if ( 'next' === $go && isset( $a['steps'][ $step ]['save'] ) && is_callable( $a['steps'][ $step ]['save'] ) ) {
			// Plain text values; each step checks its own fields again.
			$posted = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
			$result = call_user_func( $a['steps'][ $step ]['save'], is_array( $posted ) ? $posted : array(), $state['answers'] );
			if ( is_wp_error( $result ) ) {
				set_transient( 'aluleth_setup_error_' . get_current_user_id(), $result->get_error_message(), 120 );
				wp_safe_redirect( self::url( $id, $step ) );
				exit;
			}
			$state['answers'] = is_array( $result ) ? $result : $state['answers'];
		}
		if ( $index >= count( $keys ) - 1 ) {
			$state['step'] = $step;
			$state['done'] = true;
			self::put( $id, $state );
			$to = $a['finish'] ? (string) call_user_func( $a['finish'], $state['answers'] ) : $a['back'];
			wp_safe_redirect( '' !== $to ? $to : $a['back'] );
			exit;
		}
		$state['step'] = $keys[ $index + 1 ];
		self::put( $id, $state );
		wp_safe_redirect( self::url( $id, $state['step'] ) );
		exit;
	}
}
