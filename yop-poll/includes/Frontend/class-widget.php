<?php
namespace YopPoll\Frontend;

use YopPoll\Models\Model_Poll;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Widget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'yop_poll_widget',
			__( 'YOP Poll', 'yop-poll' ),
			array(
				'description' => esc_html__( 'Add a poll to your site', 'yop-poll' ),
			)
		);
	}

	public static function init() {
		add_action( 'widgets_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_widget( __CLASS__ );
	}

	public function form( $instance ) {
		$title       = isset( $instance['title'] )       ? (string) $instance['title']       : '';
		$poll_id     = isset( $instance['poll_id'] )     ? (int) $instance['poll_id']        : 0;
		$tracking_id = isset( $instance['tracking_id'] ) ? (string) $instance['tracking_id'] : '';

		$model = new Model_Poll();
		$polls = $model->all( array(
			'where'    => array( 'status' => 'published' ),
			'orderby'  => 'id',
			'order'    => 'DESC',
			'per_page' => 1000,
		) );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'yop-poll' ); ?>
			</label>
			<input class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'poll_id' ) ); ?>">
				<?php esc_html_e( 'Poll to display', 'yop-poll' ); ?>
			</label>
			<select class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'poll_id' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'poll_id' ) ); ?>">
				<option value="0"  <?php selected( 0,  $poll_id ); ?>><?php esc_html_e( "Don't Display Poll (Disable)", 'yop-poll' ); ?></option>
				<option value="-1" <?php selected( -1, $poll_id ); ?>><?php esc_html_e( 'Display Current Active Poll',   'yop-poll' ); ?></option>
				<option value="-2" <?php selected( -2, $poll_id ); ?>><?php esc_html_e( 'Display Latest Poll',           'yop-poll' ); ?></option>
				<option value="-3" <?php selected( -3, $poll_id ); ?>><?php esc_html_e( 'Display Random Poll',           'yop-poll' ); ?></option>
				<?php
				if ( is_array( $polls ) ) {
					foreach ( $polls as $poll ) {
						$pid   = (int) ( $poll['id']   ?? 0 );
						$pname = (string) ( $poll['name'] ?? '' );
						?>
						<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $pid, $poll_id ); ?>>
							<?php echo esc_html( $pname ); ?>
						</option>
						<?php
					}
				}
				?>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'tracking_id' ) ); ?>">
				<?php esc_html_e( 'Tracking Id:', 'yop-poll' ); ?>
			</label>
			<input class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'tracking_id' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'tracking_id' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $tracking_id ); ?>" />
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		return array(
			'title'       => isset( $new_instance['title'] )       ? sanitize_text_field( wp_unslash( $new_instance['title'] ) )       : '',
			'poll_id'     => isset( $new_instance['poll_id'] )     ? (int) $new_instance['poll_id']                                    : 0,
			'tracking_id' => isset( $new_instance['tracking_id'] ) ? sanitize_text_field( wp_unslash( $new_instance['tracking_id'] ) ) : '',
		);
	}

	public function widget( $args, $instance ) {
		$poll_id = isset( $instance['poll_id'] ) ? (int) $instance['poll_id'] : 0;
		if ( 0 === $poll_id ) {
			return;
		}

		$title       = isset( $instance['title'] )       ? (string) $instance['title']       : '';
		$tracking_id = isset( $instance['tracking_id'] ) ? (string) $instance['tracking_id'] : '';

		$shortcode = sprintf(
			'[yop_poll id="%d" tracking_id="%s"]',
			$poll_id,
			esc_attr( $tracking_id )
		);
		$poll_output = do_shortcode( $shortcode );

		if ( '' === trim( (string) $poll_output ) ) {
			return;
		}

		echo wp_kses_post( $args['before_widget'] ?? '' );
		if ( '' !== $title ) {
			$title = apply_filters( 'widget_title', $title, $instance, $this->id_base );
			echo wp_kses_post( $args['before_title'] ?? '' );
			echo esc_html( $title );
			echo wp_kses_post( $args['after_title'] ?? '' );
		}
		echo $poll_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is the v7 shortcode HTML (mount div + JSON init), already sanitized at construction.
		echo wp_kses_post( $args['after_widget'] ?? '' );
	}
}
