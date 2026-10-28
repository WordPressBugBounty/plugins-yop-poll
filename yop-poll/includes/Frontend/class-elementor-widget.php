<?php
/**
 * The YOP Poll widget, for sites built with Elementor.
 *
 * Elementor sites had no way to place a poll except pasting a shortcode into a
 * Shortcode widget, which renders as nothing in the canvas — so the creator
 * positioned a blank box and hoped. A widget of our own renders through the
 * same shortcode on the server, which means Elementor draws the real poll while
 * they are still deciding where it goes.
 *
 * **This file must not load unless Elementor has.** It extends a class Elementor
 * owns, so autoloading it on a site without Elementor is a fatal. Nothing names
 * the class except `Frontend::init()`, which passes `Elementor_Widget::class` —
 * a compile-time constant that triggers no autoload — into a callback on
 * `elementor/widgets/register`. That action only fires when Elementor is running,
 * so the file is only ever read on a site that can read it.
 *
 * @package YopPoll
 */

namespace YopPoll\Frontend;

use YopPoll\Models\Model_Poll;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Places a poll in an Elementor layout.
 *
 * Analysed against `tests/stubs/elementor.php`, which declares the handful of
 * members this touches. Elementor is not — and cannot be — a Composer
 * dependency, so there is nothing else for PHPStan to read the parent from.
 */
class Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Hand the widget to Elementor.
	 *
	 * @param object $widgets Elementor's widget manager.
	 * @return void
	 */
	public static function register( $widgets ) {
		$widgets->register( new self() );
	}

	/**
	 * The machine name Elementor stores in the page's layout.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'yop-poll';
	}

	/**
	 * What the creator reads in the widget panel.
	 *
	 * This string is what the builder's "Add to a page" panel tells them to
	 * search for, so the two have to agree.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'YOP Poll', 'yop-poll' );
	}

	/**
	 * The panel icon. Elementor ships its own icon font; this is one of theirs.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/**
	 * Which panel section it appears under.
	 *
	 * @return array<int, string>
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * What someone types to find it.
	 *
	 * @return array<int, string>
	 */
	public function get_keywords() {
		return array( 'poll', 'vote', 'survey', 'yop' );
	}

	/**
	 * The poll's behaviour, enqueued by Elementor only on pages using this
	 * widget.
	 *
	 * Without this the widget renders a ballot that cannot be voted in: the
	 * shortcode enqueues the script when it runs, but declaring it here is what
	 * lets Elementor account for the widget's assets when it builds a page's
	 * asset list.
	 *
	 * @return array<int, string>
	 */
	public function get_script_depends() {
		return array( 'yop-poll' );
	}

	/**
	 * The poll's stylesheet, enqueued by Elementor only on pages using this
	 * widget.
	 *
	 * This is the whole reason a widget beats a pasted shortcode for asset
	 * loading: Elementor knows which pages hold the widget, so the CSS follows
	 * it rather than being enqueued hopefully. `hard rule 3` in `CLAUDE.md`.
	 *
	 * @return array<int, string>
	 */
	public function get_style_depends() {
		return array( 'yop-poll-classic' );
	}

	/**
	 * Label the search box the poll picker opens with.
	 *
	 * The library gives no way to do it. select2 builds that input from a fixed
	 * template with no placeholder attribute — `e-select2.full.js` renders
	 * `<input class="select2-search__field" type="search" …>` and its
	 * `createPlaceholder` only ever runs for the inline search a *multiple*
	 * select uses. So an empty box it is, unless something sets the attribute
	 * after the dropdown opens, which is all this does.
	 *
	 * Editor-only twice over: the hook is Elementor's editor hook, and the
	 * control it names exists only in the panel. Nothing here can reach a
	 * visitor.
	 *
	 * @return void
	 */
	public static function editor_script() {
		$js = sprintf(
			'jQuery( document ).on( "select2:open", %s, function () {
				var field = document.querySelector(
					".select2-container--open .select2-search__field"
				);
				if ( field ) { field.setAttribute( "placeholder", %s ); }
			} );',
			// Scoped to our own control rather than every select2 in the
			// editor, which would relabel other plugins' pickers too.
			wp_json_encode( '[data-setting="poll_id"]' ),
			wp_json_encode( __( 'Type to search polls…', 'yop-poll' ) )
		);

		wp_add_inline_script( 'elementor-editor', $js );
	}

	/**
	 * The panel controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'yop_poll_content',
			array(
				'label' => __( 'Poll', 'yop-poll' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		/*
		 * SELECT2, not SELECT, for the search box it brings: with a dozen polls
		 * a plain dropdown is a list to read, and past that it stops working.
		 *
		 * `label_block` because without it the control renders in the narrow
		 * right-hand column, where "Conference talk topics (ID 11)" is
		 * unreadable. Elementor's own SELECT2 call sites all set it.
		 *
		 * Not `multiple`: that would store an array and `shortcode_for()`'s
		 * `(int)` cast would quietly read every widget as having no poll.
		 */
		$this->add_control(
			'poll_id',
			array(
				'label'       => __( 'Poll to display', 'yop-poll' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'label_block' => true,
				'options'     => self::poll_options(),
				'default'     => '0',
			)
		);

		$this->add_control(
			'results_only',
			array(
				'label'        => __( 'Show results only', 'yop-poll' ),
				'description'  => __( 'Shows the tally instead of the ballot. Nobody can vote from here.', 'yop-poll' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Every poll that can be placed, plus the three that resolve at render time.
	 *
	 * The magic ids come first because they are the ones with no name to search
	 * for — SELECT2 has no `optgroup`, so "first in a flat list" is the whole
	 * of the separation available. They are the same three the legacy widget
	 * offers (`class-widget.php`) and the shortcode resolves
	 * (`Shortcode::resolve_magic_id()`).
	 *
	 * Every published poll, with no cap. A search box over a truncated list is
	 * worse than no search box: it answers "no such poll" for a poll that
	 * exists. `published_names()` is narrow enough — two columns, no
	 * `meta_data` — that loading all of them is cheaper than the capped
	 * `all()` this used to call.
	 *
	 * PHP casts a numeric string key to an int, so `'0'` and `'42'` arrive as
	 * ints while `'-1'` stays a string. Elementor does not care; the annotation
	 * has to say so or PHPStan does.
	 *
	 * @return array<int|string, string> Value keyed to label.
	 */
	private static function poll_options() {
		$options = array(
			'0'  => __( 'Choose a poll', 'yop-poll' ),
			'-1' => __( 'The poll running now', 'yop-poll' ),
			'-2' => __( 'The most recent poll', 'yop-poll' ),
			'-3' => __( 'A random poll', 'yop-poll' ),
		);

		foreach ( ( new Model_Poll() )->published_names() as $id => $name ) {
			$options[ (string) $id ] = sprintf(
				/* translators: 1: poll name, 2: poll id. */
				__( '%1$s (ID %2$d)', 'yop-poll' ),
				$name,
				$id
			);
		}

		return $options;
	}

	/**
	 * The shortcode this widget's settings come to.
	 *
	 * Separate from `render()` so it can be tested without Elementor: building
	 * the string is the part with a decision in it, and running it is
	 * `do_shortcode`'s job.
	 *
	 * @param array $settings The widget's stored settings.
	 * @return string The shortcode, or '' when no poll is chosen.
	 */
	public static function shortcode_for( array $settings ) {
		$poll_id = (int) ( $settings['poll_id'] ?? 0 );

		if ( 0 === $poll_id ) {
			return '';
		}

		if ( 'yes' === ( $settings['results_only'] ?? '' ) ) {
			return sprintf( '[yop_poll id="%d" results_only="yes"]', $poll_id );
		}

		return sprintf( '[yop_poll id="%d"]', $poll_id );
	}

	/**
	 * What to say when there is no poll to draw.
	 *
	 * A widget that prints nothing is drawn by Elementor as a grey box with the
	 * widget's icon in it — `Widget_Base::render_content()` returns before even
	 * the container div when the render is empty, so the element has no height
	 * and the editor paints its empty-widget placeholder over it.
	 *
	 * That box is the same one shown while a render is still in flight, so from
	 * the canvas "still loading", "you have not chosen a poll" and "that poll is
	 * gone" are one indistinguishable grey rectangle. This is what tells them
	 * apart.
	 *
	 * Pure and static so it can be tested without Elementor, the same reason
	 * `shortcode_for()` is: `render()` cannot be driven without Elementor's own
	 * machinery, but the decision inside it can.
	 *
	 * @param array  $settings The widget's stored settings.
	 * @param string $rendered What the shortcode produced.
	 * @return string The message, or '' when the poll drew fine.
	 */
	public static function placeholder_for( array $settings, $rendered ) {
		if ( '' !== trim( (string) $rendered ) ) {
			return '';
		}

		if ( 0 === (int) ( $settings['poll_id'] ?? 0 ) ) {
			return __( 'Choose a poll to display.', 'yop-poll' );
		}

		// A poll was chosen and the renderer still gave nothing back, which
		// `Shortcode::render_poll()` does for a poll that has been deleted or
		// moved back to draft. Same wording as the block's `MissingPoll`.
		return __( 'Poll not found or no longer available.', 'yop-poll' );
	}

	/**
	 * Whether this render is for someone building the page.
	 *
	 * Two questions, because a widget in the canvas is rendered two different
	 * ways and only one of them is "edit mode":
	 *
	 * - **Dropped or edited**: Elementor re-renders over ajax, and
	 *   `Widgets_Manager::ajax_render_widget()` calls `set_edit_mode( true )`
	 *   around it. `is_edit_mode()` is the one that answers.
	 * - **Editor reloaded**: the canvas is a preview iframe, which is an
	 *   ordinary front-end request. `is_edit_mode()` is false there and
	 *   `is_preview_mode()` is true.
	 *
	 * Asking only the first would leave the placeholder missing every time the
	 * editor was reloaded — working when tested by dragging, broken when tested
	 * by refreshing. Elementor's own `text-editor.php` asks the same pair for
	 * the same reason.
	 *
	 * Guarded the whole way down: if Elementor moves either API this returns
	 * false and the widget renders nothing, which is what it did before this
	 * existed. A placeholder is not worth a fatal on someone's page.
	 *
	 * @return bool
	 */
	private static function in_editor() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$plugin = \Elementor\Plugin::$instance;

		if ( ! $plugin ) {
			return false;
		}

		if (
			isset( $plugin->editor )
			&& method_exists( $plugin->editor, 'is_edit_mode' )
			&& $plugin->editor->is_edit_mode()
		) {
			return true;
		}

		return isset( $plugin->preview )
			&& method_exists( $plugin->preview, 'is_preview_mode' )
			&& $plugin->preview->is_preview_mode();
	}

	/**
	 * Draw the poll.
	 *
	 * Through the shortcode, exactly as the legacy widget does
	 * (`class-widget.php`), so the canvas, the live page and every other
	 * placement share one renderer and cannot drift apart.
	 *
	 * @return void
	 */
	protected function render() {
		$settings  = (array) $this->get_settings_for_display();
		$shortcode = self::shortcode_for( $settings );
		$html      = '' === $shortcode ? '' : do_shortcode( $shortcode );

		if ( '' !== trim( (string) $html ) ) {
			// `do_shortcode` output is already escaped by the renderer that
			// built it; escaping again would print the markup as text.
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		/*
		 * A visitor sees nothing at all — the same blank they saw before this
		 * existed. "Choose a poll" is an instruction to whoever is building the
		 * page, and printing it on a live site would turn a quiet gap into a
		 * public one.
		 */
		if ( ! self::in_editor() ) {
			return;
		}

		/*
		 * Styled inline rather than from a stylesheet, which is the one place
		 * this plugin does that on purpose. The element exists only inside the
		 * editor, so a rule for it in `classic/style.css` would be bytes every
		 * visitor downloads and no visitor can ever use — and that stylesheet
		 * is on the critical path for the poll itself.
		 *
		 * The dashed border is what makes it read as a placeholder rather than
		 * as stray content the theme happens to be styling. It also gives the
		 * element real height, which is what stops Elementor painting its empty
		 * -widget box over the top: that is triggered by `outerHeight()` being
		 * zero, so text alone would be undone by a theme with odd line
		 * heights.
		 */
		printf(
			'<div class="yop-poll-elementor-placeholder" style="%s">%s</div>',
			'padding:18px;border:1px dashed #c3c4c7;border-radius:3px;'
				. 'background:#fff;color:#50575e;font-size:13px;'
				. 'line-height:1.5;text-align:center',
			esc_html( self::placeholder_for( $settings, $html ) )
		);
	}
}
