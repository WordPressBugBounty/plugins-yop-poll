<?php
/**
 * Classic poll template.
 *
 * Variables available: $poll, $elements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="yop-poll" data-template="classic" data-poll-id="<?php echo esc_attr( $poll['id'] ); ?>">
	<?php foreach ( $elements as $element ) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- foreach loop variable, not a global. ?>
		<div class="yop-poll-element" data-element-type="<?php echo esc_attr( $element['etype'] ); ?>">
			<?php if ( ! empty( $element['etext'] ) ) : ?>
				<div class="yop-poll-question"><?php echo wp_kses_post( $element['etext'] ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $element['subelements'] ) ) : ?>
				<div class="yop-poll-answers">
					<?php foreach ( $element['subelements'] as $sub ) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- foreach loop variable, not a global. ?>
						<label class="yop-poll-answer">
							<input type="radio"
								name="yop-poll-answer-<?php echo esc_attr( $element['id'] ); ?>"
								value="<?php echo esc_attr( $sub['id'] ); ?>"
								data-element-id="<?php echo esc_attr( $element['id'] ); ?>"
							/>
							<span class="yop-poll-answer-text"><?php echo wp_kses_post( $sub['stext'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<div class="yop-poll-footer">
		<button type="button" class="yop-poll-vote-button">
			<?php esc_html_e( 'Vote Now', 'yop-poll' ); ?>
		</button>
	</div>
</div>
